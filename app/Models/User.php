<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\HasProfilePhoto;
use App\Mail\DynamicEmail;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, SoftDeletes;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasProfilePhoto;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'branch_id',
        'department_id',
        'role_id',
        'first_name',
        'last_name',
        'email',
        'username',
        'password',
        'user_image',
        'pass',
        'is_active',
        'phone',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
        'full_name',
        'role_name',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Send password reset email using the `password_reset` row in `email_templates` when present;
     * placeholders: ['email_name'], ['reset_password_link'], ['reset_url'], ['expire_minutes'], plus globals in createTemplate().
     * Otherwise falls back to Laravel's default notification.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $emailTemplate = EmailTemplate::query()->where('name', 'password_reset')->first();

        if (! $emailTemplate) {
            $this->notify(new ResetPasswordNotification($token));

            return;
        }

        $resetUrl = url(route('password.reset', [
            'token' => $token,
            'email' => $this->getEmailForPasswordReset(),
        ], false));

        $brokerKey = config('auth.defaults.passwords', 'users');
        $expireMinutes = (int) config("auth.passwords.{$brokerKey}.expire", 60);

        $emailData = [
            'email_name' => trim($this->full_name) !== '' ? $this->full_name : ($this->email ?? 'there'),
            'reset_password_link' => $resetUrl,
            // 'reset_password_link' => '<a href="'.e($resetUrl).'" style="display:inline-block;padding:10px 20px;background-color:#199683;color:#ffffff;text-decoration:none;border-radius:4px;margin:10px 0;">'
            //    .e(__('Reset Password'))
            //    .'</a>',
            'reset_url' => $resetUrl,
            'expire_minutes' => (string) $expireMinutes,
        ];

        $mailMeta = [
            'from_name' => $emailTemplate->from_name ?: config('mail.from.name'),
            'from_email' => $emailTemplate->from_email ?: config('mail.from.address'),
            'cc_email' => $emailTemplate->cc_email ?: [],
            'bcc_email' => $emailTemplate->bcc_email ?: [],
        ];

        $body = createTemplate($emailTemplate->template, $emailData);
        $subject = createTemplate($emailTemplate->subject ?? '', $emailData);

        Mail::to($this->getEmailForPasswordReset())->send(new DynamicEmail($body, $subject, $mailMeta));
    }

    public function role()
    {
        return $this->hasOne('App\Models\Role', 'id', 'role_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    protected function companyId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    protected function branchId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    protected function departmentId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    protected function roleId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    public function scopeVisibleToCurrentUser(Builder $query): Builder
    {
        $query->where('role_id', '!=', 1)
            ->whereDoesntHave('role', function (Builder $roleQuery) {
                $roleQuery->whereRaw(
                    "LOWER(REPLACE(name, ' ', '')) = ?",
                    [Role::normalizeName(Role::HIDDEN_ROLE_NAME)]
                );
            });

        $user = Auth::user();

        if ($user?->hasRole('superadmin')) {
            return $query;
        }

        if ($user?->company_id) {
            $query->where('company_id', $user->company_id);
        }

        if ($user?->hasRole('companyadmin') && $user->branch_id) {
            $query->where('branch_id', $user->branch_id);
        }

        return $query;
    }

    public static function findVisibleToCurrentUser(int $id): ?self
    {
        return self::query()->visibleToCurrentUser()->find($id);
    }

    public static function resolveScopedId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'undefined') {
            return null;
        }

        return (int) $value;
    }

    /**
     * Whether this user belongs to any of the given role names (compared case-insensitively; spaces ignored).
     * Uses the app's `roles` table — not Spatie Permission.
     */
    public function hasRole(string ...$names): bool
    {
        $normalize = static function (?string $name): string {
            return strtolower(preg_replace('/\s+/', '', trim((string) $name)));
        };

        $this->loadMissing('role');
        $current = $normalize((string) ($this->role?->name ?? ''));
        if ($current === '') {
            return false;
        }

        foreach ($names as $name) {
            if ($current === $normalize((string) $name)) {
                return true;
            }
        }

        return false;
    }

    public function student()
    {
        return $this->hasOne(Student::class, 'user_id', 'id');
    }

    public function examAttempts()
    {
        return $this->hasMany(ExamAttempt::class);
    }

    public function userNotifications()
    {
        return $this->hasMany(UserNotification::class);
    }

    public function getPermissions(): Collection
    {
        if (! Auth::check()) {
            return collect();
        }

        $roleId = (int) Auth::user()->role_id;

        $permissions = Cache::remember("user_menu_permissions_tree:{$roleId}", now()->addMinutes(15), function () use ($roleId) {
            return Menu::sidebarMenusForRole($roleId);
        });

        return collect($permissions);
    }

    /**
     * @return list<string>
     */
    public function getPermissionPaths(): array
    {
        if (! Auth::check()) {
            return [];
        }

        $roleId = (int) Auth::user()->role_id;

        return Cache::remember("user_permission_paths:{$roleId}", now()->addMinutes(15), function () use ($roleId) {
            return Menu::permittedRoutePathsForRole($roleId);
        });
    }

    public function can($abilities, $arguments = [])
    {
        $permissions = Menu::permittedRouteNamesForRole((int) $this->role_id);

        if (count($permissions) > 0) {
            // If `$abilities` is an array, check for any matching permission
            if (is_array($abilities)) {
                foreach ($abilities as $ability) {
                    if (in_array($ability, $permissions)) {
                        return true;
                    }
                }

                return false;
            }
        } else {
            $abilities = [];
            $permissions = [];
        }

        // Otherwise, check the single ability
        return in_array($abilities, $permissions);
    }

    public function FullName(): Attribute
    {
        $name = '';

        if ($this->first_name) {
            $name .= $this->first_name;
        }

        if ($this->last_name) {
            $name .= ' '.$this->last_name;
        }

        return Attribute::make(
            get: fn () => ucwords($name),
        );
    }

    public function StudentNo(): Attribute
    {
        return Attribute::make(
            get: fn () => ucwords($this->student?->student_id ?? null),
        );
    }

    public function RoleName(): Attribute
    {
        return Attribute::make(
            get: fn () => ucwords((string) ($this->role?->name ?? '')),
        );
    }

    public function getFullNameAttribute()
    {
        $name = '';

        if ($this->first_name) {
            $name .= $this->first_name;
        }

        if ($this->last_name) {
            $name .= ' '.$this->last_name;
        }

        return ucwords($name);
    }

    protected function isActive(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => (bool) $value, // return true/false to frontend

            set: fn ($value) => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        );
    }

    public function getProfilePhotoPathAttribute(): ?string
    {
        return $this->attributes['user_image'] ?? null;
    }

    protected function userImage(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    public static function CreateUser($request): self
    {
        $user = new self;
        $user->company_id = self::resolveScopedId($request->company_id);
        $user->branch_id = self::resolveScopedId($request->branch_id);
        $user->department_id = self::resolveScopedId($request->department_id);
        $user->role_id = $request->role_id;
        $user->first_name = $request->first_name;
        $user->last_name = $request->last_name;
        $user->username = self::normalizeUsername((string) $request->username);
        $user->email = strtolower(trim((string) $request->email));
        $user->phone = $request->phone ?: null;
        $user->user_image = $request->user_image ?: null;
        $user->pass = $request->password;
        $user->password = Hash::make($request->password);
        $user->is_active = $request->is_active ?? true;
        $user->created_by = auth()->id();
        $user->updated_by = auth()->id();
        $user->save();

        return $user;
    }

    public static function UpdateUser($request, $id): self
    {
        $user = self::findVisibleToCurrentUser((int) $id);

        if ($user === null) {
            abort(404);
        }

        $user->company_id = self::resolveScopedId($request->company_id);
        $user->branch_id = self::resolveScopedId($request->branch_id);
        $user->department_id = self::resolveScopedId($request->department_id);
        $user->role_id = $request->role_id;
        $user->first_name = $request->first_name;
        $user->last_name = $request->last_name;
        $user->email = strtolower(trim((string) $request->email));
        $user->phone = $request->phone ?: null;
        $user->user_image = $request->user_image ?: $user->user_image;
        $user->is_active = $request->is_active ?? $user->is_active;
        $user->updated_by = auth()->id();

        if ($request->filled('password')) {
            $user->pass = $request->password;
            $user->password = Hash::make($request->password);
        }

        $user->save();

        return $user;
    }

    public static function upsertFromImport(array $row): string
    {
        $id = self::normalizeImportId($row['id'] ?? null);
        $payload = self::buildImportPayload($row);

        if ($id !== null) {
            $user = self::query()->visibleToCurrentUser()->find($id);

            if ($user === null) {
                throw ValidationException::withMessages([
                    'rows' => ["User with id {$id} was not found."],
                ]);
            }

            self::UpdateUser($payload, $id);

            return 'updated';
        }

        if (! $payload->filled('password')) {
            $payload->merge(['password' => 'password123']);
        }

        self::CreateUser($payload);

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
    protected static function buildImportPayload(array $row): Request
    {
        return new Request([
            'company_id' => $row['company_id'] ?? null,
            'branch_id' => $row['branch_id'] ?? null,
            'department_id' => $row['department_id'] ?? null,
            'role_id' => $row['role_id'] ?? null,
            'first_name' => $row['first_name'] ?? null,
            'last_name' => $row['last_name'] ?? null,
            'username' => $row['username'] ?? null,
            'email' => $row['email'] ?? null,
            'password' => $row['password'] ?? null,
            'phone' => $row['phone'] ?? null,
            'user_image' => $row['user_image'] ?? null,
            'is_active' => filter_var($row['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    public static function createCompanyAdmin($request, int $roleId, int $companyId, int $branchId): self
    {
        $adminName = trim((string) $request->admin_name);
        $nameParts = preg_split('/\s+/', $adminName, 2);
        $username = self::resolveCompanyAdminUsername($request, $companyId);

        $user = new self;
        $user->company_id = $companyId;
        // $user->branch_id = $branchId;
        $user->role_id = $roleId;
        $user->first_name = $nameParts[0] ?? $adminName;
        $user->last_name = $nameParts[1] ?? '';
        $user->username = $username;
        $user->email = $request->admin_email;
        $user->phone = $request->admin_phone ?: null;
        $user->password = Hash::make($request->password);
        $user->pass = $request->password;
        $user->is_active = true;
        $user->created_by = auth()->id();
        $user->updated_by = auth()->id();
        $user->save();

        return $user;
    }

    public static function updateCompanyAdmin($request, int $companyId): void
    {
        $user = self::query()->find($request->user_id);

        if ($user === null) {
            $user = self::query()
                ->where('company_id', $companyId)
                ->orderBy('created_at')
                ->first();
        }

        if ($user === null) {
            return;
        }

        $adminName = trim((string) $request->admin_name);
        $nameParts = preg_split('/\s+/', $adminName, 2);

        $user->first_name = $nameParts[0] ?? $adminName;
        $user->last_name = $nameParts[1] ?? '';
        $user->username = self::normalizeUsername((string) $request->admin_username);
        $user->email = $request->admin_email;
        $user->phone = $request->admin_phone ?: null;
        $user->is_active = $request->is_active ?? $request->active ?? true;
        $user->updated_by = auth()->id();
        $user->save();
    }

    public static function normalizeUsername(string $username): string
    {
        return strtolower(preg_replace('/\s+/', '', trim($username)));
    }

    public static function emailExists(string $email, ?int $exceptUserId = null): bool
    {
        $email = strtolower(trim($email));

        if ($email === '') {
            return false;
        }

        return self::query()
            ->when($exceptUserId !== null, fn ($query) => $query->where('id', '!=', $exceptUserId))
            ->where('email', $email)
            ->exists();
    }

    public static function usernameExists(string $username, ?int $exceptUserId = null): bool
    {
        $username = self::normalizeUsername($username);

        if ($username === '') {
            return false;
        }

        return self::query()
            ->when($exceptUserId !== null, fn ($query) => $query->where('id', '!=', $exceptUserId))
            ->where('username', $username)
            ->exists();
    }

    public static function resolveCompanyAdminUsername($request, int $companyId): string
    {
        $username = self::normalizeUsername((string) ($request->admin_username ?? ''));

        if ($username !== '') {
            return $username;
        }

        $usernameBase = strtolower(preg_replace('/[^a-z0-9]/', '', (string) $request->admin_email));
        $username = $usernameBase !== '' ? $usernameBase : 'admin'.$companyId;
        $suffix = 1;

        while (self::query()->where('username', $username)->exists()) {
            $username = ($usernameBase !== '' ? $usernameBase : 'admin'.$companyId).$suffix;
            $suffix++;
        }

        return $username;
    }

    public static function DeleteUser($id): void
    {
        $user = self::findVisibleToCurrentUser((int) $id);

        if ($user !== null) {
            $user->delete();
        }
    }
}
