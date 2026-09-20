<?php

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The customer group report: sales per group, where the group is the customer's group
 * (contacts.customer_group_id), not a column of the sale.
 */

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function cgrQuery(array $extra = []): array
{
    return array_merge(['show_record' => 100], $extra);
}

/**
 * The row of a group by its name.
 *
 * @return array<string, mixed>|null
 */
function cgrRowNamed(TestResponse $response, string $name): ?array
{
    return prpRows($response)->firstWhere('group_name', $name);
}

test('a sale carries no group of its own, the customer does', function () {
    expect(Schema::hasColumn('transactions', 'customer_group_id'))->toBeFalse()
        ->and(Schema::hasColumn('contacts', 'customer_group_id'))->toBeTrue();
});

test('sales are grouped by the group of the customer who bought', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $wholesale = prpGroup($scope, 'Wholesale');
    $retail = prpGroup($scope, 'Retail');

    $bulkOne = prpContact($scope, 'customer', 'Bulk One', ['customer_group_id' => $wholesale]);
    $bulkTwo = prpContact($scope, 'customer', 'Bulk Two', ['customer_group_id' => $wholesale]);
    $shop = prpContact($scope, 'customer', 'Shop', ['customer_group_id' => $retail]);

    trpDoc($scope, 'sell', ['contact_id' => $bulkOne, 'transaction_date' => '2026-09-01', 'final_amount' => 1000]);
    trpDoc($scope, 'sell', ['contact_id' => $bulkOne, 'transaction_date' => '2026-09-02', 'final_amount' => 500]);
    trpDoc($scope, 'sell', ['contact_id' => $bulkTwo, 'transaction_date' => '2026-09-03', 'final_amount' => 250]);
    trpDoc($scope, 'sell', ['contact_id' => $shop, 'transaction_date' => '2026-09-04', 'final_amount' => 80]);

    $response = prpGet('customer-group', cgrQuery());

    expect(cgrRowNamed($response, 'Wholesale'))->toMatchArray(['customers' => 2, 'invoices' => 3, 'id' => $wholesale])
        ->and(cgrRowNamed($response, 'Wholesale')['sales'])->toEqual(1750)
        ->and(cgrRowNamed($response, 'Retail'))->toMatchArray(['customers' => 1, 'invoices' => 1])
        ->and(cgrRowNamed($response, 'Retail')['sales'])->toEqual(80)
        ->and($response->json('data.total'))->toBe(2);
});

test('sell returns are taken off the sales of the group', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $group = prpGroup($scope, 'Wholesale');
    $customer = prpContact($scope, 'customer', 'Returner', ['customer_group_id' => $group]);
    $sale = trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'final_amount' => 1000]);
    trpDoc($scope, 'sellreturn', ['contact_id' => $customer, 'parent_id' => $sale, 'transaction_date' => '2026-09-02', 'final_amount' => 150]);
    trpDoc($scope, 'sellreturn', ['contact_id' => $customer, 'parent_id' => $sale, 'transaction_date' => '2026-09-03', 'final_amount' => 50]);

    $row = cgrRowNamed(prpGet('customer-group', cgrQuery()), 'Wholesale');

    expect($row['sales'])->toEqual(1000)
        ->and($row['sell_returns'])->toEqual(200)
        ->and($row['net_sales'])->toEqual(800)
        ->and($row['invoices'])->toBe(1);
});

test('customers without a group land in one no-group row', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $one = prpContact($scope, 'customer', 'Ungrouped One');
    $two = prpContact($scope, 'customer', 'Ungrouped Two');
    trpDoc($scope, 'sell', ['contact_id' => $one, 'transaction_date' => '2026-09-01', 'final_amount' => 30]);
    trpDoc($scope, 'sell', ['contact_id' => $two, 'transaction_date' => '2026-09-01', 'final_amount' => 70]);

    $rows = prpRows(prpGet('customer-group', cgrQuery()));

    expect($rows)->toHaveCount(1)
        ->and($rows->first())->toMatchArray(['group_name' => 'No group', 'customers' => 2, 'id' => 0])
        ->and($rows->first()['sales'])->toEqual(100);
});

