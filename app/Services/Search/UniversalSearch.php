<?php

namespace App\Services\Search;

use App\Models\Menu;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The search bar of the top bar: one query over the pages the user may open, customers, suppliers, products, sales, purchases and
 * journal vouchers. Each group is searched only when the user holds the menu permission of its list page, only inside the company
 * (and, for a branch user, the branch) of the signed-in user, and returns at most a handful of rows with the address of the page that
 * shows them. Terms are bound, never concatenated; `%` and `_` typed by the user are matched literally.
 */
class UniversalSearch
{
    public const PER_GROUP = 5;

    /**
     * @return list<array{group: string, label: string, hint: string, url: string}>
     */
    public function search(User $user, string $term, ?int $companyId, ?int $branchId): array
    {
        $term = trim(mb_substr($term, 0, 60));

        if (mb_strlen($term) < 2) {
            return [];
        }

        $like = '%'.addcslashes($term, '\\%_').'%';
        $results = $this->pages($user, $like);
        $allowed = fn (string $path): bool => $this->may($user, $path);

        if ($companyId === null) {
            return $results;
        }

        if ($allowed('/customer') || $allowed('/supplier')) {
            $kinds = array_values(array_filter([$allowed('/customer') ? 'customer' : null, $allowed('/supplier') ? 'supplier' : null]));

            foreach (DB::table('contacts')->where('company_id', $companyId)->whereNull('deleted_at')
                ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
                ->where(fn ($q) => $q->where('business_name', 'like', $like)->orWhere('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('mobile', 'like', $like))
                ->orderBy('business_name')->limit(self::PER_GROUP * 2)->get(['id', 'business_name', 'first_name', 'last_name', 'code', 'user_type']) as $contact) {
                $kind = $contact->user_type === 'supplier' ? 'supplier' : 'customer';

                if (! in_array($kind, $kinds, true) && $contact->user_type !== 'both') {
                    continue;
                }

                $kind = in_array($kind, $kinds, true) ? $kind : $kinds[0];
                $results[] = ['group' => ucfirst($kind), 'label' => trim((string) ($contact->business_name ?: $contact->first_name.' '.$contact->last_name)), 'hint' => (string) $contact->code, 'url' => "/{$kind}/{$contact->id}/view"];
            }
        }

        if ($allowed('/product')) {
            foreach (DB::table('products')->where('company_id', $companyId)->whereNull('deleted_at')->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('sku', 'like', $like))
                ->orderBy('name')->limit(self::PER_GROUP)->get(['id', 'name', 'sku']) as $product) {
                $results[] = ['group' => 'Product', 'label' => (string) $product->name, 'hint' => (string) $product->sku, 'url' => "/product/{$product->id}/edit"];
            }
        }

        foreach ([['/sell', 'sell', 'Sale', 'sell'], ['/purchase', 'purchase', 'Purchase', 'purchase']] as [$path, $type, $group, $base]) {
            if (! $allowed($path)) {
                continue;
            }

            foreach (DB::table('transactions')->where('company_id', $companyId)->where('type', $type)->whereNull('deleted_at')
                ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
                ->where(fn ($q) => $q->where('invoice_no', 'like', $like)->orWhere('sup_ref_no', 'like', $like))
                ->orderByDesc('id')->limit(self::PER_GROUP)->get(['id', 'invoice_no', 'final_amount', 'transaction_date']) as $row) {
                $results[] = ['group' => $group, 'label' => (string) $row->invoice_no, 'hint' => substr((string) $row->transaction_date, 0, 10).' - '.number_format((float) $row->final_amount, 2), 'url' => "/{$base}/{$row->id}/view"];
            }
        }

        if ($allowed('/journalentry')) {
            foreach (DB::table('t_accounts')->where('company_id', $companyId)->where(fn ($q) => $q->where('voucher_no', 'like', $like)->orWhere('ref_no', 'like', $like))
                ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))->orderByDesc('id')->limit(self::PER_GROUP)->get(['id', 'voucher_no', 'voucher_date']) as $voucher) {
                $results[] = ['group' => 'Voucher', 'label' => (string) $voucher->voucher_no, 'hint' => substr((string) $voucher->voucher_date, 0, 10), 'url' => "/journalentry/{$voucher->id}/view"];
            }
        }

        return $results;
    }

    /**
     * @return list<array{group: string, label: string, hint: string, url: string}>
     */
    private function pages(User $user, string $like): array
    {
        $rows = Menu::query()->where('is_hidden', 0)->whereNotNull('route_path')->where('route_path', '!=', '')->where('route_path', 'not like', '%:id%')->where('name', 'like', $like)
            ->orderBy('name')->limit(40)->get(['name', 'route_path']);
        $out = [];

        foreach ($rows as $row) {
            if (! $this->may($user, (string) $row->route_path)) {
                continue;
            }

            $out[] = ['group' => 'Page', 'label' => (string) $row->name, 'hint' => (string) $row->route_path, 'url' => (string) $row->route_path];

            if (count($out) >= self::PER_GROUP + 3) {
                break;
            }
        }

        return $out;
    }

    private function may(User $user, string $path): bool
    {
        return $user->hasRole('superadmin') || (int) $user->role_id === 1 || hasMenuPermission($path);
    }
}
