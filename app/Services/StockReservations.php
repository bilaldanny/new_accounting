<?php

namespace App\Services;

use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What is held back for others. A reservation lowers the quantity a sale may take only when the company's
 * `reservation_mode` is `enforce`; with it unset or `off` (the default) reservations are a record and the stock check is
 * exactly what it was. A customer's own reservation never blocks a sale to that customer.
 */
class StockReservations
{
    private static ?bool $tracked = null;

    public static function supported(): bool
    {
        return self::$tracked ??= Schema::hasTable('stock_reservations') && Schema::hasColumn('company_settings', 'reservation_mode');
    }

    public static function forgetSchemaMemo(): void
    {
        self::$tracked = null;
    }

    public static function enforced(?int $companyId): bool
    {
        if ($companyId === null || ! self::supported()) {
            return false;
        }

        return DB::table('company_settings')->where('company_id', $companyId)->value('reservation_mode') === 'enforce';
    }

    /**
     * Base units held for others on this variation in the branch (a reservation with no branch holds in every branch).
     */
    public function heldForOthers(int $companyId, int $productId, int $variationId, ?int $branchId, ?int $contactId): float
    {
        if (! self::supported()) {
            return 0.0;
        }

        return round((float) StockReservation::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->where('product_id', $productId)
            ->where(fn ($q) => $q->whereNull('variation_id')->orWhere('variation_id', $variationId))
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', now()->toDateString()))
            ->when($branchId !== null, fn ($q) => $q->where(fn ($b) => $b->whereNull('branch_id')->orWhere('branch_id', $branchId)))
            ->when($contactId !== null, fn ($q) => $q->where(fn ($c) => $c->whereNull('contact_id')->orWhere('contact_id', '!=', $contactId)))
            ->sum('quantity'), 6);
    }
}
