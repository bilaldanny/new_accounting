<?php

use App\Models\Contact;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Helpers for the party report tests (customer and supplier outstanding, the customer & supplier
 * summary, customer groups and aging). They build on the transaction report helpers
 * (trpScope, trpDoc, trpPayment): documents and payments are inserted straight into the tables so
 * every amount, status and date is known.
 */

/**
 * A contact of the scope. It has no pay term and no chart-of-accounts account unless asked.
 *
 * @param  array{company_id: int, branch_id: int}  $scope
 * @param  array<string, mixed>  $attributes
 */
function prpContact(array $scope, string $userType, string $name, array $attributes = []): int
{
    static $sequence = 0;
    $sequence++;

    return Contact::query()->create(array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'business_name' => $name,
        'first_name' => '',
        'last_name' => '',
        'mobile' => '03007654321',
        'address' => 'Contact address',
        'code' => 'PR-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
        'user_type' => $userType,
        'type' => 'local',
        'ntn_number' => '1234567',
        'pay_term' => null,
        'pay_type' => 'day',
        'credit_limit' => 0,
        'active' => true,
    ], $attributes))->id;
}

/**
 * A contact with a chart-of-accounts account, so the ledger can post and read journal lines for it.
 *
 * @param  array{company_id: int, branch_id: int}  $scope
 * @param  array<string, mixed>  $attributes
 * @return array{id: int, coa_id: int, code: string}
 */
function prpLinkedContact(array $scope, string $userType, string $code, string $name, array $attributes = []): array
{
    $isCustomer = $userType === 'customer';

    $coaId = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'code' => $code,
        'name' => $name,
        'acc_type' => 't',
        'acc_nature' => $isCustomer ? 'dr' : 'cr',
        'bs' => 1,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $id = prpContact($scope, $userType, $name, array_merge([
        'link_account' => true,
        'gl_id' => $code,
        'customer_gl_id' => $isCustomer ? $code : null,
        'supplier_gl_id' => $isCustomer ? null : $code,
    ], $attributes));

    return ['id' => $id, 'coa_id' => $coaId, 'code' => $code];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function prpFinancialYear(int $companyId, string $start = '2026-07-01', string $end = '2027-07-01', array $attributes = []): int
{
    return DB::table('financial_years')->insertGetId(array_merge([
        'company_id' => $companyId,
        'name' => 'FY '.$start,
        'start_date' => $start,
        'end_date' => $end,
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
}

/**
 * @param  array{company_id: int, branch_id: int}  $scope
 */
function prpOpeningBalance(array $scope, int $coaId, float $amount, string $nature): void
{
    DB::table('account_balances')->insert([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'financial_id' => DB::table('financial_years')->where('company_id', $scope['company_id'])->value('id'),
        'coa_id' => $coaId,
        'opening_balance' => $amount,
        'acc_nature' => $nature,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * One approved voucher with a single line on a contact's account.
 *
 * @param  array{company_id: int, branch_id: int}  $scope
 */
function prpJournalLine(array $scope, string $code, int $contactId, float $debit, float $credit, string $date, string $nature): void
{
    static $sequence = 0;
    $sequence++;

    $voucherId = DB::table('t_accounts')->insertGetId([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'account_code' => $code,
        'voucher_no' => 'JV-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
        'ref_no' => '',
        'cheque_no' => '',
        'comments' => 'Party report journal',
        'voucher_date' => $date,
        'status' => 'approved',
        'type' => 'cash',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('t_account_details')->insert([
        't_account_id' => $voucherId,
        'branch_id' => $scope['branch_id'],
        'contact_id' => $contactId,
        'account_code' => $code,
        'description' => 'Party report journal',
        'acc_nature' => $nature,
        'credit' => $credit,
        'debit' => $debit,
        'highlight' => 0,
        'amount' => max($debit, $credit),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array{company_id: int, branch_id: int}  $scope
 */
function prpGroup(array $scope, string $name): int
{
    return DB::table('customer_groups')->insertGetId([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'name' => $name,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $query
 */
function prpGet(string $report, array $query = []): TestResponse
{
    $suffix = $query === [] ? '' : '?'.http_build_query($query);

    return test()->getJson("/api/reports/{$report}{$suffix}");
}

/**
 * The rows on the returned page.
 *
 * @return Collection<int, array<string, mixed>>
 */
function prpRows(TestResponse $response): Collection
{
    return collect($response->json('data.data'));
}

/**
 * The row of one contact, or group, by its id.
 *
 * @return array<string, mixed>|null
 */
function prpRow(TestResponse $response, int $id): ?array
{
    return prpRows($response)->firstWhere('id', $id);
}
