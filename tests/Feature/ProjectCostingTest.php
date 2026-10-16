<?php

use App\Models\SellLine;
use App\Services\AccountsVoucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Task C, item 8: project / job costing: a project master apart from cost centers, a project dimension on sale lines, purchase lines
 * and voucher lines, and the profitability report. A tag is a dimension: no figure of the books moves.
 */
const PJ_PERMS = ['/project', '/project/add', '/project/:id/edit', '/project/delete', '/project/tag'];

function pjProject(array $extra = []): array
{
    return $extra + ['code' => 'P-100', 'name' => 'Warehouse fit-out', 'budget_revenue' => 5000, 'budget_cost' => 3000];
}

test('revenue and direct cost tagged to a project give its margin against budget, and tagging moves nothing in the books', function () {
    $scope = fcoScope();
    Sanctum::actingAs(fcoStaff($scope, PJ_PERMS));
    $project = test()->postJson('/api/projects', pjProject())->assertCreated()->json('data.id');

    $sale = createSellRecord($scope, ['final_amount' => 2000, 'invoice_no' => 'INV-PJ']);
    DB::table('sell_lines')->where('transaction_id', $sale->id)->update(['quantity' => 4, 'subtotal' => 2000, 'quantity_returned' => 1]);
    $purchase = createPurchaseRecord($scope, ['status' => 'received', 'final_amount' => 600, 'invoice_no' => 'PO-PJ']);
    DB::table('purchase_lines')->where('transaction_id', $purchase->id)->update(['quantity' => 6, 'purchase_rate' => 100, 'quantity_received' => 6]);
    $voucher = app(AccountsVoucher::class)->post($scope['company_id'], $scope['branch_id'], '2026-10-05', [
        ['account_id' => $scope['rent_id'], 'debit' => 250], ['account_id' => $scope['cash_id'], 'credit' => 250],
    ], 'Site rent');

    $books = fcoTrialBalance($scope['company_id']);
    $vouchers = DB::table('t_accounts')->count();

    $this->postJson('/api/projects/tag', ['project_id' => $project, 'transaction_ids' => [$sale->id, $purchase->id], 'voucher_ids' => [$voucher->id]])->assertOk()->assertJsonPath('lines', 4);

    // 3 of the 4 units stayed sold: 1500 revenue; 600 of purchases + 250 of rent as cost
    $report = $this->getJson("/api/projects/report?project_id={$project}")->assertOk();
    $row = $report->json('rows.0');
    expect($row['revenue'])->toEqual(1500)->and($row['cost_purchases'])->toEqual(600)->and($row['cost_vouchers'])->toEqual(250)
        ->and($row['cost'])->toEqual(850)->and($row['margin'])->toEqual(650)->and($row['margin_percent'])->toEqual(43.33)
        ->and($row['cost_over_budget'])->toEqual(-2150)->and($row['revenue_vs_budget'])->toEqual(-3500);

    // the books are exactly what they were
    expect(fcoTrialBalance($scope['company_id']))->toBe($books)->and(DB::table('t_accounts')->count())->toBe($vouchers);

    // an untagged document is not in any project
    $other = createSellRecord($scope, ['final_amount' => 999, 'invoice_no' => 'INV-OTHER']);
    expect($other->id)->toBeGreaterThan(0)
        ->and($this->getJson('/api/projects/report')->json('summary.revenue'))->toEqual(1500);
});

test('a line created through the document path carries its project and a project of another company is refused', function () {
    $scope = fcoScope();
    $other = fcoOtherCompany();
    Sanctum::actingAs(fcoStaff($scope, PJ_PERMS));
    $mine = $this->postJson('/api/projects', pjProject())->assertCreated()->json('data.id');
    $foreign = DB::table('projects')->insertGetId(['company_id' => $other['company_id'], 'code' => 'X-1', 'name' => 'Foreign', 'created_at' => now(), 'updated_at' => now()]);
    $sale = createSellRecord($scope, ['final_amount' => 100, 'invoice_no' => 'INV-LINE']);

    $line = SellLine::createFromRow(['product_id' => $scope['product_id'], 'variation_id' => $scope['variation_id'], 'unit_id' => $scope['unit_id'], 'quantity' => 1, 'unit_price' => 50, 'project_id' => $mine], $sale->id);
    expect((int) $line->fresh()->project_id)->toBe($mine);

    expect(fn () => SellLine::createFromRow(['product_id' => $scope['product_id'], 'variation_id' => $scope['variation_id'], 'unit_id' => $scope['unit_id'], 'quantity' => 1, 'unit_price' => 50, 'project_id' => $foreign], $sale->id))
        ->toThrow(ValidationException::class);

    $this->postJson('/api/projects/tag', ['project_id' => $foreign, 'transaction_ids' => [$sale->id]])->assertUnprocessable();
    $this->postJson('/api/projects', pjProject(['code' => 'P-200', 'cost_center_id' => 999999]))->assertUnprocessable();
});

test('tagging into a locked period is refused, a project with tags cannot be deleted and projects are company scoped', function () {
    $scope = fcoScope();
    $other = fcoOtherCompany();
    Sanctum::actingAs(fcoStaff($scope, PJ_PERMS));
    $project = $this->postJson('/api/projects', pjProject(['company_id' => $other['company_id']]))->assertCreated()->json('data.id');
    expect((int) DB::table('projects')->where('id', $project)->value('company_id'))->toBe((int) $scope['company_id']);

    $sale = createSellRecord($scope, ['final_amount' => 100, 'invoice_no' => 'INV-LOCK', 'transaction_date' => '2026-08-10']);
    fcoLockThrough($scope['company_id'], '2026-08-31');
    $this->postJson('/api/projects/tag', ['project_id' => $project, 'transaction_ids' => [$sale->id]])->assertStatus(422);

    $open = createSellRecord($scope, ['final_amount' => 100, 'invoice_no' => 'INV-OPEN', 'transaction_date' => '2026-10-10']);
    $this->postJson('/api/projects/tag', ['project_id' => $project, 'transaction_ids' => [$open->id]])->assertOk();
    $this->deleteJson("/api/projects/{$project}")->assertUnprocessable();

    Sanctum::actingAs(fcoStaff($other, PJ_PERMS));
    $this->getJson('/api/projects')->assertOk()->assertJsonCount(0, 'data');
    $this->putJson("/api/projects/{$project}", pjProject())->assertNotFound();
    $this->postJson('/api/projects/tag', ['project_id' => $project, 'transaction_ids' => [$open->id]])->assertUnprocessable();
});

test('crud matrix: a project is added, validated, edited and deleted', function () {
    $scope = fcoScope();
    Sanctum::actingAs(fcoStaff($scope, PJ_PERMS));

    $this->postJson('/api/projects', pjProject(['name' => '']))->assertUnprocessable();
    $id = (int) $this->postJson('/api/projects', pjProject(['code' => 'M-1']))->assertCreated()->json('data.id');
    $this->postJson('/api/projects', pjProject(['code' => 'M-1']))->assertUnprocessable();
    $this->getJson('/api/projects')->assertOk();
    $this->putJson("/api/projects/{$id}", pjProject(['code' => 'M-1', 'name' => 'Edited project']))->assertSuccessful();
    expect(DB::table('projects')->where('id', $id)->value('name'))->toBe('Edited project');
    $this->deleteJson("/api/projects/{$id}")->assertSuccessful();
    expect(DB::table('projects')->where('id', $id)->whereNull('deleted_at')->exists())->toBeFalse();
});
