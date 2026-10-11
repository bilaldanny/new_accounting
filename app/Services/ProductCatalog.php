<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductAttributeDefinition;
use App\Models\ProductBarcode;
use App\Models\ProductDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * The catalog fields a product carries beyond its name and prices: tags, custom fields, reorder settings, ownership
 * (own or consignment) and extra barcodes. Every one is optional and only touched when the request names it, so a
 * client that does not know them (the importer, the public API, an old form) leaves them exactly as they are.
 */
class ProductCatalog
{
    public const MAX_TAGS = 20;

    private static ?bool $supported = null;

    public static function supported(): bool
    {
        return self::$supported ??= Schema::hasColumn('products', 'tags') && Schema::hasTable('product_barcodes');
    }

    public static function forgetSchemaMemo(): void
    {
        self::$supported = null;
    }

    /**
     * Applies the catalog fields the request names to a saved product.
     *
     * @throws ValidationException
     */
    public function apply(Product $product, object $request): void
    {
        if (! self::supported()) {
            return;
        }

        $changed = false;

        if ($this->sent($request, 'tags')) {
            $product->tags = $this->cleanTags($request->tags ?? []);
            $changed = true;
        }

        if ($this->sent($request, 'custom_fields')) {
            $product->custom_fields = $this->cleanCustomFields((int) $product->company_id, $request->custom_fields ?? []);
            $changed = true;
        }

        foreach (['reorder_level', 'reorder_qty', 'min_stock', 'max_stock'] as $field) {
            if ($this->sent($request, $field)) {
                $product->{$field} = $this->nonNegative($request->{$field} ?? null, $field);
                $changed = true;
            }
        }

        if ($this->sent($request, 'lead_time_days')) {
            $value = $request->lead_time_days ?? null;
            $product->lead_time_days = $value === null || $value === '' ? null : (int) $this->nonNegative($value, 'lead_time_days');
            $changed = true;
        }

        if ($product->min_stock !== null && $product->max_stock !== null && (float) $product->min_stock > (float) $product->max_stock) {
            throw ValidationException::withMessages(['min_stock' => ['Minimum stock cannot be above maximum stock.']]);
        }

        if ($this->sent($request, 'ownership') || $this->sent($request, 'consignment_supplier_id')) {
            $this->applyOwnership($product, $request);
            $changed = true;
        }

        if ($changed) {
            $product->save();
        }

        if ($this->sent($request, 'barcodes')) {
            $this->syncBarcodes($product, (array) ($request->barcodes ?? []));
        }
    }

    /**
     * The variation (and so the product) a scanned or typed code stands for in a company: a variation SKU, a product
     * SKU, or one of a product extra barcodes. Exact match only.
     *
     * @return array{product_id: int, variation_id: int|null}|null
     */
    public static function resolveCode(int $companyId, string $code): ?array
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        $detail = ProductDetail::query()
            ->join('products', 'products.id', '=', 'product_details.product_id')
            ->where('products.company_id', $companyId)
            ->whereNull('products.deleted_at')
            ->where('product_details.sku', $code)
            ->first(['product_details.id', 'product_details.product_id']);

        if ($detail !== null) {
            return ['product_id' => (int) $detail->product_id, 'variation_id' => (int) $detail->id];
        }

        if (self::supported()) {
            $extra = ProductBarcode::query()->where('company_id', $companyId)->where('barcode', $code)->first();

            if ($extra !== null) {
                return ['product_id' => (int) $extra->product_id, 'variation_id' => $extra->variation_id === null ? null : (int) $extra->variation_id];
            }
        }

        $product = Product::query()->where('company_id', $companyId)->where('sku', $code)->first(['id']);

