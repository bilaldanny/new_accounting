<?php

namespace App\Services\Saas;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The smaller tenant-side pieces of the SaaS billing wave: the onboarding checklist, add-ons billed with the plan, partner
 * commission statements and custom domain mapping.
 */
class TenantServices
{
    /**
     * The first-week checklist of a company, each step with whether it is done, computed from what exists.
     *
     * @return array{items: list<array{key: string, label: string, done: bool}>, done: int, total: int}
     */
    public function onboarding(int $companyId): array
    {
        $has = fn (string $table, array $where = []): bool => Schema::hasTable($table) && DB::table($table)->where('company_id', $companyId)->where($where)->exists();
        $company = DB::table('companies')->where('id', $companyId)->first();
        $items = [
            ['company_profile', 'Fill in the company name, phone and address', filled($company->name ?? null) && filled($company->phone ?? null) && filled($company->address ?? null)],
            ['branch', 'Add a branch', $has('branches')],
            ['users', 'Invite a second user', DB::table('users')->where('company_id', $companyId)->count() > 1],
            ['accounts', 'Set up the chart of accounts', $has('chart_of_accounts')],
            ['financial_year', 'Set the financial year', $has('financial_years')],
            ['products', 'Add your first product', $has('products')],
            ['customers', 'Add a customer', $has('contacts', ['user_type' => 'customer'])],
            ['suppliers', 'Add a supplier', $has('contacts', ['user_type' => 'supplier'])],
            ['first_purchase', 'Record a purchase', $has('transactions', ['type' => 'purchaseorder'])],
            ['first_sale', 'Make your first sale', $has('transactions', ['type' => 'sell'])],
        ];
        $rows = array_map(fn (array $i): array => ['key' => $i[0], 'label' => $i[1], 'done' => (bool) $i[2]], $items);

        return ['items' => $rows, 'done' => count(array_filter($rows, fn (array $r): bool => $r['done'])), 'total' => count($rows)];
    }

    /**
     * What the company's active add-ons add to an invoice for a period.
     */
    public function addonsTotal(int $companyId, string $periodStart, string $periodEnd): float
    {
        if (! Schema::hasTable('tenant_addons')) {
            return 0.0;
        }

        return round((float) DB::table('tenant_addons as t')->join('addons as a', 'a.id', '=', 't.addon_id')
            ->where('t.company_id', $companyId)->where('a.is_active', true)
            ->whereDate('t.starts_on', '<=', $periodEnd)
            ->where(fn ($q) => $q->whereNull('t.ends_on')->orWhereDate('t.ends_on', '>=', $periodStart))
            ->selectRaw('coalesce(sum(a.price * t.quantity), 0) as total')->value('total'), 2);
    }

    /**
     * Commission earned by a partner on the subscription invoices its companies paid in a period.
     *
     * @return array{partner: object, rows: list<array<string, mixed>>, base: float, commission: float}
     */
    public function commissionStatement(int $partnerId, string $from, string $to): array
    {
        $partner = DB::table('partners')->where('id', $partnerId)->first();
        abort_if($partner === null, 404);

        $rows = DB::table('subscription_invoices as i')->join('companies as c', 'c.id', '=', 'i.company_id')
            ->where('c.partner_id', $partnerId)->where('i.status', 'paid')
            ->whereDate('i.paid_at', '>=', $from)->whereDate('i.paid_at', '<=', $to)
            ->orderBy('i.paid_at')->get(['i.invoice_no', 'c.name as company', 'i.paid_amount', 'i.paid_at'])
            ->map(fn (object $r): array => [
                'invoice_no' => $r->invoice_no, 'company' => $r->company, 'paid_amount' => round((float) $r->paid_amount, 2), 'paid_at' => (string) $r->paid_at,
                'commission' => round((float) $r->paid_amount * (float) $partner->commission_percent / 100, 2),
            ])->all();

        return [
            'partner' => $partner, 'rows' => $rows, 'base' => round(array_sum(array_column($rows, 'paid_amount')), 2),
            'commission' => round(array_sum(array_column($rows, 'commission')), 2),
        ];
    }

    /**
     * Maps a custom domain to a company: stored with a verification token the owner puts in a DNS TXT record.
     *
     * @throws ValidationException
     */
    public function addDomain(int $companyId, string $domain): object
    {
        $domain = strtolower(trim($domain));

        if (! preg_match('/^(?=.{4,190}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
            throw ValidationException::withMessages(['domain' => ['Enter a domain name such as erp.example.com.']]);
        }

        if (DB::table('custom_domains')->where('domain', $domain)->exists()) {
            throw ValidationException::withMessages(['domain' => ['This domain is already mapped.']]);
        }

        $id = DB::table('custom_domains')->insertGetId([
            'company_id' => $companyId, 'domain' => $domain, 'verification_token' => 'erp-verify-'.Str::lower(Str::random(24)), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('custom_domains')->find($id);
    }

    /**
     * Verifies a domain: the TXT record `_erp-verify.<domain>` must hold the token. `$lookup` is the DNS reader (injectable).
     *
     * @param  callable(string): list<string>|null  $lookup
     */
    public function verifyDomain(object $row, ?callable $lookup = null): bool
    {
        $lookup ??= function (string $host): array {
            $records = @dns_get_record($host, DNS_TXT) ?: [];

            return array_map(fn (array $r): string => (string) ($r['txt'] ?? ''), $records);
        };

        $ok = in_array($row->verification_token, $lookup('_erp-verify.'.$row->domain), true);

        if ($ok) {
            DB::table('custom_domains')->where('id', $row->id)->update(['verified_at' => now(), 'updated_at' => now()]);
        }

        return $ok;
    }

    /**
     * The branding of the company a verified custom domain points to (for the sign-in page), or null.
     *
     * @return array{company_id: int, name: string, color: string|null, logo: string|null, hide_powered_by: bool}|null
     */
    public function brandingForHost(string $host): ?array
    {
        if (! Schema::hasTable('custom_domains')) {
            return null;
        }

        $row = DB::table('custom_domains as d')->join('companies as c', 'c.id', '=', 'd.company_id')
            ->where('d.domain', strtolower($host))->whereNotNull('d.verified_at')->where('c.is_active', true)
            ->first(['c.id', 'c.name', 'c.brand_name', 'c.brand_color', 'c.logo', 'c.hide_powered_by']);

        return $row === null ? null : [
            'company_id' => (int) $row->id, 'name' => (string) ($row->brand_name ?: $row->name), 'color' => $row->brand_color, 'logo' => $row->logo, 'hide_powered_by' => (bool) $row->hide_powered_by,
        ];
    }
}
