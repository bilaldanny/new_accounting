<?php

namespace App\Rules;

use App\Models\User;
use App\Services\Security\SecuritySettings;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The company's configurable password policy (Security Center): minimum length, a number, mixed case, a symbol, and not one of the
 * last N passwords of the user. With the policy switched off (the default) it passes everything, so the application's own rules
 * stay exactly as they were.
 */
class CompanyPasswordPolicy implements ValidationRule
{
    public function __construct(private readonly ?int $companyId, private readonly ?User $user = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $policy = SecuritySettings::for($this->companyId);

        if (! $policy['policy_enabled'] || ! is_string($value)) {
            return;
        }

        if (mb_strlen($value) < $policy['min_length']) {
            $fail("The password must be at least {$policy['min_length']} characters.");
        }

        if ($policy['require_number'] && ! preg_match('/\d/', $value)) {
            $fail('The password must contain a number.');
        }

        if ($policy['require_mixed_case'] && (! preg_match('/[a-z]/', $value) || ! preg_match('/[A-Z]/', $value))) {
            $fail('The password must contain upper and lower case letters.');
        }

        if ($policy['require_symbol'] && ! preg_match('/[^A-Za-z0-9]/', $value)) {
            $fail('The password must contain a symbol.');
        }

        if ($policy['history_count'] > 0 && $this->user !== null) {
            $recent = DB::table('password_histories')->where('user_id', $this->user->id)->orderByDesc('id')->limit($policy['history_count'])->pluck('hash');

            foreach ($recent as $hash) {
                if (Hash::check($value, (string) $hash)) {
                    $fail("The password cannot be one of the last {$policy['history_count']} passwords used.");

                    return;
                }
            }
        }
    }
}
