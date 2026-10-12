<?php

namespace App\Http\Middleware;

use App\Services\Security\IpRules;
use App\Services\Security\SecuritySettings;
use App\Services\Security\SessionRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * The runtime checks of the Security Center, for a signed-in user (the superadmin is never locked out by a company rule):
 *
 *  1. the company's IP allow / deny list;
 *  2. a session the user (or an admin) revoked is signed out at the next request, and the session is kept in the device list;
 *  3. a password older than the company's expiry must be changed first (only the password page and logout stay open);
 *  4. maker-checker: with the switch on, nobody approves a document they created themselves.
 *
 * A company with no security setting, and a database without the tables, pass through untouched.
 */
class EnforceSecurity
{
    /**
     * Approval route prefix => [table, column holding the person who made the record].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const MAKER_COLUMNS = [
        'journal-entry-approvals' => ['t_accounts', 'created_by'], 'payment-approvals' => ['t_accounts', 'created_by'],
        'expense-approvals' => ['t_accounts', 'created_by'], 'deposit-approvals' => ['t_accounts', 'created_by'],
        'fund-transfer-approvals' => ['t_accounts', 'created_by'], 'credit-debit-note-approvals' => ['t_accounts', 'created_by'],
        'purchase-approvals' => ['transactions', 'created_by'], 'sell-approvals' => ['transactions', 'created_by'],
        'purchase-return-approvals' => ['transactions', 'created_by'], 'stock-adjustment-approvals' => ['transactions', 'created_by'],
        'stock-transfer-approvals' => ['transactions', 'created_by'], 'cash-collection-approvals' => ['cash_collections', 'collected_by'],
        'asset-approvals' => ['fixed_assets', 'created_by'], 'pricelist-approvals' => ['price_lists', 'created_by'],
        'credit-limit-approvals' => ['credit_limit_requests', 'requested_by'], 'purchase-requisition-approvals' => ['purchase_requisitions', 'requested_by'],
        'sales-orders' => ['sales_orders', 'created_by'], 'leave-requests' => ['leave_requests', 'created_by'], 'payroll-runs' => ['payroll_runs', 'created_by'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->hasRole('superadmin') || $user->company_id === null) {
            return $next($request);
        }

        $companyId = (int) $user->company_id;

        if (IpRules::available() && ! app(IpRules::class)->allows($companyId, (string) $request->ip())) {
            $this->recordBlocked($user->id, $companyId, $request);

            return response()->json(['message' => 'Your IP address is not allowed to use this company.', 'ip_blocked' => true], 403);
        }

        if ($request->hasSession() && SessionRegistry::available()) {
            if (! app(SessionRegistry::class)->touch($request, $user)) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $request->expectsJson()
                    ? response()->json(['message' => 'This session was ended by an administrator.', 'session_revoked' => true], 401)
                    : redirect('/login');
            }
        }

        $policy = SecuritySettings::for($companyId);

        if ($policy['policy_enabled'] && $policy['expiry_days'] > 0 && $user->password_changed_at !== null
            && Carbon::parse($user->password_changed_at)->addDays($policy['expiry_days'])->isPast()
            && ! $request->is('settings/password', 'api/settings/password', 'logout', 'api/logout')) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Your password has expired. Change it to continue.', 'password_expired' => true], 403)
                : redirect('/settings/password');
        }

        if ($policy['maker_checker'] && $request->isMethod('POST') && ($blocked = $this->makerIsChecker($request, (int) $user->id)) !== null) {
            return response()->json(['message' => 'Maker-checker: you cannot approve a document you created yourself.', 'errors' => ['approval' => [$blocked]]], 422);
        }

        return $next($request);
    }

    private function makerIsChecker(Request $request, int $userId): ?string
    {
        $path = $request->path();

        if (! preg_match('#^api/([a-z-]+)/(\d+)/approve$#', $path, $match) || ! isset(self::MAKER_COLUMNS[$match[1]])) {
            return null;
        }

        [$table, $column] = self::MAKER_COLUMNS[$match[1]];
        $maker = DB::table($table)->where('id', (int) $match[2])->value($column);

        return $maker !== null && (int) $maker === $userId ? 'The creator of this document cannot be its approver.' : null;
    }

    private function recordBlocked(int $userId, int $companyId, Request $request): void
    {
        if (Schema::hasTable('login_events')) {
            DB::table('login_events')->insert([
                'company_id' => $companyId, 'user_id' => $userId, 'event' => 'ip_blocked', 'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255), 'created_at' => now(),
            ]);
        }
    }
}
