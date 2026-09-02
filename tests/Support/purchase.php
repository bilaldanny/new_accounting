<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Contact;
use App\Models\ItemType;
use App\Models\Product;
use App\Models\ProductDetail;
use App\Models\PurchaseLine;
use App\Models\Transaction;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

function seedPurchaseScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'PURC001',
        'name' => 'Purchase Test Company',
        'address' => '12 Warehouse Road',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $branchId = DB::table('branches')->insertGetId([
        'code' => 'PURB001',
        'company_id' => $companyId,
        'name' => 'Purchase Branch',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $unit = Unit::query()->create([
        'company_id' => $companyId,
        'name' => 'Kilogram',
        'short_name' => 'KG',
        'type' => 'large',
        'active' => true,
        'auto_adjustment' => false,
    ]);

    $brand = Brand::query()->create([
        'company_id' => $companyId,
        'name' => 'Farm Fresh',
        'active' => true,
    ]);

    $category = Category::query()->create([
        'company_id' => $companyId,
        'name' => 'Grocery',
        'active' => true,
    ]);

    $itemType = ItemType::query()->create([
        'company_id' => $companyId,
        'name' => 'Finished Goods',
        'active' => true,
    ]);

    $product = Product::query()->create([
        'company_id' => $companyId,
        'unit_id' => $unit->id,
        'brand_id' => $brand->id,
        'category_id' => $category->id,
        'itemtype_id' => $itemType->id,
        'name' => 'Premium Basmati Rice',
        'sku' => 'AS-00001',
        'type' => 'single',
        'active' => true,
    ]);

    $detail = ProductDetail::query()->create([
        'product_id' => $product->id,
        'name' => $product->name.' dummy',
        'sku' => $product->sku.'-1',
        'variation_name' => 'dummy',
        'default_purchase_price' => 100,
        'dpp_unit_price' => 100,
        'largequantity' => 10,
        'smallquantity' => 20,
        'profit_percent' => 20,
        'default_sell_price' => 120,
    ]);

    $supplier = Contact::query()->create([
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'business_name' => 'Acme Supplies',
        'first_name' => 'John',
        'mobile' => '03001234567',
        'address' => 'Test address',
        'code' => 'SU-00001',
        'user_type' => 'supplier',
        'type' => 'local',
        'ntn_number' => '1234567',
        'pay_term' => 15,
        'pay_type' => 'day',
        'active' => true,
    ]);

    $scope = [
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'unit_id' => $unit->id,
        'product_id' => $product->id,
        'variation_id' => $detail->id,
        'itemtype_id' => $itemType->id,
        'category_id' => $category->id,
        'contact_id' => $supplier->id,
    ];

    return seedPurchaseChartAccounts($scope, $supplier);
}

/**
 * @param  array<string, mixed>  $scope
 * @return array<string, mixed>
 */
function seedPurchaseChartAccounts(array $scope, Contact $supplier): array
{
    if (! DB::table('financial_years')->where('company_id', $scope['company_id'])->exists()) {
        DB::table('financial_years')->insert([
            'company_id' => $scope['company_id'],
            'name' => 'FY 2026',
            'start_date' => '2026-07-01',
            'end_date' => '2027-07-01',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $localPurchaseId = insertPurchaseChartAccount($scope, '501-00001', 'Purchase - Local', 'dr', true);
    $exportPurchaseId = insertPurchaseChartAccount($scope, '501-00002', 'Purchase - Export', 'dr', true);
    $inputTaxId = insertPurchaseChartAccount($scope, '201-00010', 'Input Tax', 'dr', false);
    $supplierAccountId = insertPurchaseChartAccount($scope, '311-00001', 'Acme Supplies', 'cr', false);

    insertPurchaseAccountMapping($scope, 'Local Purchase', 'localpurchase', $localPurchaseId);
    insertPurchaseAccountMapping($scope, 'Import Purchase', 'importpurchase', $exportPurchaseId);
    insertPurchaseAccountMapping($scope, 'Purchase', 'purchase', $localPurchaseId);
    insertPurchaseAccountMapping($scope, 'Input Tax', 'inputtax', $inputTaxId);

    $supplier->forceFill([
        'supplier_gl_id' => '311-00001',
        'gl_id' => '311-00001',
        'link_account' => true,
    ])->save();

    return array_merge($scope, [
        'local_purchase_coa_id' => $localPurchaseId,
        'local_purchase_code' => '501-00001',
        'export_purchase_coa_id' => $exportPurchaseId,
        'export_purchase_code' => '501-00002',
        'input_tax_coa_id' => $inputTaxId,
        'input_tax_code' => '201-00010',
        'supplier_coa_id' => $supplierAccountId,
        'supplier_code' => '311-00001',
    ]);
}

/**
 * @param  array<string, mixed>  $scope
 */
function insertPurchaseChartAccount(array $scope, string $code, string $name, string $nature, bool $isProfitAndLoss): int
{
    return (int) DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'code' => $code,
        'name' => $name,
        'acc_type' => 't',
        'acc_nature' => $nature,
        'pl' => $isProfitAndLoss ? 1 : 0,
        'bs' => $isProfitAndLoss ? 0 : 1,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $scope
 */
function insertPurchaseAccountMapping(array $scope, string $name, string $key, int $accountId): void
{
    DB::table('chart_of_account_mappings')->insert([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'name' => $name,
        'key' => $key,
        'value' => (string) $accountId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $scope
 */
function createExportSupplier(array $scope): Contact
{
    $code = '311-00002';

    insertPurchaseChartAccount($scope, $code, 'Export Supplies', 'cr', false);

    return Contact::query()->create([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'business_name' => 'Export Supplies',
        'first_name' => 'Amina',
        'mobile' => '03007654321',
        'address' => 'Port address',
        'code' => 'SU-00002',
        'user_type' => 'supplier',
        'type' => 'export',
        'ntn_number' => '7654321',
        'pay_term' => 15,
        'pay_type' => 'day',
        'active' => true,
        'link_account' => true,
        'supplier_gl_id' => $code,
        'gl_id' => $code,
    ]);
}

function validPurchasePayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'contact_id' => $scope['contact_id'],
        'transaction_date' => '2026-08-23',
        'pay_term' => 15,
        'pay_type' => 'day',
        'discount_type' => 'none',
        'discount_amount' => 0,
        'shipping_charges' => 50,
        'shipping_details' => 'Local delivery',
        'additional_note' => 'Urgent stock',
        'final_amount' => 250,
        'total_item' => 1,
        'is_direct' => false,
        'purchaselines' => [
            [
                'product_id' => $scope['product_id'],
                'variation_id' => $scope['variation_id'],
                'itemtype_id' => $scope['itemtype_id'],
                'unit_id' => $scope['unit_id'],
                'quantity' => 2,
                'packing_qty' => 1,
                'pp_without_discount' => 100,
                'discount_percent' => 0,
                'purchase_rate' => 100,
                'profit_percent' => 20,
                'default_sell_price' => 120,
            ],
        ],
    ], $overrides);
}

function createPurchaseRecord(array $scope, array $attributes = []): Transaction
{
    $purchase = Transaction::query()->create(array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'contact_id' => $scope['contact_id'],
        'invoice_no' => 'PO-00001',
        'type' => Transaction::TYPE_PURCHASE,
        'status' => 'pending',
        'payment_status' => 'due',
        'transaction_date' => now(),
        'final_amount' => 100,
        'total_item' => 1,
    ], $attributes));

    PurchaseLine::query()->create([
        'transaction_id' => $purchase->id,
        'product_id' => $scope['product_id'],
        'variation_id' => $scope['variation_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'unit_id' => $scope['unit_id'],
        'quantity' => 1,
        'purchase_rate' => 100,
        'pp_without_discount' => 100,
        'default_sell_price' => 120,
        'packing_qty' => 1,
    ]);

    return $purchase;
}

function createReceivedPurchase(array $scope, array $attributes = []): Transaction
{
    $purchase = createPurchaseRecord($scope, array_merge([
        'status' => 'received',
    ], $attributes));

    $line = $purchase->purchaselines()->first();

    if ($line !== null) {
        $line->quantity_received = $line->quantity;
        $line->save();
    }

    return $purchase->fresh(['purchaselines']);
}

function validPurchaseReturnPayload(array $scope, Transaction $purchase, array $overrides = []): array
{
    $line = $purchase->purchaselines()->first();

    return array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'contact_id' => $scope['contact_id'],
        'transaction_id' => $purchase->id,
        'transaction_date' => '2026-08-24',
        'purchaselines' => [
            [
                'id' => $line?->id,
                'quantity_returned' => 1,
            ],
        ],
    ], $overrides);
}

function validReceivingNotePayload(array $scope, Transaction $purchase, array $overrides = []): array
{
    $line = $purchase->purchaselines()->first();

    return array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'contact_id' => $scope['contact_id'],
        'transaction_id' => $purchase->id,
        'purchaselines' => [
            [
                'id' => $line?->id,
                'quantity_received' => 1,
            ],
        ],
    ], $overrides);
}
