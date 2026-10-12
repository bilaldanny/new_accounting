<?php

namespace App\Providers;

use App\Http\Responses\LoginResponse;
use App\Http\Responses\LogoutResponse;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\User;
use App\Services\Fbr\FbrInvoiceGateway;
use App\Services\Fbr\StubFbrGateway;
use App\Services\LeadAllocator;
use App\Services\Security\SessionRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);

        // No live FBR gateway exists (no verified spec, sandbox or credentials): only the stub is ever bound.
        $this->app->bind(FbrInvoiceGateway::class, StubFbrGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureFromSettings();
        $this->registerSecurityHooks();
    }

    /**
     * Login audit (login, failed, logout, lockout), the password history of the policy, and lead allocation. Each is inert until
     * its table exists and a company configures it.
     */
    protected function registerSecurityHooks(): void
    {
        $audit = function (string $event, ?User $user, ?string $identity = null): void {
            try {
                if (! Schema::hasTable('login_events')) {
                    return;
                }

                $request = request();
                DB::table('login_events')->insert([
                    'company_id' => $user?->company_id, 'user_id' => $user?->id, 'identity' => $identity, 'event' => $event, 'ip' => $request->ip(),
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 255), 'device' => SessionRegistry::device((string) $request->userAgent()),
                    'created_at' => now(),
                ]);
            } catch (Throwable) {
                // auditing never blocks a sign-in
            }
        };

        Event::listen(Login::class, fn ($e) => $audit('login', $e->user instanceof User ? $e->user : null));
        Event::listen(Logout::class, fn ($e) => $audit('logout', $e->user instanceof User ? $e->user : null));
        Event::listen(Failed::class, fn ($e) => $audit('failed', $e->user instanceof User ? $e->user : null, (string) ($e->credentials['email'] ?? $e->credentials['username'] ?? '')));
        Event::listen(Lockout::class, fn ($e) => $audit('lockout', null, (string) ($e->request->input('email') ?? $e->request->input('username') ?? '')));

        User::saved(function (User $user): void {
            try {
                if ($user->wasChanged('password') && Schema::hasTable('password_histories')) {
                    DB::table('password_histories')->insert(['user_id' => $user->id, 'hash' => $user->password, 'created_at' => now()]);
                    $old = DB::table('password_histories')->where('user_id', $user->id)->orderByDesc('id')->skip(20)->take(100)->pluck('id');
                    DB::table('password_histories')->whereIn('id', $old)->delete();

                    if (Schema::hasColumn('users', 'password_changed_at')) {
                        DB::table('users')->where('id', $user->id)->update(['password_changed_at' => now()]);
                    }
                }
            } catch (Throwable) {
                // the password is already saved
            }
        });

        Lead::creating(fn (Lead $lead) => app(LeadAllocator::class)->assign($lead));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    protected function configureFromSettings(): void
    {
        try {
            Setting::applyAppConfig();
            Setting::applyMailConfig();
        } catch (Throwable) {
            // Settings table may not exist during early migrations.
        }
    }
}
