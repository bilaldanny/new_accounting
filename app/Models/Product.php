<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'unit_id',
        'brand_id',
        'category_id',
        'subcategory_id',
        'itemtype_id',
        'warranty_id',
        'name',
        'alert_qty',
        'sku',
        'weight',
        'product_desc',
        'product_image',
        'active',
        'type',
    ];

    protected function active(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === 1 || $value === '1' || $value === true,
            set: fn ($value) => ($value === 'false' || $value === false || $value === 0 || $value === '0') ? 0 : 1,
        );
    }

    protected function companyId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    protected function unitId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    protected function brandId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    protected function categoryId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    protected function subcategoryId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    protected function itemtypeId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    protected function warrantyId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'subcategory_id');
    }

    /**
     * @return BelongsTo<ItemType, $this>
     */
    public function itemtype(): BelongsTo
    {
        return $this->belongsTo(ItemType::class, 'itemtype_id');
    }

    /**
     * @return BelongsTo<Warranty, $this>
     */
    public function warranty(): BelongsTo
    {
        return $this->belongsTo(Warranty::class);
    }

    /**
     * @return HasMany<ProductDetail, $this>
     */
    public function productdetail(): HasMany
    {
        return $this->hasMany(ProductDetail::class);
    }

    public static function normalizeName(?string $name): string
    {
        return strtolower(preg_replace('/\s+/', '', trim((string) $name)));
    }

    public static function nameExists(
        string $name,
        ?int $exceptId = null,
        ?int $companyId = null,
    ): bool {
        return self::query()
            ->when($exceptId !== null, fn (Builder $query) => $query->where('id', '!=', $exceptId))
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
            ->whereRaw("LOWER(REPLACE(name, ' ', '')) = ?", [self::normalizeName($name)])
            ->exists();
    }

    public function scopeVisibleToCurrentUser(Builder $query): Builder
    {
        $user = Auth::user();

        if ($user?->hasRole('superadmin')) {
            return $query;
        }

        if ($user?->company_id) {
            return $query->where('company_id', $user->company_id);
        }

        return $query;
    }

    public static function findVisibleToCurrentUser(int $id): ?self
    {
        return self::query()->visibleToCurrentUser()->find($id);
    }

    /**
     * @throws ValidationException
     */
    public static function assertUniqueName(
        string $name,
        ?int $exceptId = null,
        ?int $companyId = null,
    ): void {
        if (self::nameExists($name, $exceptId, $companyId)) {
            throw ValidationException::withMessages([
                'name' => ['A product with this name already exists.'],
            ]);
        }
    }

    public static function resolveScopedId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'undefined') {
            return null;
        }

        return (int) $value;
    }

    public static function imageDirectory(): string
    {
        $path = public_path('images/product_image');
        File::isDirectory($path) or File::makeDirectory($path, 0777, true, true);

        return $path;
    }

    public static function imageUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (str_starts_with($path, '/')) {
            return asset(ltrim($path, '/'));
        }

        return asset('images/product_image/'.$path);
    }

    public static function storeImage($request, ?string $existing = null): ?string
    {
        if (is_object($request) && method_exists($request, 'hasFile') && $request->hasFile('product_image') && $request->file('product_image') instanceof UploadedFile) {
            return self::saveImageFile($request->file('product_image'), (string) $request->name);
        }

        $image = $request->product_image;

        if (is_string($image) && str_starts_with($image, 'data:image')) {
            return self::saveImageFromBase64($image, (string) $request->name);
        }

        if (is_string($image) && $image !== '') {
            return $image;
        }

        return $existing;
    }

    public static function saveImageFile(UploadedFile $file, string $name): string
    {
        $filename = time().'.'.str_replace(' ', '', $name).'.'.$file->getClientOriginalExtension();
        $file->move(self::imageDirectory(), $filename);

        return $filename;
    }

    public static function saveImageFromBase64(string $image, string $name): ?string
    {
        if (! preg_match('/^data:image\/(\w+);base64,/', $image, $matches)) {
            return null;
        }

        $extension = $matches[1];
        $imageData = base64_decode(substr($image, strpos($image, ',') + 1));

        if ($imageData === false) {
            return null;
        }

        $filename = time().'.'.str_replace(' ', '', $name).'.'.$extension;
        file_put_contents(self::imageDirectory().'/'.$filename, $imageData);

        return $filename;
    }

    public static function generateSku(?int $companyId, ?string $requestedSku = null): string
    {
        $requested = trim((string) $requestedSku);

        if ($requested !== '') {
            return $requested;
        }

        $prefix = 'AS';

        if ($companyId !== null) {
            $settingPrefix = CompanySetting::query()
                ->where('company_id', $companyId)
                ->value('product');

            if (is_string($settingPrefix) && trim($settingPrefix) !== '') {
                $prefix = trim($settingPrefix);
            }
        }

        $lastId = (int) self::query()
            ->withTrashed()
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
            ->max('id');

        $next = $lastId + 1;

        do {
            $sku = $prefix.'-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
            $next++;
        } while (
            self::query()
                ->withTrashed()
                ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
                ->where('sku', $sku)
                ->exists()
        );

        return $sku;
    }

    public static function variationSku(string $productSku, int $index): string
    {
        return $productSku.'-'.$index;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $details
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeDetails(?array $details, string $productName): array
    {
        if ($details === null || $details === []) {
            return [[
                'variation_name' => 'dummy',
                'name' => $productName.' dummy',
                'default_purchase_price' => 0,
                'dpp_unit_price' => 0,
                'largequantity' => 0,
                'smallquantity' => 0,
                'profit_percent' => 0,
                'default_sell_price' => 0,
                'variation_image' => '',
            ]];
        }

        return collect($details)
            ->map(function ($row) use ($productName) {
                if (! is_array($row)) {
                    return null;
                }

                $variationName = trim((string) ($row['variation_name'] ?? ''));

                if ($variationName === '') {
                    return null;
                }

                return array_merge($row, [
                    'variation_name' => $variationName,
                    'name' => trim((string) ($row['name'] ?? ($productName.' '.$variationName))),
                ]);
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @throws ValidationException
     */
    public static function assertValidDetails(?array $details): void
    {
        $normalized = self::normalizeDetails($details, 'Product');

        if ($normalized === []) {
            throw ValidationException::withMessages([
                'productdetail' => ['Add at least one pricing row.'],
            ]);
        }
    }

    public static function createProduct(object $request): self
    {
        $companyId = self::resolveScopedId($request->company_id);

        self::assertUniqueName($request->name, null, $companyId);
        self::assertValidDetails($request->productdetail ?? null);

        $product = new self;
        $product->fillFromRequest($request, $companyId);
        $product->sku = self::generateSku($companyId, $request->sku ?? null);
        $product->product_image = self::storeImage($request);
        $product->save();

        self::syncDetails($product, $request->productdetail ?? []);

        return $product;
    }

    public static function updateProduct(object $request, int $id): self
    {
        $product = self::findVisibleToCurrentUser($id);

        if ($product === null) {
            abort(404);
        }

        $companyId = self::resolveScopedId($request->company_id);

        self::assertUniqueName($request->name, $id, $companyId);
        self::assertValidDetails($request->productdetail ?? null);

        $product->fillFromRequest($request, $companyId);
        $product->sku = trim((string) ($request->sku ?? '')) !== ''
            ? (string) $request->sku
            : $product->sku;
        $product->product_image = self::storeImage($request, $product->product_image);
        $product->save();

        self::syncDetails($product, $request->productdetail ?? []);

        return $product;
    }

    public static function deleteProduct(int $id): void
    {
        $product = self::findVisibleToCurrentUser($id);

        if ($product === null) {
            return;
        }

        $product->delete();
    }

    /**
     * @param  array<int, mixed>  $details
     */
    public static function syncDetails(self $product, array $details): void
    {
        $normalized = self::normalizeDetails($details, $product->name);
        $keptIds = [];

        foreach ($normalized as $index => $row) {
            $detail = ProductDetail::syncForProduct($product, $row, $index + 1);
            $keptIds[] = $detail->id;
        }

        ProductDetail::query()
            ->where('product_id', $product->id)
            ->when($keptIds !== [], fn (Builder $query) => $query->whereNotIn('id', $keptIds))
            ->when($keptIds === [], fn (Builder $query) => $query)
            ->delete();
    }

    protected function fillFromRequest(object $request, ?int $companyId): void
    {
        $this->company_id = $companyId;
        $this->name = $request->name;
        $this->type = in_array($request->type, ['single', 'variable'], true) ? $request->type : 'single';
        $this->unit_id = self::resolveScopedId($request->unit_id);
        $this->brand_id = self::resolveScopedId($request->brand_id);
        $this->category_id = self::resolveScopedId($request->category_id);
        $this->subcategory_id = self::resolveScopedId($request->subcategory_id);
        $this->itemtype_id = self::resolveScopedId($request->itemtype_id);
        $this->warranty_id = self::resolveScopedId($request->warranty_id);
        $this->alert_qty = $request->alert_qty !== null && $request->alert_qty !== '' ? (int) $request->alert_qty : null;
        $this->weight = $request->weight !== null && $request->weight !== '' ? (int) $request->weight : null;
        $this->product_desc = $request->product_desc ?: null;
        $this->active = $request->active ?? true;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function upsertFromImport(array $row): string
    {
        $id = self::normalizeImportId($row['id'] ?? null);
        $payload = self::buildImportPayload($row, $id);

        if ($id !== null) {
            $product = self::findVisibleToCurrentUser($id);

            if ($product === null) {
                throw ValidationException::withMessages([
                    'rows' => ["Product with id {$id} was not found."],
                ]);
            }

            self::updateProduct($payload, $id);

            return 'updated';
        }

        self::createProduct($payload);

        return 'created';
    }

    protected static function normalizeImportId(mixed $id): ?int
    {
        if ($id === null || $id === '' || $id === 0 || $id === '0') {
            return null;
        }

        if (is_numeric($id)) {
            $normalized = (int) $id;

            return $normalized > 0 ? $normalized : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected static function buildImportPayload(array $row, ?int $existingId = null): object
    {
        $companyId = self::resolveScopedId($row['company_id'] ?? null);

        if ($companyId === null && Auth::user()?->company_id) {
            $companyId = (int) Auth::user()->company_id;
        }

        if ($existingId !== null) {
            $existingProduct = self::findVisibleToCurrentUser($existingId);

            if ($existingProduct !== null && $companyId === null) {
                $companyId = self::resolveScopedId($existingProduct->company_id);
            }
        }

        $type = strtolower(trim((string) ($row['type'] ?? 'single')));

        if (! in_array($type, ['single', 'variable'], true)) {
            $type = 'single';
        }

        $details = self::parseImportDetails($row, $type, (string) ($row['name'] ?? ''));

        return (object) [
            'company_id' => $companyId,
            'name' => (string) ($row['name'] ?? ''),
            'type' => $type,
            'unit_id' => self::resolveImportRecordId($row['unit_id'] ?? $row['unit'] ?? null, $companyId, Unit::class, 'Unit', true),
            'brand_id' => self::resolveImportRecordId($row['brand_id'] ?? $row['brand'] ?? null, $companyId, Brand::class, 'Brand', true),
            'category_id' => self::resolveImportRecordId($row['category_id'] ?? $row['category'] ?? null, $companyId, Category::class, 'Category', true),
            'subcategory_id' => self::resolveImportRecordId($row['subcategory_id'] ?? $row['subcategory'] ?? null, $companyId, Category::class, 'Subcategory', false),
            'itemtype_id' => self::resolveImportRecordId($row['itemtype_id'] ?? $row['item_type'] ?? $row['itemtype'] ?? null, $companyId, ItemType::class, 'Item type', true),
            'warranty_id' => self::resolveImportRecordId($row['warranty_id'] ?? $row['warranty'] ?? null, $companyId, Warranty::class, 'Warranty', false),
            'alert_qty' => $row['alert_qty'] ?? null,
            'sku' => $row['sku'] ?? null,
            'weight' => $row['weight'] ?? null,
            'product_desc' => $row['product_desc'] ?? $row['description'] ?? null,
            'product_image' => $row['product_image'] ?? null,
            'active' => self::normalizeImportBool($row['active'] ?? $row['is_active'] ?? 1),
            'productdetail' => $details,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, array<string, mixed>>
     */
    public static function parseImportDetails(array $row, string $type, string $productName): array
    {
        if (isset($row['productdetail']) && is_array($row['productdetail'])) {
            return self::normalizeDetails($row['productdetail'], $productName);
        }

        $purchase = ProductDetail::resolveNumeric($row['default_purchase_price'] ?? $row['purchase_price'] ?? 0);
        $margin = ProductDetail::resolveNumeric($row['profit_percent'] ?? $row['margin'] ?? 0);
        $sell = ProductDetail::resolveNumeric($row['default_sell_price'] ?? $row['sell_price'] ?? 0);
        $large = (int) ProductDetail::resolveNumeric($row['largequantity'] ?? 0);
        $small = (int) ProductDetail::resolveNumeric($row['smallquantity'] ?? 0);

        $names = ['dummy'];

        if ($type === 'variable') {
            $rawValues = trim((string) ($row['variation_values'] ?? $row['variations'] ?? ''));
            $parsed = collect(explode('|', $rawValues))
                ->map(fn (string $name) => trim($name))
                ->filter()
                ->values()
                ->all();

            if ($parsed !== []) {
                $names = $parsed;
            }
        }

        return array_map(fn (string $variationName) => [
            'variation_name' => $variationName,
            'name' => trim($productName.' '.$variationName),
            'default_purchase_price' => $purchase,
            'dpp_unit_price' => $purchase,
            'largequantity' => $large,
            'smallquantity' => $small,
            'profit_percent' => $margin,
            'default_sell_price' => $sell,
            'variation_image' => '',
        ], $names);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    protected static function resolveImportRecordId(
        mixed $value,
        ?int $companyId,
        string $modelClass,
        string $label,
        bool $required,
    ): ?int {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            if ($required) {
                throw ValidationException::withMessages([
                    'rows' => ["{$label} is required and must match an existing {$label} name or id."],
                ]);
            }

            return null;
        }

        if (is_numeric($value)) {
            $recordId = (int) $value;

            if ($recordId <= 0) {
                return null;
            }

            $exists = $modelClass::query()
                ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
                ->where('id', $recordId)
                ->exists();

            if (! $exists) {
                throw ValidationException::withMessages([
                    'rows' => ["{$label} with id {$recordId} was not found."],
                ]);
            }

            return $recordId;
        }

        $record = $modelClass::query()
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
            ->whereRaw('LOWER(REPLACE(name, \' \', \'\')) = ?', [$modelClass::normalizeName((string) $value)])
            ->first();

        if ($record === null) {
            throw ValidationException::withMessages([
                'rows' => ["{$label} \"{$value}\" was not found."],
            ]);
        }

        return (int) $record->id;
    }

    protected static function normalizeImportBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'yes';
    }
}
