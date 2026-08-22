<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;

class ProductDetail extends Model
{
    protected $fillable = [
        'product_id',
        'name',
        'sku',
        'variation_name',
        'default_purchase_price',
        'dpp_unit_price',
        'largequantity',
        'smallquantity',
        'profit_percent',
        'default_sell_price',
        'variation_image',
    ];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public static function resolveNumeric(mixed $value, float $default = 0): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return (float) $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function syncForProduct(Product $product, array $row, int $index): self
    {
        $detail = isset($row['id']) && is_numeric($row['id'])
            ? self::query()->where('product_id', $product->id)->find((int) $row['id'])
            : null;

        if ($detail === null) {
            $detail = new self;
            $detail->product_id = $product->id;
        }

        $variationName = trim((string) ($row['variation_name'] ?? 'dummy'));
        $purchasePrice = self::resolveNumeric($row['default_purchase_price'] ?? 0);
        $profitPercent = self::resolveNumeric($row['profit_percent'] ?? 0);
        $sellPrice = self::resolveNumeric($row['default_sell_price'] ?? 0);

        if ($sellPrice <= 0 && $purchasePrice > 0) {
            $sellPrice = round($purchasePrice + (($purchasePrice * $profitPercent) / 100), 2);
        }

        $detail->name = trim((string) ($row['name'] ?? ($product->name.' '.$variationName)));
        $detail->sku = trim((string) ($row['sku'] ?? '')) !== ''
            ? (string) $row['sku']
            : Product::variationSku($product->sku, $index);
        $detail->variation_name = $variationName !== '' ? $variationName : 'dummy';
        $detail->default_purchase_price = $purchasePrice;
        $detail->dpp_unit_price = self::resolveNumeric($row['dpp_unit_price'] ?? $purchasePrice);
        $detail->largequantity = (int) self::resolveNumeric($row['largequantity'] ?? 0);
        $detail->smallquantity = (int) self::resolveNumeric($row['smallquantity'] ?? 0);
        $detail->profit_percent = $profitPercent;
        $detail->default_sell_price = $sellPrice;
        $detail->variation_image = self::storeImage($row, $detail->variation_image);
        $detail->save();

        return $detail;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function storeImage(array $row, ?string $existing = null): ?string
    {
        $image = $row['variation_image'] ?? null;

        if ($image instanceof UploadedFile) {
            return self::saveImageFile($image, (string) ($row['variation_name'] ?? 'variation'));
        }

        if (is_string($image) && str_starts_with($image, 'data:image')) {
            return Product::saveImageFromBase64($image, (string) ($row['variation_name'] ?? 'variation'));
        }

        if (is_string($image) && $image !== '') {
            return $image;
        }

        return $existing;
    }

    protected static function saveImageFile(UploadedFile $file, string $name): string
    {
        $filename = time().'.'.str_replace(' ', '', $name).'.'.$file->getClientOriginalExtension();
        $file->move(Product::imageDirectory(), $filename);

        return $filename;
    }
}
