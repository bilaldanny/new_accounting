<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\HasProfilePhoto;
use App\Mail\DynamicEmail;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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
        'role_id',
        'first_name',
        'last_name',
        'email',
        'username',
        'password',
        'profile_photo_path',
        'pass',
        'is_active',
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

        $permissions = Cache::remember("user_menu_permissions:{$roleId}", now()->addMinutes(15), function () use ($roleId) {
            if ($roleId === 1) {
                $menu = Menu::with(['children' => function ($query) {
                    $query->select('route_path AS my_route', 'is_active AS status', 'menus.*')
                        ->whereColumn('menus.id', '!=', 'menus.parent_id');
                }])
                    ->select('route_path AS my_route', 'is_active AS status', 'menus.*')
                    ->orderBy('sort_order', 'asc')
                    ->where('is_active', '=', 1)
                    ->where(function ($query) {
                        $query->whereNull('parent_id')
                            ->orWhereColumn('parent_id', '!=', 'id');
                    })
                    ->get();

                return $menu->map(function ($item) {
                    $array = $item->toArray();
                    $array['permission'] = [['status' => 1]];

                    return $array;
                })->values()->all();
            }

            return Menu::with([
                'permission' => function ($query) {
                    $query->where('status', 1);
                },
                'children' => function ($query) {
                    $query->where(function ($q) {
                        $q->whereHas('permission', function ($query1) {
                            $query1->where('status', 1);
                        })
                            ->orWhereIn('route_path', ['checkout', 'support']);
                    })
                        ->whereColumn('menus.id', '!=', 'menus.parent_id')
                        ->select('route_path AS my_route', 'is_active AS status', 'menus.*');
                },
            ])
                ->where(function ($q) {
                    $q->whereHas('permission', function ($query) {
                        $query->where('status', 1);
                    })
                        ->orWhereIn('route_path', ['checkout', 'support']);
                })
                ->select('route_path AS my_route', 'is_active AS status', 'menus.*')
                ->where('is_active', 1)
                ->orderBy('sort_order', 'asc')
                ->get()
                ->toArray();
        });

        return collect($permissions);
    }

    public function can($abilities, $arguments = [])
    {
        $data = $this->getPermissions();

        if (count($data) > 0) {
            // Assume `$abilities` is the permission you want to check
            $permissions = $data->pluck('route_name')->toArray();

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

    public static function CreateUser($request)
    {
        $user = new User;
        $user->role_id = $request->role_id;
        $user->first_name = $request->first_name;
        $user->last_name = $request->last_name;
        $user->username = $request->username;
        $user->email = $request->email;
        $user->pass = $request->password;
        $user->profile_photo_path = ($request->profile_photo_path) ?? null;
        $user->password = Hash::make($request->password);
        $user->is_active = $request->is_active;
        $user->save();

        return $user;
    }

    public static function UpdateUser($request, $id)
    {
        $user = User::find($id);
        $user->role_id = $request->role_id;
        $user->first_name = $request->first_name;
        $user->last_name = $request->last_name;
        // $user->username = $request->username;
        $user->email = $request->email;
        $user->profile_photo_path = ($request->profile_photo_path) ?? null;
        $user->is_active = $request->is_active;

        if ($request->filled('password')) {
            $user->pass = $request->password;
            $user->password = Hash::make($request->password);
        }

        $user->save();

        return $user;
    }

    public static function DeleteUser($id)
    {
        $user = User::find($id);
        if (isset($user)) {
            $user->delete();
        }
    }
}
