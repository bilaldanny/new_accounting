<?php

namespace App\Services;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Bulk create of customers or suppliers from spreadsheet rows. Every row goes through the same
 * Contact::createCustomer() / createSupplier() the add form uses, so numbering, the chart-of-accounts link
 * and the opening balance are posted by the one existing path; this class adds only row validation and
 * duplicate detection (same email, mobile or business name as a contact the company already has, which is
 * what the Duplicate Contacts page groups on).
 *
 * A run is always executed for real inside a transaction: a check ("dry run") rolls it back, an import
 * commits only when no row failed. A duplicate row is skipped and reported, not an error.
 */
class ContactImporter
{
    public const MAX_ROWS = 1000;

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{ok: bool, dry_run: bool, summary: array{total: int, created: int, updated: int, duplicates: int, failed: int}, rows: list<array<string, mixed>>}
     */
    public function run(string $kind, array $rows, int $companyId, int $branchId, bool $dryRun, bool $allowDuplicates = false): array
    {
        $results = [];

        DB::beginTransaction();

        try {
            foreach ($rows as $index => $row) {
                $results[] = $this->importRow($kind, is_array($row) ? $row : [], $index + 1, $companyId, $branchId, $allowDuplicates);
            }

            $failed = count(array_filter($results, fn (array $result): bool => $result['status'] === 'error'));

            if ($dryRun || $failed > 0) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $count = fn (string $status): int => count(array_filter($results, fn (array $result): bool => $result['status'] === $status));

        return [
            'ok' => $failed === 0,
            'dry_run' => $dryRun,
            'summary' => [
                'total' => count($rows),
                'created' => $count('created'),
                'updated' => 0,
                'duplicates' => $count('duplicate'),
                'failed' => $failed,
            ],
            'rows' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{row: int, status: string, name: string, errors: list<string>, duplicate_of: ?string}
     */
    private function importRow(string $kind, array $row, int $number, int $companyId, int $branchId, bool $allowDuplicates): array
    {
        $row = array_map(fn (mixed $value): mixed => is_string($value) ? trim($value) : (is_int($value) || is_float($value) ? (string) $value : $value), $row);
        $name = (string) ($row['business_name'] ?? $row['first_name'] ?? '');
        $result = ['row' => $number, 'status' => 'error', 'name' => $name, 'errors' => [], 'duplicate_of' => null];

        $validator = Validator::make($row, [
            'business_name' => 'bail|required|string|max:255',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'mobile' => 'bail|required|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:1000',
            'ntn_number' => 'nullable|string|max:255',
            'strn_number' => 'nullable|string|max:255',
            'code' => 'nullable|string|max:255',
            'opening_balance' => 'nullable|numeric',
            'credit_limit' => 'nullable|numeric|min:0',
            'pay_term' => 'nullable|numeric|min:0',
            'pay_type' => 'nullable|in:month,day,year',
            'type' => 'nullable|in:local,export',
        ]);

        if ($validator->fails()) {
            $result['errors'] = $validator->errors()->all();

            return $result;
        }

        if (! $allowDuplicates && ($duplicate = $this->findDuplicate($companyId, $row)) !== null) {
            $result['status'] = 'duplicate';
            $result['duplicate_of'] = $duplicate;

            return $result;
        }

        $payload = (object) [
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'business_name' => $row['business_name'],
            'first_name' => filled($row['first_name'] ?? null) ? $row['first_name'] : $row['business_name'],
            'last_name' => $row['last_name'] ?? null,
            'mobile' => $row['mobile'],
            'email' => filled($row['email'] ?? null) ? $row['email'] : null,
            'address' => $row['address'] ?? '',
            'ntn_number' => $row['ntn_number'] ?? '',
            'strn_number' => $row['strn_number'] ?? null,
            'code' => $row['code'] ?? null,
            'opening_balance' => (float) ($row['opening_balance'] ?? 0),
            'credit_limit' => (float) ($row['credit_limit'] ?? 0),
            'pay_term' => filled($row['pay_term'] ?? null) ? $row['pay_term'] : null,
            'pay_type' => $row['pay_type'] ?? 'day',
            'type' => $row['type'] ?? 'local',
            'user_type' => $kind === 'supplier' ? 'supplier' : 'customer',
            'active' => true,
        ];

        try {
            DB::transaction(function () use ($kind, $payload): void {
                $contact = $kind === 'supplier' ? Contact::createSupplier($payload) : Contact::createCustomer($payload);
                $glId = $kind === 'supplier' ? $contact->supplier_gl_id : $contact->customer_gl_id;

                if (round((float) $payload->opening_balance, 2) !== 0.0 && blank($glId)) {
                    throw ValidationException::withMessages([
                        'opening_balance' => ['An opening balance needs the '.$kind.' account mapping, which is not set for this branch.'],
                    ]);
                }
            });
        } catch (ValidationException $e) {
            $result['errors'] = collect($e->errors())->flatten()->values()->all();

            return $result;
        } catch (Throwable $e) {
            $result['errors'] = [$e->getMessage()];

            return $result;
        }

        $result['status'] = 'created';

        return $result;
    }

    /**
     * Describes the contact of this company (any branch, so a second branch cannot recreate it) that shares
     * the row's email, mobile or business name, or null when there is none.
     *
     * @param  array<string, mixed>  $row
     */
    private function findDuplicate(int $companyId, array $row): ?string
    {
        foreach (['email', 'mobile', 'business_name'] as $field) {
            $value = strtolower(trim((string) ($row[$field] ?? '')));

            if ($value === '') {
                continue;
            }

            $match = Contact::query()
                ->where('company_id', $companyId)
                ->whereRaw('lower(trim('.$field.')) = ?', [$value])
                ->orderBy('id')
                ->first(['id', 'code', 'business_name']);

            if ($match !== null) {
                return sprintf('%s (%s) already has this %s', $match->business_name, $match->code, str_replace('_', ' ', $field));
            }
        }

        return null;
    }
}
