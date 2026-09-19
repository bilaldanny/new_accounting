<?php

use App\Models\Contact;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * A customer or supplier with an opening balance, two documents and one payment, in the fallback
 * (no journals) ledger mode. Customer: 2,500 opening + 5,000 + 1,200 sold - 1,000 paid = 7,700.
 * Supplier: 10,000 opening + 23,000 + 4,000 bought - 5,000 paid = 32,000.
 *
 * @return array{contact: Contact, company_id: int, branch_id: int, closing: float, debit: float, credit: float, opening: float}
 */
function seedParityContact(string $party): array
{
    $isCustomer = $party === 'customer';
    $code = $isCustomer ? '101-00020' : '311-00020';

    $companyId = DB::table('companies')->insertGetId([
        'code' => 'PAR'.($isCustomer ? 'C' : 'S').'01', 'name' => 'Parity Company', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $branchId = DB::table('branches')->insertGetId([
        'code' => 'PARB'.($isCustomer ? 'C' : 'S'), 'company_id' => $companyId, 'name' => 'Parity Branch', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $financialYearId = DB::table('financial_years')->insertGetId([
        'company_id' => $companyId, 'name' => 'FY 2026', 'start_date' => '2026-07-01', 'end_date' => '2027-07-01', 'status' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $coaId = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $companyId, 'branch_id' => $branchId, 'code' => $code, 'name' => 'Parity Account', 'acc_type' => 't',
        'acc_nature' => $isCustomer ? 'dr' : 'cr', 'bs' => 1, 'active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $contact = Contact::query()->create([
        'company_id' => $companyId, 'branch_id' => $branchId, 'business_name' => 'Parity '.ucfirst($party), 'first_name' => 'Pat',
        'mobile' => '03001234567', 'address' => 'Test address', 'code' => ($isCustomer ? 'CU-' : 'SU-').$coaId, 'user_type' => $party,
        'type' => 'local', 'ntn_number' => '1234567', 'active' => true, 'link_account' => true,
        'supplier_gl_id' => $isCustomer ? null : $code, 'customer_gl_id' => $isCustomer ? $code : null, 'gl_id' => $code,
    ]);

    $opening = $isCustomer ? 2500.0 : 10000.0;
    DB::table('account_balances')->insert([
        'company_id' => $companyId, 'branch_id' => $branchId, 'financial_id' => $financialYearId, 'coa_id' => $coaId,
        'opening_balance' => $opening, 'acc_nature' => $isCustomer ? 'dr' : 'cr', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $type = $isCustomer ? Transaction::TYPE_SELL : Transaction::TYPE_PURCHASE;
    $amounts = $isCustomer ? [5000, 1200] : [23000, 4000];

    $documents = collect($amounts)->map(fn (int $amount, int $index) => Transaction::factory()->create([
        'company_id' => $companyId, 'branch_id' => $branchId, 'contact_id' => $contact->id, 'type' => $type,
        'invoice_no' => ($isCustomer ? 'INV-' : 'PO-').($index + 1), 'transaction_date' => '2026-08-1'.($index + 5),
        'final_amount' => $amount, 'payment_status' => 'due', 'status' => 'approved',
    ]));

    $paid = $isCustomer ? 1000 : 5000;
    DB::table('payments')->insert([
        'company_id' => $companyId, 'branch_id' => $branchId, 'transaction_id' => $documents->first()->id, 'contact_id' => $contact->id,
        'amount' => $paid, 'method' => 'cash', 'paid_on' => '2026-08-20', 'payment_ref_no' => 'PAY-1', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return [
        'contact' => $contact,
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'opening' => $opening,
        'debit' => $isCustomer ? 6200.0 : 5000.0,
        'credit' => $isCustomer ? 1000.0 : 27000.0,
        'closing' => $isCustomer ? 7700.0 : 32000.0,
    ];
}

/** The page's running balance, written the same way both view pages and the report page write it. */
function runLedgerBalance(array $ledger): float
{
    $balance = (float) $ledger['openingbalance'];

    foreach ($ledger['taccount'] as $row) {
        $debit = (float) ($row['debit'] ?? 0);
        $credit = (float) ($row['credit'] ?? 0);

        $balance = ($row['acc_nature'] ?? null) === 'cr'
            ? $balance + $credit - $debit
            : $balance + $debit - $credit;
    }

    return round($balance, 2);
}

test('the report page and the party view page read identical ledger data', function (string $party) {
    $seed = seedParityContact($party);
    Sanctum::actingAs(User::query()->findOrFail(1));

    // Both pages call GET api/fetchledger with these params (view page: reloadCustomerView/loadLedger).
    $params = http_build_query([
        'contact_id' => $seed['contact']->id,
        'company_id' => $seed['company_id'],
        'branch_id' => $seed['branch_id'],
        'start_date' => '2026-07-01',
        'end_date' => '2027-07-01',
    ]);

    $fromViewPage = $this->getJson('/api/fetchledger?'.$params)->assertOk()->json();
    $fromReportPage = $this->getJson('/api/fetchledger?'.$params)->assertOk()->json();

    expect($fromReportPage)->toBe($fromViewPage)
        ->and($fromReportPage['taccount'])->toHaveCount(3)
        ->and((float) $fromReportPage['openingbalance'])->toBe($seed['opening']);
})->with(['customer', 'supplier']);

test('the ledger totals the report page shows equal the closing balance the server reports', function (string $party) {
    $seed = seedParityContact($party);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $ledger = $this->getJson('/api/fetchledger?contact_id='.$seed['contact']->id.'&start_date=2026-07-01&end_date=2027-07-01')->assertOk()->json();

    $debit = collect($ledger['taccount'])->sum(fn (array $row) => (float) ($row['debit'] ?? 0));
    $credit = collect($ledger['taccount'])->sum(fn (array $row) => (float) ($row['credit'] ?? 0));

    expect(runLedgerBalance($ledger))->toBe($seed['closing'])
        ->and(runLedgerBalance($ledger))->toBe((float) $ledger['closingbalance'])
        ->and((float) $debit)->toBe($seed['debit'])
        ->and((float) $credit)->toBe($seed['credit']);
})->with(['customer', 'supplier']);

test('the report page and both view pages open for the same contact', function (string $party) {
    $seed = seedParityContact($party);
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route($party.'.view', $seed['contact']->id))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component("contact/{$party}/view")->where('id', (string) $seed['contact']->id));

    $this->actingAs($superadmin)
        ->get(route('report.ledger'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('report/ledger'));
})->with(['customer', 'supplier']);

test('the report page uses the same running balance rule as the customer and supplier view pages', function () {
    $sources = collect([
        'customer view' => 'contact/customer/view.vue',
        'supplier view' => 'contact/supplier/view.vue',
        'report page' => 'report/ledger.vue',
    ])->map(fn (string $file) => preg_replace('/\s+/', ' ', file_get_contents(resource_path("js/pages/{$file}"))));

    foreach ($sources as $name => $source) {
        expect($source)->toContain("row.acc_nature === 'cr'", 'runningBalance + credit - debit', 'runningBalance + debit - credit');
    }

    expect($sources['report page'])->toContain('getLedger');
});
