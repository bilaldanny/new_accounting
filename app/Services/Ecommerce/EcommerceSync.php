<?php

namespace App\Services\Ecommerce;

use App\Models\Contact;
use App\Models\EcommerceConnection;
use App\Models\EcommerceOrder;
use App\Models\ProductDetail;
use App\Services\ProductCatalog;
use App\Services\SaleDocumentBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pulls a connection's orders and turns each new one into a draft sale for the connection's web customer (found again by the
 * order's SKU lines in the company's own catalog). An order is stored once per external id; one that cannot be turned into a
 * sale (an unknown SKU, a customer on hold) is kept as `failed` with the reason and can be retried after the catalog is fixed.
 * Sales are drafts: nothing is posted or taken from stock until staff finish them on the sale screen.
 */
class EcommerceSync
{
    public function __construct(private readonly SaleDocumentBuilder $builder) {}

    public function adapterFor(EcommerceConnection $connection): EcommerceAdapter
    {
        return match ($connection->platform) {
            'shopify' => new ShopifyAdapter,
            'woocommerce' => new WooCommerceAdapter,
            default => new MockEcommerceAdapter,
        };
    }

    /**
     * @return array{fetched: int, imported: int, failed: int, skipped: int}
     *
     * @throws EcommerceNotConfigured
     */
    public function sync(EcommerceConnection $connection, ?EcommerceAdapter $adapter = null): array
    {
        $adapter ??= $this->adapterFor($connection);
        $orders = $adapter->fetchOrders($connection, $connection->last_synced_at);
        $result = ['fetched' => count($orders), 'imported' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($orders as $order) {
            $record = EcommerceOrder::query()->firstOrNew(['ecommerce_connection_id' => $connection->id, 'external_id' => (string) $order['external_id']]);

            if ($record->exists && $record->status === 'imported') {
                $result['skipped']++;

                continue;
            }

            $record->fill(['company_id' => $connection->company_id, 'payload' => $order, 'status' => $record->status ?: 'new'])->save();
            $this->import($connection, $record) ? $result['imported']++ : $result['failed']++;
        }

        $connection->update(['last_synced_at' => now()]);

        return $result;
    }

    public function import(EcommerceConnection $connection, EcommerceOrder $record): bool
    {
        try {
            $sale = DB::transaction(function () use ($connection, $record) {
                $order = (array) $record->payload;
                $contact = Contact::query()->where('company_id', $connection->company_id)->find($connection->default_contact_id);

                if ($contact === null) {
                    throw new \RuntimeException('Choose the customer that web orders are recorded under.');
                }

                $rows = [];

                foreach ((array) ($order['lines'] ?? []) as $line) {
                    $match = ProductCatalog::resolveCode((int) $connection->company_id, trim((string) ($line['sku'] ?? '')));

                    if ($match === null) {
                        throw new \RuntimeException('No product has the SKU '.($line['sku'] ?? '(empty)').'.');
                    }

                    $rows[] = [
                        'product_id' => $match['product_id'],
                        'variation_id' => $match['variation_id'] ?? ProductDetail::query()->where('product_id', $match['product_id'])->orderBy('id')->value('id'),
                        'quantity' => (float) $line['quantity'], 'unit_price' => $line['unit_price'] ?? null,
                    ];
                }

                $built = $this->builder->lines((int) $connection->company_id, $connection->branch_id === null ? null : (int) $connection->branch_id, (int) $contact->id, $rows);

                return $this->builder->create([
                    'company_id' => $connection->company_id,
                    'branch_id' => $connection->branch_id ?? $contact->branch_id,
                    'contact_id' => $contact->id,
                    'transaction_date' => $order['placed_at'] ?? now()->toDateString(),
                    'note' => 'Web order '.$connection->name.' #'.$record->external_id,
                    'shipping_charges' => (float) ($order['shipping'] ?? 0),
                ], $built['lines'], $built['total'], 'draft');
            });
        } catch (Throwable $e) {
            $record->update(['status' => 'failed', 'error' => mb_substr($e instanceof ValidationException ? (string) collect($e->errors())->flatten()->first() : $e->getMessage(), 0, 500)]);

            return false;
        }

        $record->update(['status' => 'imported', 'transaction_id' => $sale->id, 'error' => null, 'imported_at' => now()]);

        return true;
    }
}
