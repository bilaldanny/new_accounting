<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\ItemType;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Warranty;
use App\Services\ProductCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk update of product fields other than prices (those have the Bulk Price Update wizard), the complete product
 * export, and the scan lookup the sale and POS screens use.
 */
class ProductBulkController extends Controller
{
    public const MAX_IDS = 1000;

    private const LOOKUPS = ['category_id' => Category::class, 'subcategory_id' => Category::class, 'brand_id' => Brand::class, 'itemtype_id' => ItemType::class, 'warranty_id' => Warranty::class];

    public function update(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/product/:id/edit');

        $data = $request->validate([
            'ids' => 'required|array|min:1|max:'.self::MAX_IDS,
            'ids.*' => 'integer',
            'fields' => 'required|array|min:1',
            'fields.category_id' => 'nullable|integer',
            'fields.subcategory_id' => 'nullable|integer',
            'fields.brand_id' => 'nullable|integer',
            'fields.itemtype_id' => 'nullable|integer',
            'fields.warranty_id' => 'nullable|integer',
            'fields.active' => 'nullable|boolean',
            'fields.alert_qty' => 'nullable|numeric|min:0',
            'fields.reorder_level' => 'nullable|numeric|min:0',
            'fields.reorder_qty' => 'nullable|numeric|min:0',
            'fields.min_stock' => 'nullable|numeric|min:0',
            'fields.max_stock' => 'nullable|numeric|min:0',
            'fields.lead_time_days' => 'nullable|integer|min:0|max:3650',
            'fields.ownership' => 'nullable|in:own,consignment',
            'fields.consignment_supplier_id' => 'nullable|integer',
            'fields.tags_add' => 'nullable|array',
            'fields.tags_add.*' => 'string|max:100',
            'fields.tags_remove' => 'nullable|array',
            'fields.tags_remove.*' => 'string|max:100',
            'fields.custom_fields' => 'nullable|array',
        ]);

        $fields = $data['fields'];
        $products = Product::query()->visibleToCurrentUser()->whereIn('id', $data['ids'])->get();

        if ($products->isEmpty()) {
            throw ValidationException::withMessages(['ids' => ['None of the selected products were found.']]);
        }

        $companies = $products->pluck('company_id')->unique();

        foreach (self::LOOKUPS as $field => $model) {
            if (! array_key_exists($field, $fields) || $fields[$field] === null) {
                continue;
            }

            $found = $model::query()->whereIn('company_id', $companies)->whereKey($fields[$field])->count();

            if ($found !== $companies->count()) {
                throw ValidationException::withMessages(["fields.{$field}" => ['That value does not belong to the company of every selected product.']]);
            }
        }

        $touched = DB::transaction(function () use ($products, $fields): int {
            $touched = 0;

            foreach ($products as $product) {
                foreach (array_keys(self::LOOKUPS) as $field) {
                    if (array_key_exists($field, $fields) && $fields[$field] !== null) {
                        $product->{$field} = (int) $fields[$field];
                    }
                }

                if (array_key_exists('active', $fields) && $fields['active'] !== null) {
                    $product->active = $fields['active'];
                }

                if (array_key_exists('alert_qty', $fields) && $fields['alert_qty'] !== null) {
                    $product->alert_qty = (int) $fields['alert_qty'];
                }

                $product->save();

                $catalog = [];

                foreach (['reorder_level', 'reorder_qty', 'min_stock', 'max_stock', 'lead_time_days', 'ownership', 'consignment_supplier_id'] as $field) {
                    if (array_key_exists($field, $fields) && $fields[$field] !== null) {
                        $catalog[$field] = $fields[$field];
                    }
                }

                if (! empty($fields['tags_add']) || ! empty($fields['tags_remove'])) {
                    $current = (array) ($product->tags ?? []);
                    $remove = array_map('mb_strtolower', (array) ($fields['tags_remove'] ?? []));
                    $kept = array_values(array_filter($current, fn (string $tag): bool => ! in_array(mb_strtolower($tag), $remove, true)));
                    $catalog['tags'] = array_merge($kept, (array) ($fields['tags_add'] ?? []));
                }

                if (! empty($fields['custom_fields'])) {
                    $catalog['custom_fields'] = array_merge((array) ($product->custom_fields ?? []), (array) $fields['custom_fields']);
                }

                if ($catalog !== []) {
                    app(ProductCatalog::class)->apply($product, (object) $catalog);
                }

                $touched++;
            }

            return $touched;
        });

        return response()->json(['message' => "{$touched} product(s) updated", 'updated' => $touched]);
    }