test('a group deleted since keeps its name and its sales', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $group = prpGroup($scope, 'Closed Group');
    $customer = prpContact($scope, 'customer', 'Member', ['customer_group_id' => $group]);
    trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'final_amount' => 40]);
    DB::table('customer_groups')->where('id', $group)->update(['deleted_at' => now()]);

    expect(cgrRowNamed(prpGet('customer-group', cgrQuery()), 'Closed Group')['sales'])->toEqual(40);
});

test('drafts and quotations are not sales and deleted documents and customers are left out', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $group = prpGroup($scope, 'Statuses');
    $customer = prpContact($scope, 'customer', 'Buyer', ['customer_group_id' => $group]);
    $gone = prpContact($scope, 'customer', 'Removed', ['customer_group_id' => $group]);

    trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'status' => 'draft', 'final_amount' => 1]);
    trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'status' => 'quotation', 'final_amount' => 2]);
    trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'status' => 'issue', 'final_amount' => 4]);
    trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'status' => 'final', 'final_amount' => 8]);
    trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'final_amount' => 16, 'deleted_at' => now()]);
    trpDoc($scope, 'purchaseorder', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'final_amount' => 32]);
    trpDoc($scope, 'sell', ['contact_id' => $gone, 'transaction_date' => '2026-09-01', 'final_amount' => 64]);
    Contact::query()->findOrFail($gone)->delete();

    $row = cgrRowNamed(prpGet('customer-group', cgrQuery()), 'Statuses');

    expect($row['sales'])->toEqual(12)
        ->and($row['customers'])->toBe(1)
        ->and($row['invoices'])->toBe(2);
});

test('the date range is inclusive at both ends and groups with nothing in it are not listed', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $inside = prpGroup($scope, 'Inside');
    $outside = prpGroup($scope, 'Outside');
    $insider = prpContact($scope, 'customer', 'Insider', ['customer_group_id' => $inside]);
    $outsider = prpContact($scope, 'customer', 'Outsider', ['customer_group_id' => $outside]);

    trpDoc($scope, 'sell', ['contact_id' => $insider, 'transaction_date' => '2026-09-04 23:59:59', 'final_amount' => 1]);
    trpDoc($scope, 'sell', ['contact_id' => $insider, 'transaction_date' => '2026-09-05 00:00:00', 'final_amount' => 2]);
    trpDoc($scope, 'sell', ['contact_id' => $insider, 'transaction_date' => '2026-09-06 23:59:59', 'final_amount' => 4]);
    trpDoc($scope, 'sell', ['contact_id' => $insider, 'transaction_date' => '2026-09-07 00:00:00', 'final_amount' => 8]);
    trpDoc($scope, 'sell', ['contact_id' => $outsider, 'transaction_date' => '2026-08-01', 'final_amount' => 16]);

    $response = prpGet('customer-group', cgrQuery(['start_date' => '2026-09-05', 'end_date' => '2026-09-06']));

    expect(cgrRowNamed($response, 'Inside')['sales'])->toEqual(6)
        ->and(cgrRowNamed($response, 'Outside'))->toBeNull()
        ->and(prpGet('customer-group', cgrQuery(['end_date' => '2026-09-05']))->json('summary.sales'))->toEqual(19);
});

test('the report filters by group and searches by group name', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $wholesale = prpGroup($scope, 'Wholesale');
    $retail = prpGroup($scope, 'Retail');
    foreach ([$wholesale, $retail] as $group) {
        trpDoc($scope, 'sell', [
            'contact_id' => prpContact($scope, 'customer', 'Member '.$group, ['customer_group_id' => $group]),
            'transaction_date' => '2026-09-01',
            'final_amount' => 10,
        ]);
    }

    $names = fn (array $extra): array => prpRows(prpGet('customer-group', cgrQuery($extra)))->pluck('group_name')->all();

    expect($names(['customer_group_id' => $retail]))->toBe(['Retail'])
        ->and($names(['search' => 'wholes']))->toBe(['Wholesale']);
});

