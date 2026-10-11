<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\ProductDetail;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Bulk import of sales from spreadsheet rows (one row per invoice line). Rows sharing an invoice reference make one sale; every
 * sale goes through SaleDocumentBuilder, so the credit limit, stock check, tax and posting are the sale form's own. Customers are
 * found by code, mobile or business name and products by variation SKU / barcode, inside the company only. Like the contact import
 * it is all or nothing: a run executes for real inside a transaction that is rolled back for a check (`dry_run`) and whenever a
 * row failed. An invoice reference already imported is skipped, not made twice.
 */
class SaleImporter
{
    public const MAX_ROWS = 2000;

    public function __construct(private readonly SaleDocumentBuilder $builder) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{ok: bool, dry_run: bool, summary: array{rows: int, invoices: int, created: int, duplicates: int, failed: int}, invoices: list<array<string, mixed>>}
     */
    public function run(array $rows, int $companyId, int $branchId, string $status, bool $dryRun): array
    {
        $groups = [];

        foreach ($rows as $index => $row) {
            $row = is_array($row) ? $row : [];
            $key = trim((string) ($row['invoice_ref'] ?? '')) ?: 'ROW-'.($index + 1);
            $groups[$key]['rows'][] = ['number' => $index + 1] + $row;
        }

        $results = [];

        DB::beginTransaction();

        try {
            foreach ($groups as $reference => $group) {
                $results[] = $this->importInvoice((string) $reference, $group['rows'], $companyId, $branchId, $status);
            }

            $failed = count(array_filter($results, fn (array $result): bool => $result['status'] === 'error'));

            if ($dryRun || $failed > 0) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $count = fn (string $state): int => count(array_filter($results, fn (array $result): bool => $result['status'] === $state));

        return [
            'ok' => $failed === 0,
            'dry_run' => $dryRun,
            'summary' => ['rows' => count($rows), 'invoices' => count($results), 'created' => $count('created'), 'duplicates' => $count('duplicate'), 'failed' => $failed],
            'invoices' => $results,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function importInvoice(string $reference, array $rows, int $companyId, int $branchId, string $status): array
    {
        $numbers = array_column($rows, 'number');
        $result = ['invoice_ref' => $reference, 'rows' => $numbers, 'status' => 'error', 'errors' => [], 'invoice_no' => null, 'total' => null];
        $marker = 'Import ref '.$reference;

        if (! str_starts_with($reference, 'ROW-') && Transaction::query()->sells()->where('company_id', $companyId)->where('additional_note', $marker)->exists()) {
            $result['status'] = 'duplicate';

            return $result;
        }

        $first = $rows[0];
        $customer = $this->customer($companyId, $first);

        if ($customer === null) {
            $result['errors'][] = 'Row '.$first['number'].': customer not found (use the code, mobile or business name of a customer of this company).';

            return $result;
        }

        $date = trim((string) ($first['date'] ?? ''));

        if ($date === '' || strtotime($date) === false) {
            $result['errors'][] = 'Row '.$first['number'].': the date is missing or not a date.';

            return $result;
        }

        $productRows = [];

        foreach ($rows as $row) {
            $match = ProductCatalog::resolveCode($companyId, trim((string) ($row['sku'] ?? '')));

            if ($match === null) {
                $result['errors'][] = 'Row '.$row['number'].': product not found (use the variation SKU or barcode).';

                continue;
            }

            if ((float) ($row['quantity'] ?? 0) <= 0) {
                $result['errors'][] = 'Row '.$row['number'].': quantity must be above zero.';

                continue;
            }

            $variation = $match['variation_id'] ?? ProductDetail::query()->where('product_id', $match['product_id'])->orderBy('id')->value('id');
            $productRows[] = [
                'product_id' => $match['product_id'], 'variation_id' => $variation, 'quantity' => (float) $row['quantity'],
                'unit_price' => isset($row['unit_price']) && $row['unit_price'] !== '' ? (float) $row['unit_price'] : null,
                'discount' => (float) ($row['discount'] ?? 0),
            ];
        }

        if ($result['errors'] !== []) {
            return $result;
        }

        try {
            $sale = DB::transaction(function () use ($companyId, $branchId, $customer, $date, $productRows, $marker, $status): Transaction {
                $built = $this->builder->lines($companyId, $branchId, (int) $customer->id, $productRows);

                return $this->builder->create([
                    'company_id' => $companyId, 'branch_id' => $branchId, 'contact_id' => $customer->id,
                    'transaction_date' => date('Y-m-d', strtotime($date)), 'note' => $marker,
                ], $built['lines'], $built['total'], $status);
            });
        } catch (ValidationException $e) {
            $result['errors'] = array_map(fn (string $message): string => 'Invoice '.$reference.': '.$message, collect($e->errors())->flatten()->all());

            return $result;
        } catch (Throwable $e) {
            $result['errors'][] = 'Invoice '.$reference.': '.$e->getMessage();

            return $result;
        }

        return ['status' => 'created', 'errors' => [], 'invoice_no' => $sale->invoice_no, 'total' => round((float) $sale->final_amount, 2)] + $result;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function customer(int $companyId, array $row): ?Contact
    {
        $query = Contact::query()->where('company_id', $companyId)->whereIn('user_type', ['customer', 'both']);

        foreach (['customer_code' => 'code', 'customer_mobile' => 'mobile', 'customer' => 'business_name'] as $field => $column) {
            $value = trim((string) ($row[$field] ?? ''));

            if ($value !== '') {
                $found = (clone $query)->where($column, $value)->first();

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