    /**
     * Every product field, one row per variation, as a CSV the importer understands.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorizeMenuPermission('/product/export');

        $user = Auth::user();
        $companyId = $user?->hasRole('superadmin') ? $request->integer('company_id') : (int) $user?->company_id;

        $query = DB::table('products as p')
            ->join('product_details as d', 'd.product_id', '=', 'p.id')
            ->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('categories as sc', 'sc.id', '=', 'p.subcategory_id')
            ->leftJoin('item_types as it', 'it.id', '=', 'p.itemtype_id')
            ->leftJoin('warranties as w', 'w.id', '=', 'p.warranty_id')
            ->whereNull('p.deleted_at')
            ->when($companyId, fn ($q) => $q->where('p.company_id', $companyId), fn ($q) => $q->whereRaw('1 = 0'))
            ->orderBy('p.id')
            ->orderBy('d.id')
            ->select([
                'p.id', 'p.name', 'p.sku as product_sku', 'p.type', 'u.name as unit', 'b.name as brand', 'c.name as category',
                'sc.name as subcategory', 'it.name as item_type', 'w.name as warranty', 'p.alert_qty', 'p.weight', 'p.product_desc as description',
                'p.active', 'd.id as variation_id', 'd.variation_name', 'd.sku as variation_sku', 'd.default_purchase_price',
                'd.profit_percent', 'd.default_sell_price', 'd.min_sell_price', 'd.max_sell_price', 'd.smallquantity', 'd.largequantity',
                'p.tracking_type',
            ]);

        $catalog = ProductCatalog::supported();

        if ($catalog) {
            $query->addSelect(['p.tags', 'p.custom_fields', 'p.reorder_level', 'p.reorder_qty', 'p.min_stock', 'p.max_stock', 'p.lead_time_days', 'p.ownership', 'p.consignment_supplier_id']);
        }

        $barcodes = $catalog && $companyId
            ? ProductBarcode::query()->where('company_id', $companyId)->orderBy('id')->get(['product_id', 'barcode'])->groupBy('product_id')->map(fn ($rows) => $rows->pluck('barcode')->implode('|'))
            : collect();

        return response()->streamDownload(function () use ($query, $catalog, $barcodes): void {
            $out = fopen('php://output', 'w');
            $header = null;

            foreach ($query->cursor() as $row) {
                $line = (array) $row;

                if ($catalog) {
                    $line['tags'] = implode(',', (array) json_decode((string) ($line['tags'] ?? '[]'), true));
                    $line['custom_fields'] = ($line['custom_fields'] ?? null) ? (string) $line['custom_fields'] : '';
                    $line['barcodes'] = (string) ($barcodes[$line['id']] ?? '');
                }

                if ($header === null) {
                    $header = array_keys($line);
                    fputcsv($out, $header);
                }

                fputcsv($out, array_map(fn ($value) => $this->csvSafe($value), $line));
            }

            if ($header === null) {
                fputcsv($out, ['id', 'name', 'product_sku']);
            }

            fclose($out);
        }, 'products-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * What a scanned or typed code stands for: a variation SKU, a product SKU, or an extra barcode.
     */
    public function lookup(Request $request): JsonResponse
    {
        abort_unless(hasAnyMenuPermission('/product', '/sell/add', '/sell/pos', '/sell', '/purchase/add'), 403, 'You do not have permission to perform this action.');

        $request->validate(['code' => 'required|string|max:100', 'company_id' => 'nullable|integer']);

        $user = Auth::user();
        $companyId = $user?->hasRole('superadmin') ? $request->integer('company_id') : (int) $user?->company_id;
        $match = $companyId ? ProductCatalog::resolveCode($companyId, (string) $request->input('code')) : null;

        if ($match === null) {
            return response()->json(['message' => 'No product has this code.'], 404);
        }

        return response()->json($match);
    }

    /**
     * A cell that starts with = + - or @ would run as a formula when the file is opened in a spreadsheet.
     */
    private function csvSafe(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) ? "'".$value : $value;
    }
}