test('the summary totals the whole filtered set, not the page on screen', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $wholesale = prpGroup($scope, 'Wholesale');
    $retail = prpGroup($scope, 'Retail');
    $bulk = prpContact($scope, 'customer', 'Bulk', ['customer_group_id' => $wholesale]);
    $shop = prpContact($scope, 'customer', 'Shop', ['customer_group_id' => $retail]);
    $sale = trpDoc($scope, 'sell', ['contact_id' => $bulk, 'transaction_date' => '2026-09-01', 'final_amount' => 100]);
    trpDoc($scope, 'sellreturn', ['contact_id' => $bulk, 'parent_id' => $sale, 'transaction_date' => '2026-09-02', 'final_amount' => 30]);
    trpDoc($scope, 'sell', ['contact_id' => $shop, 'transaction_date' => '2026-09-01', 'final_amount' => 50]);

    $response = prpGet('customer-group', cgrQuery(['show_record' => 1]));

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('summary'))->toMatchArray(['count' => 2, 'customers' => 2, 'invoices' => 2])
        ->and($response->json('summary.sales'))->toEqual(150)
        ->and($response->json('summary.sell_returns'))->toEqual(30)
        ->and($response->json('summary.net_sales'))->toEqual(120);
});

test('rows sort by a known column and fall back to the highest net sales first', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $small = prpGroup($scope, 'Aaa Small');
    $large = prpGroup($scope, 'Zzz Large');
    trpDoc($scope, 'sell', ['contact_id' => prpContact($scope, 'customer', 'S', ['customer_group_id' => $small]), 'transaction_date' => '2026-09-01', 'final_amount' => 10]);
    trpDoc($scope, 'sell', ['contact_id' => prpContact($scope, 'customer', 'L', ['customer_group_id' => $large]), 'transaction_date' => '2026-09-01', 'final_amount' => 999]);

    $order = fn (array $extra): array => prpRows(prpGet('customer-group', cgrQuery($extra)))->pluck('group_name')->all();

    expect($order([]))->toBe(['Zzz Large', 'Aaa Small'])
        ->and($order(['sort_by' => 'group_name', 'sort_type' => 'asc']))->toBe(['Aaa Small', 'Zzz Large'])
        ->and($order(['sort_by' => 'net_sales', 'sort_type' => 'asc']))->toBe(['Aaa Small', 'Zzz Large'])
        ->and($order(['sort_by' => 'x; drop table users']))->toBe(['Zzz Large', 'Aaa Small']);
});

test('a company user sees only their own groups and a branch user only their branch', function () {
    $mine = trpScope('1');
    $theirs = trpScope('2');
    $otherBranch = trpBranch($mine['company_id'], 'Second Branch');

    $ownGroup = prpGroup($mine, 'Mine Group');
    $otherGroup = prpGroup($theirs, 'Theirs Group');
    $own = prpContact($mine, 'customer', 'Mine', ['customer_group_id' => $ownGroup]);
    $other = prpContact($theirs, 'customer', 'Theirs', ['customer_group_id' => $otherGroup]);
    trpDoc($mine, 'sell', ['contact_id' => $own, 'transaction_date' => '2026-09-01', 'final_amount' => 10]);
    trpDoc($mine, 'sell', ['contact_id' => $own, 'branch_id' => $otherBranch, 'transaction_date' => '2026-09-01', 'final_amount' => 5]);
    trpDoc($theirs, 'sell', ['contact_id' => $other, 'transaction_date' => '2026-09-01', 'final_amount' => 20]);

    Sanctum::actingAs(jeaUserWith($mine, ['/report/customer-group']));

    $response = prpGet('customer-group', cgrQuery(['company_id' => $theirs['company_id']]));

    expect(prpRows($response)->pluck('group_name')->all())->toBe(['Mine Group'])
        ->and($response->json('summary.sales'))->toEqual(10);

    prpGet('customer-group', cgrQuery(['search' => "' OR 1=1 --"]))->assertSuccessful()->assertJsonPath('summary.count', 0);
});

test('an empty report is empty', function () {
    trpScope();
    trpActAsSuperadmin();

    prpGet('customer-group', cgrQuery())
        ->assertSuccessful()
        ->assertJsonPath('data.total', 0)
        ->assertJsonPath('summary.net_sales', 0);
});
