<?php

namespace App\Http\Controllers;

use App\Models\Country;
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
            'countries' => Country::query()->orderBy('name')->get(['id', 'name'])->map(fn (Country $country): array => ['id' => $country->id, 'name' => $country->name])->all(),
            'old' => collect($request->session()->getOldInput())->only(['company_name', 'company_email', 'company_phone', 'address', 'country_id', 'zipcode', 'ntn_no', 'strn_no', 'first_name', 'last_name', 'username', 'email', 'phone', 'plan'])->map(fn ($value): string => (string) $value)->all(),
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
            'company_email' => 'nullable|email|max:190',
            'company_phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9\s\-()]{6,30}$/'],
            'address' => 'nullable|string|max:500',
            'country_id' => 'nullable|integer|exists:countries,id',
            'zipcode' => 'nullable|string|max:20',
            'ntn_no' => 'nullable|string|max:30',
            'strn_no' => 'nullable|string|max:30',
            'first_name' => 'bail|required|string|min:2|max:75',
            'last_name' => 'nullable|string|max:75',
            'username' => ['bail', 'required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'email' => 'bail|required|email|max:190',
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9\s\-()]{6,30}$/'],
            'password' => ['bail', 'required', 'confirmed', 'regex:'.SelfSignup::PASSWORD_REGEX],
            'plan' => 'nullable|string|max:100',
        ], ['password.regex' => 'The password needs at least 8 characters with an uppercase letter, a lowercase letter, a number and a special character.']);

        $email = mb_strtolower(trim($data['email']));
        $username = User::normalizeUsername($data['username']);

        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => self::GENERIC_ERROR]);
        }

        if (User::query()->where('username', $username)->exists()) {
            throw ValidationException::withMessages(['username' => 'This username is not available. Please choose another.']);
        }

        if (($data['plan'] ?? null) !== null && SelfSignup::trialPlans()->firstWhere('code', $data['plan']) === null) {
            throw ValidationException::withMessages(['plan' => 'That plan is not available for a free trial.']);
        }

        try {
            $user = $signup->register([
                'company_name' => trim($data['company_name']),
                'company_email' => filled($data['company_email'] ?? null) ? mb_strtolower(trim($data['company_email'])) : $email,
                'company_phone' => isset($data['company_phone']) ? preg_replace('/\D+/', '', $data['company_phone']) : null,
                'address' => filled($data['address'] ?? null) ? trim($data['address']) : null,
                'country_id' => $data['country_id'] ?? null,
                'zipcode' => filled($data['zipcode'] ?? null) ? trim($data['zipcode']) : null,
                'ntn_no' => filled($data['ntn_no'] ?? null) ? trim($data['ntn_no']) : null,
                'strn_no' => filled($data['strn_no'] ?? null) ? trim($data['strn_no']) : null,
                'first_name' => trim($data['first_name']),
                'last_name' => trim((string) ($data['last_name'] ?? '')),
                'username' => $username,
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