        return $product === null ? null : ['product_id' => (int) $product->id, 'variation_id' => null];
    }

    /**
     * @param  array<int, mixed>  $rows  strings, or `{barcode, variation_id?, label?}`
     *
     * @throws ValidationException
     */
    private function syncBarcodes(Product $product, array $rows): void
    {
        $companyId = (int) $product->company_id;
        $wanted = [];

        foreach ($rows as $index => $row) {
            $code = trim((string) (is_array($row) ? ($row['barcode'] ?? '') : $row));

            if ($code === '') {
                continue;
            }

            if (mb_strlen($code) > 100) {
                throw ValidationException::withMessages(["barcodes.{$index}" => ['A barcode can have at most 100 characters.']]);
            }

            $variationId = is_array($row) && ! empty($row['variation_id']) ? (int) $row['variation_id'] : null;

            if ($variationId !== null && ! ProductDetail::query()->where('product_id', $product->id)->whereKey($variationId)->exists()) {
                throw ValidationException::withMessages(["barcodes.{$index}" => ['That variation does not belong to this product.']]);
            }

            if (isset($wanted[$code])) {
                throw ValidationException::withMessages(["barcodes.{$index}" => ["The barcode {$code} is listed twice."]]);
            }

            $wanted[$code] = ['variation_id' => $variationId, 'label' => is_array($row) ? ($row['label'] ?? null) : null];
        }

        foreach (array_keys($wanted) as $code) {
            $code = (string) $code;
            $taken = ProductBarcode::query()->where('company_id', $companyId)->where('barcode', $code)->where('product_id', '!=', $product->id)->exists()
                || ProductDetail::query()->join('products', 'products.id', '=', 'product_details.product_id')
                    ->where('products.company_id', $companyId)->where('product_details.sku', $code)->where('product_details.product_id', '!=', $product->id)->exists();

            if ($taken) {
                throw ValidationException::withMessages(['barcodes' => ["The barcode {$code} already belongs to another product."]]);
            }
        }

        DB::transaction(function () use ($product, $companyId, $wanted): void {
            ProductBarcode::query()->where('product_id', $product->id)->whereNotIn('barcode', array_map('strval', array_keys($wanted)))->delete();

            foreach ($wanted as $code => $attributes) {
                ProductBarcode::query()->updateOrCreate(
                    ['company_id' => $companyId, 'barcode' => (string) $code],
                    ['product_id' => $product->id, 'variation_id' => $attributes['variation_id'], 'label' => $attributes['label']],
                );
            }
        });
    }

    /**
     * @return list<string>
     *
     * @throws ValidationException
     */
    private function cleanTags(mixed $tags): array
    {
        if (is_string($tags)) {
            $tags = explode(',', $tags);
        }

        if (! is_array($tags)) {
            throw ValidationException::withMessages(['tags' => ['Tags must be a list.']]);
        }

        $clean = [];

        foreach ($tags as $tag) {
            $tag = trim((string) $tag);

            if ($tag === '') {
                continue;
            }

            if (mb_strlen($tag) > 100) {
                throw ValidationException::withMessages(['tags' => ['A tag can have at most 100 characters.']]);
            }

            if (! in_array(mb_strtolower($tag), array_map('mb_strtolower', $clean), true)) {
                $clean[] = $tag;
            }
        }

        if (count($clean) > self::MAX_TAGS) {
            throw ValidationException::withMessages(['tags' => ['A product can have at most '.self::MAX_TAGS.' tags.']]);
        }

        return $clean;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function cleanCustomFields(int $companyId, mixed $values): array
    {
        if ($values === null || $values === '') {
            $values = [];
        }

        if (! is_array($values)) {
            throw ValidationException::withMessages(['custom_fields' => ['Custom fields must be an object.']]);
        }

        $definitions = ProductAttributeDefinition::query()->where('company_id', $companyId)->where('is_active', true)->get()->keyBy('field_key');
        $clean = [];
        $errors = [];

        foreach ($values as $key => $value) {
            $definition = $definitions->get((string) $key);

            if ($definition === null) {
                $errors["custom_fields.{$key}"] = ["There is no custom field called {$key}."];

                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $normalized = $this->normalizeValue($definition, $value);

            if ($normalized === null) {
                $errors["custom_fields.{$key}"] = ["{$definition->name} is not a valid {$definition->type} value."];

                continue;
            }

            $clean[(string) $key] = $normalized;
        }

        foreach ($definitions as $key => $definition) {
            if ($definition->is_required && ! array_key_exists((string) $key, $clean)) {
                $errors["custom_fields.{$key}"] = ["{$definition->name} is required."];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    private function normalizeValue(ProductAttributeDefinition $definition, mixed $value): string|int|float|bool|null
    {
        return match ($definition->type) {
            'number' => is_numeric($value) ? $value + 0 : null,
            'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false ? $value : null,
            'checkbox' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'select' => is_string($value) && in_array($value, (array) $definition->options, true) ? $value : null,
            default => is_scalar($value) && mb_strlen((string) $value) <= 500 ? (string) $value : null,
        };
    }

    private function nonNegative(mixed $value, string $field): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (float) $value < 0) {
            throw ValidationException::withMessages([$field => ['Enter a number that is zero or more.']]);
        }

        return round((float) $value, 4);
    }

    /**
     * @throws ValidationException
     */
    private function applyOwnership(Product $product, object $request): void
    {
        $ownership = $this->sent($request, 'ownership') ? (string) ($request->ownership ?? 'own') : (string) ($product->ownership ?: 'own');

        if (! in_array($ownership, ['own', 'consignment'], true)) {
            throw ValidationException::withMessages(['ownership' => ['Ownership is either own or consignment.']]);
        }

        if ($ownership === 'own') {
            $product->ownership = 'own';
            $product->consignment_supplier_id = null;

            return;
        }

        $supplierId = $this->sent($request, 'consignment_supplier_id') ? (int) ($request->consignment_supplier_id ?? 0) : (int) $product->consignment_supplier_id;

        $isSupplier = $supplierId > 0 && Contact::query()
            ->where('company_id', $product->company_id)->whereKey($supplierId)->where('user_type', 'supplier')->exists();

        if (! $isSupplier) {
            throw ValidationException::withMessages(['consignment_supplier_id' => ['Choose the supplier of this company who owns the stock.']]);
        }

        $product->ownership = 'consignment';
        $product->consignment_supplier_id = $supplierId;
    }

    private function sent(object $request, string $field): bool
    {
        return method_exists($request, 'has') ? $request->has($field) : isset($request->{$field});
    }
}
