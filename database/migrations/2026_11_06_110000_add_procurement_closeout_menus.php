<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Menu rows for the Procurement closeout pages (in the group of the Purchase page) and the Supplier Price Comparison report
 * (next to the Stock report), granted to the global companyadmin role.
 */
return new class extends Migration
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private function rows(): array
    {
        return [
            ['sibling:/purchase', 'Purchase Approval Rules', '/purchaseapprovalrule', 'purchaseapprovalrule', 'ListChecks', false],
            ['/purchaseapprovalrule', 'Add Purchase Approval Rule', '/purchaseapprovalrule/add', 'purchaseapprovalrule.add', 'Grid', true],
            ['/purchaseapprovalrule', 'Edit Purchase Approval Rule', '/purchaseapprovalrule/:id/edit', 'purchaseapprovalrule.edit', 'Grid', true],
            ['/purchaseapprovalrule', 'Delete Purchase Approval Rule', '/purchaseapprovalrule/delete', 'purchaseapprovalrule.delete', 'Grid', true],
            ['sibling:/purchase', 'Reorder Suggestions', '/reordersuggestion', 'reordersuggestion', 'RefreshCw', false],
            ['/reordersuggestion', 'Create Orders from Suggestions', '/reordersuggestion/add', 'reordersuggestion.add', 'Grid', true],
            ['sibling:/purchase', 'Import Purchases', '/importpurchase', 'importpurchase', 'Ship', false],
            ['/importpurchase', 'Edit Import Purchase Details', '/importpurchase/:id/edit', 'importpurchase.edit', 'Grid', true],
            ['sibling:/purchase', 'Quality Inspection', '/qualityinspection', 'qualityinspection', 'ClipboardCheck', false],
            ['/qualityinspection', 'Add Quality Inspection', '/qualityinspection/add', 'qualityinspection.add', 'Grid', true],
            ['/qualityinspection', 'Delete Quality Inspection', '/qualityinspection/delete', 'qualityinspection.delete', 'Grid', true],
            ['sibling:/purchase', 'Supplier Payments', '/supplierpayment', 'supplierpayment', 'Banknote', false],
            ['/supplierpayment', 'Add Supplier Payment', '/supplierpayment/add', 'supplierpayment.add', 'Grid', true],
            ['/supplierpayment', 'Reverse Supplier Payment', '/supplierpayment/reverse', 'supplierpayment.reverse', 'Grid', true],
            ['sibling:/purchase', 'Inbound Shipments', '/inboundshipment', 'inboundshipment', 'Truck', false],
            ['/inboundshipment', 'Add Inbound Shipment', '/inboundshipment/add', 'inboundshipment.add', 'Grid', true],
            ['/inboundshipment', 'Update Inbound Shipment', '/inboundshipment/:id/edit', 'inboundshipment.edit', 'Grid', true],
            ['sibling:/purchase', 'Supplier Returns (RMA)', '/rma', 'rma', 'Undo2', false],
            ['/rma', 'Add RMA', '/rma/add', 'rma.add', 'Grid', true],
            ['/rma', 'Update RMA', '/rma/:id/edit', 'rma.edit', 'Grid', true],
            ['/rma', 'Delete RMA', '/rma/delete', 'rma.delete', 'Grid', true],
            ['sibling:/report/stock', 'Supplier Price Comparison', '/report/supplier-price-comparison', 'report.supplier-price-comparison', 'FileBarChart', false],
        ];
    }

    public function up(): void
    {
        MenuMigration::install($this->rows());
    }

    public function down(): void
    {
        MenuMigration::remove(array_column($this->rows(), 2));
    }
};
