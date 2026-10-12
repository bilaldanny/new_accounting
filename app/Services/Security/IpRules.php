<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A company's IP allow / deny list. An address matching any deny rule is refused. When the company has allow rules, only
 * addresses matching one of them are accepted. With no rules (the default) every address is accepted. Rules are an address or a
 * CIDR block (IPv4 ranges, IPv6 exact or prefix).
 */
class IpRules
{
    public static function available(): bool
    {
        return Schema::hasTable('ip_rules');
    }

    public function allows(?int $companyId, string $ip): bool
    {
        if ($companyId === null || ! self::available()) {
            return true;
        }

        $rules = DB::table('ip_rules')->where('company_id', $companyId)->get(['type', 'address']);

        if ($rules->isEmpty()) {
            return true;
        }

        foreach ($rules->where('type', 'deny') as $rule) {
            if (self::matches($ip, (string) $rule->address)) {
                return false;
            }
        }

        $allow = $rules->where('type', 'allow');

        return $allow->isEmpty() || $allow->contains(fn (object $rule): bool => self::matches($ip, (string) $rule->address));
    }

    public static function matches(string $ip, string $rule): bool
    {
        if (! str_contains($rule, '/')) {
            return strcasecmp($ip, $rule) === 0;
        }

        [$subnet, $bits] = explode('/', $rule, 2);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin) || ! ctype_digit($bits)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bits > strlen($ipBin) * 8) {
            return false;
        }

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }
}
