<?php

namespace App\Services\System;

use App\Models\Contact;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The log of what imports created, and the undo of an import that only created records. An undo removes (soft deletes, the same
 * delete the contact page uses) the contacts that run created, and only those that are still untouched: a contact that has since
 * got a sale, a purchase, a payment or a voucher line is left in place and reported. Existing records are never altered by an
 * import (the importer skips duplicates), so there is nothing else to restore.
 */
class ImportHistory
{
    public static function available(): bool
    {
        return Schema::hasTable('import_runs');
    }

    /**
     * @param  list<int>  $createdIds
     * @param  array<string, mixed>  $summary
     */
    public function record(int $companyId, ?int $userId, string $entity, array $createdIds, array $summary, string $source = 'file', ?string $fileName = null): ?int
    {
        if (! self::available() || $createdIds === []) {
            return null;
        }

        return (int) DB::table('import_runs')->insertGetId([
            'company_id' => $companyId, 'user_id' => $userId, 'entity' => $entity, 'source' => $source, 'file_name' => $fileName !== null ? mb_substr($fileName, 0, 190) : null,
            'summary' => json_encode($summary), 'created_ids' => json_encode(array_values($createdIds)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @return array{undone: int, kept: list<array{id: int, reason: string}>}
     */
    public function undo(int $companyId, int $runId, ?int $userId): array
    {
        $run = DB::table('import_runs')->where('company_id', $companyId)->where('id', $runId)->first();
        abort_if($run === null, 404);

        if ($run->status !== 'active') {
            return ['undone' => 0, 'kept' => []];
        }

        $undone = 0;
        $kept = [];

        if ($run->entity === 'products') {
            return $this->undoProducts($run, $companyId, $userId);
        }

        foreach ((array) json_decode((string) $run->created_ids, true) as $id) {
            $contact = Contact::withTrashed()->withoutGlobalScopes()->where('company_id', $companyId)->find((int) $id);

            if ($contact === null || $contact->trashed()) {
                continue;
            }

            $reason = $this->inUse((int) $id);

            if ($reason !== null) {
                $kept[] = ['id' => (int) $id, 'reason' => $reason];

                continue;
            }

            $contact->delete();
            $undone++;
        }

        DB::table('import_runs')->where('id', $run->id)->update(['status' => $kept === [] ? 'undone' : 'partly_undone', 'undone_at' => now(), 'undone_by' => $userId, 'updated_at' => now()]);

        return ['undone' => $undone, 'kept' => $kept];
    }

    /**
     * Removes the products a product import created that nothing has used since: a product on a sale, purchase, stock adjustment,
     * opening stock line or component list stays.
     *
     * @return array{undone: int, kept: list<array{id: int, reason: string}>}
     */
    private function undoProducts(object $run, int $companyId, ?int $userId): array
    {
        $undone = 0;
        $kept = [];

        foreach ((array) json_decode((string) $run->created_ids, true) as $id) {
            $product = Product::withTrashed()->withoutGlobalScopes()->where('company_id', $companyId)->find((int) $id);

            if ($product === null || $product->trashed()) {
                continue;
            }

            $reason = match (true) {
                DB::table('sell_lines')->where('product_id', $id)->exists() => 'it is on a sale',
                DB::table('purchase_lines')->where('product_id', $id)->exists() => 'it is on a purchase, stock adjustment or opening stock',
                Schema::hasTable('product_components') && DB::table('product_components')->where(fn ($q) => $q->where('product_id', $id)->orWhere('component_product_id', $id))->exists() => 'it is in a component list',
                default => null,
            };

            if ($reason !== null) {
                $kept[] = ['id' => (int) $id, 'reason' => $reason];

                continue;
            }

            $product->delete();
            $undone++;
        }

        DB::table('import_runs')->where('id', $run->id)->update(['status' => $kept === [] ? 'undone' : 'partly_undone', 'undone_at' => now(), 'undone_by' => $userId, 'updated_at' => now()]);

        return ['undone' => $undone, 'kept' => $kept];
    }

    private function inUse(int $contactId): ?string
    {
        if (DB::table('transactions')->where('contact_id', $contactId)->whereNull('deleted_at')->exists()) {
            return 'it has sales or purchases';
        }

        if (DB::table('payments')->where('contact_id', $contactId)->exists()) {
            return 'it has payments';
        }

        if (DB::table('t_account_details')->where('contact_id', $contactId)->where(fn ($q) => $q->where('debit', '>', 0)->orWhere('credit', '>', 0))->exists()) {
            return 'it has accounting entries (for example an opening balance)';
        }

        return null;
    }
}
