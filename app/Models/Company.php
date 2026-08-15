<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    protected $fillable = [
        'code',
        'name',
        'logo',
        'phone',
        'email',
        'ntn_no',
        'address',
        'country_id',
        'state_id',
        'city_id',
        'zipcode',
        'is_active',
        'max_users',
        'max_branches',
    ];

    protected function isActive(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === 1 || $value === '1' || $value === true,
            set: fn ($value) => ($value === 'false' || $value === false || $value === 0 || $value === '0') ? 0 : 1,
        );
    }

    /**
     * @return HasMany<Branch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
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
        if (! preg_match('/^data:image\/(\w+);base64,/', $image, $matches)) {
            return null;
        }

        $extension = $matches[1];
        $imageData = base64_decode(substr($image, strpos($image, ',') + 1));

        if ($imageData === false) {
            return null;
        }

        $filename = time().'.'.str_replace(' ', '', $companyName).'.'.$extension;
        file_put_contents(self::logoDirectory().'/'.$filename, $imageData);

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
            'country_id' => $row['country_id'] ?? '',
            'state_id' => $row['state_id'] ?? '',
            'city_id' => $row['city_id'] ?? '',
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
}
