<?php

use App\Models\Contact;
use App\Models\SellLine;
use App\Models\Transaction;

function seedSellScope(): array
{
    $scope = seedPurchaseScope();

    $customer = Contact::query()->create([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'business_name' => 'Acme Retail',
        'first_name' => 'Sara',
        'mobile' => '03007654321',
        'address' => 'Customer address',
        'code' => 'CU-00001',
        'user_type' => 'customer',
        'type' => 'local',
        'ntn_number' => '7654321',
        'pay_term' => 10,
        'pay_type' => 'day',
        'credit_limit' => 25000,
        'active' => true,
    ]);

    $scope['customer_id'] = $customer->id;
    $scope['contact_id'] = $customer->id;

    return $scope;
}

function validSellPayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'contact_id' => $scope['contact_id'],
        'transaction_date' => '2026-08-26',
        'pay_term' => 10,
        'pay_type' => 'day',
        'status' => 'final',
        'discount_type' => 'none',
        'discount_amount' => 0,
        'shipping_charges' => 40,
        'shipping_details' => 'Local delivery',
        'shipping_address' => 'Warehouse 2',
        'shipping_status' => 'ordered',
        'delivered_to' => 'Front desk',
        'billty_no' => 'BLT-100',
        'packing' => 'Carton',
        'additional_note' => 'Priority dispatch',
        'final_amount' => 280,
        'total_item' => 1,
        'is_direct' => false,
        'selllines' => [
            [
                'product_id' => $scope['product_id'],
                'variation_id' => $scope['variation_id'],
                'itemtype_id' => $scope['itemtype_id'],
                'unit_id' => $scope['unit_id'],
                'quantity' => 2,
                'packing_qty' => 1,
                'unit_price' => 120,
                'discount_percent' => 0,
                'unit_price_after_discount' => 120,
                'row_subtotal' => 240,
            ],
        ],
    ], $overrides);
}

function createSellRecord(array $scope, array $attributes = []): Transaction
{
    $sell = Transaction::query()->create(array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'contact_id' => $scope['contact_id'],
        'invoice_no' => 'INV-00001',
        'type' => Transaction::TYPE_SELL,
        'status' => 'final',
        'payment_status' => 'due',
        'transaction_date' => now(),
        'final_amount' => 120,
        'total_item' => 1,
        'shipping_status' => 'ordered',
        'billty_no' => 'BLT-100',
    ], $attributes));

    SellLine::query()->create([
        'transaction_id' => $sell->id,
        'product_id' => $scope['product_id'],
        'variation_id' => $scope['variation_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'unit_id' => $scope['unit_id'],
        'quantity' => 1,
        'unit_price' => 120,
        'discount_percent' => 0,
        'unit_price_after_discount' => 120,
        'subtotal' => 120,
        'packing_qty' => 1,
    ]);

    return $sell;
}

function validIssueNotePayload(array $scope, Transaction $sell, array $overrides = []): array
{
    $line = $sell->selllines()->first();

    return array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'contact_id' => $scope['contact_id'],
        'transaction_id' => $sell->id,
        'selllines' => [
            [
                'id' => $line?->id,
                'quantity_issue' => 1,
            ],
        ],
    ], $overrides);
}
