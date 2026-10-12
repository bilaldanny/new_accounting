<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A company's security settings: the maker-checker switch and the password policy. A company with no row, or a database
 * without the table, gets exactly the behaviour the application had before (nothing enforced).
 */
class SecuritySettings
{
    public static function available(): bool
    {
        return Schema::hasTable('security_settings');
    }

    /**
     * @return array{maker_checker: bool, policy_enabled: bool, min_length: int, require_number: bool, require_mixed_case: bool, require_symbol: bool, expiry_days: int, history_count: int}
     */
    public static function for(?int $companyId): array
    {
        $defaults = ['maker_checker' => false, 'policy_enabled' => false, 'min_length' => 8, 'require_number' => false, 'require_mixed_case' => false, 'require_symbol' => false, 'expiry_days' => 0, 'history_count' => 0];

        if ($companyId === null || ! self::available()) {
            return $defaults;
        }

        $row = DB::table('security_settings')->where('company_id', $companyId)->first();

        if ($row === null) {
            return $defaults;
        }

        return [
            'maker_checker' => (bool) $row->maker_checker, 'policy_enabled' => (bool) $row->policy_enabled, 'min_length' => (int) $row->min_length,
            'require_number' => (bool) $row->require_number, 'require_mixed_case' => (bool) $row->require_mixed_case, 'require_symbol' => (bool) $row->require_symbol,
            'expiry_days' => (int) $row->expiry_days, 'history_count' => (int) $row->history_count,
        ];
    }
}
