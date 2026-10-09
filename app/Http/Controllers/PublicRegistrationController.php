<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SelfSignup;
use App\Support\PublicBranding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Self-service sign-up from the landing page. It can be switched off (`PUBLIC_REGISTRATION_ENABLED=false`), is throttled per IP
 * (the `self-signup` limiter), has a honeypot field, and never says whether an email address is already registered.
 */
class PublicRegistrationController extends Controller
{
    private const GENERIC_ERROR = 'We could not create the account with these details. Please check them, or contact us.';

    public function create(Request $request): Response
    {
        $this->ensureEnabled();

        return Inertia::render('landing/Register', [
            'branding' => PublicBranding::get(),
            'contact' => array_filter(config('landing.contact')),
            'plan' => $request->query('plan'),
            'old' => collect($request->session()->getOldInput())->only(['company_name', 'name', 'email', 'phone', 'plan'])->map(fn ($value): string => (string) $value)->all(),
            'plans' => SelfSignup::trialPlans()->map(fn (SubscriptionPlan $plan): array => [
                'code' => $plan->code,
                'name' => $plan->name,
                'trial_days' => (int) $plan->trial_days,
            ])->values()->all(),
        ]);
    }

    public function store(Request $request, SelfSignup $signup): RedirectResponse
    {
        $this->ensureEnabled();

        // A real visitor never sees this field, so a filled one is a bot: look like a success, create nothing.
        if ($request->filled('website')) {
            return redirect()->route('home');
        }

        $data = $request->validate([
            'company_name' => 'bail|required|string|min:3|max:200',
            'name' => 'bail|required|string|min:2|max:150',
            'email' => 'bail|required|email|max:190',
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9\s\-()]{6,30}$/'],
            'password' => ['bail', 'required', 'confirmed', 'regex:'.SelfSignup::PASSWORD_REGEX],
            'plan' => 'nullable|string|max:100',
        ], ['password.regex' => 'The password needs at least 8 characters with an uppercase letter, a lowercase letter, a number and a special character.']);

        $email = mb_strtolower(trim($data['email']));

        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => self::GENERIC_ERROR]);
        }

        if (($data['plan'] ?? null) !== null && SelfSignup::trialPlans()->firstWhere('code', $data['plan']) === null) {
            throw ValidationException::withMessages(['plan' => 'That plan is not available for a free trial.']);
        }

        try {
            $user = $signup->register([
                'company_name' => trim($data['company_name']),
                'name' => trim($data['name']),
                'email' => $email,
                'phone' => isset($data['phone']) ? preg_replace('/\D+/', '', $data['phone']) : null,
                'password' => $data['password'],
            ], $data['plan'] ?? null);
        } catch (Throwable $e) {
            Log::error('Self sign-up failed: '.$e->getMessage());

            throw ValidationException::withMessages(['email' => self::GENERIC_ERROR]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    private function ensureEnabled(): void
    {
        abort_unless(config('landing.registration_enabled'), 403, 'Registration is closed.');
    }
}
