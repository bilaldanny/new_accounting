<?php

namespace App\Models;

use App\Support\Base64Upload;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

class Company extends Model
{
    use SoftDeletes;

    /**
     * @var list<string>
     */
    public const TENANT_STATUSES = ['trial', 'active', 'suspended', 'cancelled'];

    protected $fillable = [
        'code',
        'name',
        'logo',
        'phone',
        'cell',
        'whatsapp_no',
        'fb_link',
        'email',
        'ntn_no',
        'strn_no',
        'address',
        'country_id',
        'state_id',
        'city_id',
        'zipcode',
        'is_active',
        'max_users',
        'max_branches',
        'subscription_plan_id',
        'tenant_status',
        'trial_ends_at',
        'current_period_starts_at',
        'current_period_ends_at',
        'max_warehouses',
        'max_products',
        'max_invoices_per_month',
        'max_storage_mb',
    ];

    protected function isActive(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === 1 || $value === '1' || $value === true,
            set: fn ($value) => ($value === 'false' || $value === false || $value === 0 || $value === '0') ? 0 : 1,
        );
    }

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_starts_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Branch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /**
     * @return BelongsTo<SubscriptionPlan, $this>
     */
    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    /**
     * @return HasMany<SubscriptionInvoice, $this>
     */
    public function subscriptionInvoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    /**
     * @return HasOne<CompanySetting, $this>
     */
    public function companySetting(): HasOne
    {
        return $this->hasOne(CompanySetting::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @param  LengthAwarePaginator<int, Company>  $companies
     */
    public static function enrichIndexCollection(LengthAwarePaginator $companies): void
    {
        /** @var Collection<int, Company> $collection */
        $collection = $companies->getCollection();

        if ($collection->isEmpty()) {
            return;
        }

        $companyIds = $collection->pluck('id');

        /** @var Collection<int, User> $admins */
        $admins = User::query()
            ->whereIn('company_id', $companyIds)
            ->orderBy('created_at')
            ->get()
            ->groupBy('company_id')
            ->map(fn (Collection $users) => $users->first());

        $collection->transform(function (Company $company) use ($admins): Company {
            $admin = $admins->get($company->id);

            $company->admin_name = $admin !== null
                ? trim($admin->first_name.' '.$admin->last_name)
                : null;
            $company->admin_email = $admin?->email;
            $company->admin_phone = $admin?->phone;
            $company->branches_usage = sprintf(
                '%d/%d',
                (int) ($company->branches_count ?? 0),
                (int) $company->max_branches,
            );
            $company->users_usage = sprintf(
                '%d/%d',
                (int) ($company->users_count ?? 0),
                (int) $company->max_users,
            );

            return $company;
        });

        $companies->setCollection($collection);
    }

    public static function createCompany($request): self
    {
        $company = new self;
        $company->code = self::resolveCode($request->input('code'), (string) $request->name);
        $company->name = $request->name;
        $company->logo = self::storeLogo($request);
        $company->phone = $request->phone;
        $company->email = $request->email;
        $company->ntn_no = $request->ntn_no;
        $company->strn_no = $request->strn_no ?? $company->strn_no;
        $company->address = $request->address;
        $company->country_id = $request->country_id ?: null;
        $company->state_id = $request->state_id ?: null;
        $company->city_id = $request->city_id ?: null;
        $company->zipcode = $request->zipcode;
        $company->max_users = $request->max_users ?? $request->user_no ?? 10;
        $company->max_branches = $request->max_branches ?? $request->branch_no ?? 2;
        $company->is_active = $request->is_active ?? $request->active ?? true;
        $company->save();

        return $company;
    }

    public static function updateCompany($request, $id): self
    {
        $company = self::findOrFail($id);
        $company->code = self::normalizeCode((string) $request->code);
        $company->name = $request->name;
        $company->logo = self::storeLogo($request, $company->logo);
        $company->phone = $request->phone;
        $company->email = $request->email;
        $company->ntn_no = $request->ntn_no;
        $company->strn_no = $request->strn_no ?? $company->strn_no;
        $company->address = $request->address;
        $company->country_id = $request->country_id ?: null;
        $company->state_id = $request->state_id ?: null;
        $company->city_id = $request->city_id ?: null;
        $company->zipcode = $request->zipcode;
        $company->max_users = $request->max_users ?? $request->user_no ?? 10;
        $company->max_branches = $request->max_branches ?? $request->branch_no ?? 2;
        $company->is_active = $request->is_active ?? $request->active ?? true;
        $company->save();

        return $company;
    }

    public static function deleteCompany($id): void
    {
        $company = self::find($id);

        if ($company !== null) {
            $company->delete();
        }
    }

    public static function generateUniqueCode(string $name = 'COMP'): string
    {
        return self::nextCode();
    }

    public static function nextCode(): string
    {
        $maxNumber = self::query()
            ->withTrashed()
            ->where('code', 'like', 'CO-%')
            ->pluck('code')
            ->map(fn (string $code) => self::extractCodeNumber($code))
            ->max() ?? 0;

        $nextNumber = $maxNumber + 1;
        $code = self::formatCode($nextNumber);

        while (self::codeExists($code)) {
            $nextNumber++;
            $code = self::formatCode($nextNumber);
        }

        return $code;
    }

    public static function formatCode(int $number): string
    {
        return sprintf('CO-%05d', $number);
    }

    public static function extractCodeNumber(string $code): int
    {
        $normalized = strtoupper(str_replace(' ', '', trim($code)));

        if (preg_match('/^CO-(\d+)$/', $normalized, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    public static function normalizeCode(string $code): string
    {
        $normalized = strtoupper(str_replace(' ', '', trim($code)));

        if (preg_match('/^CO-(\d+)$/', $normalized, $matches)) {
            return self::formatCode((int) $matches[1]);
        }

        return $normalized;
    }

    public static function resolveCode(?string $code, string $name): string
    {
        $normalized = self::normalizeCode((string) $code);

        if ($normalized !== '') {
            return $normalized;
        }

        return self::nextCode();
    }

    public static function codeExists(string $code, ?int $exceptId = null): bool
    {
        $normalized = self::normalizeCode($code);

        if ($normalized === '') {
            return false;
        }

        return self::query()
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->where('code', $normalized)
            ->exists();
    }

    public static function storeLogo($request, ?string $existing = null): ?string
    {
        if ($request->hasFile('logo') && $request->file('logo') instanceof UploadedFile) {
            return self::saveLogoFile($request->file('logo'), (string) $request->name);
        }

        $logo = $request->logo;

        if (is_string($logo) && str_starts_with($logo, 'data:image')) {
            return self::saveLogoFromBase64($logo, (string) $request->name);
        }

        if (is_string($logo) && $logo !== '') {
            return $logo;
        }

        return $existing;
    }

    public static function logoUrl(?string $path): ?string
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

        return asset('images/company_images/'.$path);
    }

    protected static function logoDirectory(): string
    {
        $path = public_path('images/company_images');
        File::isDirectory($path) or File::makeDirectory($path, 0777, true, true);

        return $path;
    }

    protected static function saveLogoFile(UploadedFile $file, string $companyName): string
    {
        $filename = time().'.'.str_replace(' ', '', $companyName).'.'.$file->getClientOriginalExtension();
        $file->move(self::logoDirectory(), $filename);

        return $filename;
    }

    protected static function saveLogoFromBase64(string $image, string $companyName): ?string
    {
        $decoded = Base64Upload::decode($image);

        if ($decoded === null) {
            return null;
        }

        $filename = time().'.'.str_replace(' ', '', $companyName).'.'.$decoded['extension'];
        file_put_contents(self::logoDirectory().'/'.$filename, $decoded['binary']);

        return $filename;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function upsertFromImport(array $row): string
    {
        $id = self::normalizeImportId($row['id'] ?? null);
        $payload = self::buildImportRequest($row);

        if ($id !== null) {
            $company = self::query()->find($id);

            if ($company === null) {
                throw ValidationException::withMessages([
                    'rows' => ["Company with id {$id} was not found."],
                ]);
            }

            self::updateCompany($payload, $id);
            User::updateCompanyAdmin($payload, $id);

            return 'updated';
        }

        self::assertImportCreateFields($payload);

        $company = self::createCompany($payload);
        CompanySetting::createCompanySettings($company->id, (string) $company->name);
        $branch = Branch::createCompanyBranch($company->id);
        $role = Role::find(2);

        if ($role === null) {
            throw ValidationException::withMessages([
                'rows' => ['Default company admin role was not found.'],
            ]);
        }

        User::createCompanyAdmin($payload, (int) $role->id, (int) $company->id, (int) $branch->id);

        return 'created';
    }

    /**
     * @throws ValidationException
     */
    protected static function assertImportCreateFields(Request $payload): void
    {
        $missing = [];

        foreach (['name', 'admin_name', 'admin_username', 'admin_email', 'password', 'max_users', 'max_branches'] as $field) {
            if ($payload->input($field) === null || $payload->input($field) === '') {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'rows' => ['New company rows require: '.implode(', ', $missing).'.'],
            ]);
        }
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
    protected static function buildImportRequest(array $row): Request
    {
        $isActive = $row['is_active'] ?? $row['active'] ?? 1;
        $countryId = Branch::resolveImportCountryId($row['country_id'] ?? $row['country'] ?? null);
        $stateId = Branch::resolveImportStateId($row['state_id'] ?? $row['state'] ?? null, $countryId);
        $cityId = Branch::resolveImportCityId($row['city_id'] ?? $row['city'] ?? null, $stateId, $countryId);
        $code = isset($row['code']) ? self::normalizeCode((string) $row['code']) : '';
        $name = (string) ($row['name'] ?? '');

        if ($code === '' && $name !== '') {
            $code = self::resolveCode('', $name);
        }

        return Request::create('/', 'POST', [
            'code' => $code,
            'name' => $name,
            'email' => (string) ($row['email'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'ntn_no' => (string) ($row['ntn_no'] ?? ''),
            'address' => (string) ($row['address'] ?? ''),
            'country_id' => $countryId,
            'state_id' => $stateId,
            'city_id' => $cityId,
            'zipcode' => (string) ($row['zipcode'] ?? ''),
            'max_users' => $row['max_users'] ?? $row['user_no'] ?? 10,
            'max_branches' => $row['max_branches'] ?? $row['branch_no'] ?? 2,
            'is_active' => self::normalizeImportBool($isActive),
            'logo' => (string) ($row['logo'] ?? ''),
            'admin_name' => (string) ($row['admin_name'] ?? ''),
            'admin_username' => User::normalizeUsername((string) ($row['admin_username'] ?? '')),
            'admin_email' => (string) ($row['admin_email'] ?? ''),
            'admin_phone' => (string) ($row['admin_phone'] ?? ''),
            'password' => (string) ($row['password'] ?? ''),
            'password_confirmation' => (string) ($row['password_confirmation'] ?? $row['password'] ?? ''),
        ]);
    }

    protected static function normalizeImportBool(mixed $value): int
    {
        return $value === true || $value === 1 || $value === '1' ? 1 : 0;
    }

    /**
     * Assigns a company to a plan — its first subscription, or an Upgrade/Downgrade/Plan Change of an
     * existing one. Always snapshots the plan's limits onto the company's own limit columns (so a later
     * plan edit or deletion never silently changes what an already-subscribed tenant is allowed). Only a
     * company with NO plan yet (its very first subscription) gets a fresh trial window and billing
     * period opened; switching plans while already subscribed changes what the tenant is allowed without
     * resetting its trial or where it is in its current cycle.
     */
    public function changePlan(SubscriptionPlan $plan): void
    {
        $isFirstSubscription = $this->subscription_plan_id === null;

        $this->subscription_plan_id = $plan->id;
        $this->max_users = $plan->max_users;
        $this->max_branches = $plan->max_branches;
        $this->max_warehouses = $plan->max_warehouses;
        $this->max_products = $plan->max_products;
        $this->max_invoices_per_month = $plan->max_invoices_per_month;
        $this->max_storage_mb = $plan->max_storage_mb;

        if ($isFirstSubscription) {
            $now = now();
            $this->tenant_status = $plan->trial_days > 0 ? 'trial' : 'active';
            $this->trial_ends_at = $plan->trial_days > 0 ? $now->copy()->addDays($plan->trial_days) : null;
            $this->current_period_starts_at = $now;
            $this->current_period_ends_at = $now->copy()->addDays($plan->cycleDays());
        }

        $this->save();
    }

    /**
     * @throws ValidationException
     */
    public static function assertValidTenantStatus(string $status): void
    {
        if (! in_array($status, self::TENANT_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => ['Tenant status must be one of: '.implode(', ', self::TENANT_STATUSES).'.'],
            ]);
        }
    }

    /**
     * Generic limit check shared by every per-feature limit below: how many of $used may exist against
     * $max before blocking a new one.
     */
    private static function withinLimit(int $used, int $max): bool
    {
        return $used < $max;
    }

    /**
     * @throws ValidationException
     */
    private static function assertWithinLimit(int $used, int $max, string $field, string $label): void
    {
        if (self::withinLimit($used, $max)) {
            return;
        }

        throw ValidationException::withMessages([
            $field => ["Maximum {$label} limit reached for this company."],
        ]);
    }

    public function canAddUser(): bool
    {
        return self::withinLimit(User::query()->where('company_id', $this->id)->count(), (int) $this->max_users);
    }

    /**
     * @throws ValidationException
     */
    public function assertCanAddUser(): void
    {
        self::assertWithinLimit(
            User::query()->where('company_id', $this->id)->count(),
            (int) $this->max_users,
            'company_id',
            'user',
        );
    }

    public function canAddWarehouse(): bool
    {
        return self::withinLimit(Warehouse::query()->where('company_id', $this->id)->count(), (int) $this->max_warehouses);
    }

    /**
     * @throws ValidationException
     */
    public function assertCanAddWarehouse(): void
    {
        self::assertWithinLimit(
            Warehouse::query()->where('company_id', $this->id)->count(),
            (int) $this->max_warehouses,
            'company_id',
            'warehouse',
        );
    }

    public function canAddProduct(): bool
    {
        return self::withinLimit(Product::query()->where('company_id', $this->id)->count(), (int) $this->max_products);
    }

    /**
     * @throws ValidationException
     */
    public function assertCanAddProduct(): void
    {
        self::assertWithinLimit(
            Product::query()->where('company_id', $this->id)->count(),
            (int) $this->max_products,
            'company_id',
            'product',
        );
    }

    /**
     * Sell invoices (type = sell, excluding drafts/quotations) created in the current calendar month.
     */
    public function invoicesThisMonth(): int
    {
        return Transaction::query()
            ->where('company_id', $this->id)
            ->where('type', Transaction::TYPE_SELL)
            ->whereNotIn('status', ['draft', 'quotation'])
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();
    }

    public function canAddInvoice(): bool
    {
        return self::withinLimit($this->invoicesThisMonth(), (int) $this->max_invoices_per_month);
    }

    /**
     * @throws ValidationException
     */
    public function assertCanAddInvoice(): void
    {
        if (self::withinLimit($this->invoicesThisMonth(), (int) $this->max_invoices_per_month)) {
            return;
        }

        throw ValidationException::withMessages([
            'company_id' => ['Maximum monthly invoice limit reached for this company.'],
        ]);
    }

    /**
     * Bytes used by this company's stored attachments: its own logo, its products'/variations' images,
     * and the documents attached to its sell/purchase payments. Computed on demand from the files that
     * actually exist rather than a running counter, so it can never drift out of sync with reality.
     */
    public function storageUsedBytes(): int
    {
        $bytes = 0;

        if ($this->logo) {
            $bytes += self::fileSize(public_path('images/company_images/'.$this->logo));
        }

        Product::query()
            ->where('company_id', $this->id)
            ->whereNotNull('product_image')
            ->pluck('product_image')
            ->each(function (string $file) use (&$bytes): void {
                $bytes += self::fileSize(Product::imageDirectory().'/'.$file);
            });

        Payment::query()
            ->where('company_id', $this->id)
            ->whereNotNull('document')
            ->pluck('document')
            ->each(function (string $file) use (&$bytes): void {
                $bytes += self::fileSize(Payment::imageDirectory().'/'.$file);
            });

        return $bytes;
    }

    public function canAddStorageBytes(int $incomingBytes): bool
    {
        $maxBytes = (int) $this->max_storage_mb * 1024 * 1024;

        return ($this->storageUsedBytes() + $incomingBytes) <= $maxBytes;
    }

    /**
     * @throws ValidationException
     */
    public function assertCanAddStorageBytes(int $incomingBytes): void
    {
        if ($this->canAddStorageBytes($incomingBytes)) {
            return;
        }

        throw ValidationException::withMessages([
            'document' => ['This company has reached its storage limit. Delete old attachments or upgrade the plan.'],
        ]);
    }

    private static function fileSize(string $path): int
    {
        return is_file($path) ? (int) filesize($path) : 0;
    }
}
