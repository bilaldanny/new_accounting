<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Menu rows for the Sales & POS closeout pages, in the group of the Sell page, granted to the global companyadmin role.
 * `/salesorder/approve` is an approval menu: only roles that are given approvals (the Manager preset) hold it by default.
 */
return new class extends Migration
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private function rows(): array
    {
        return [
            ['sibling:/sell', 'Quotations', '/quotation', 'quotation', 'FileText', false],
            ['/quotation', 'Edit Quotation Details', '/quotation/:id/edit', 'quotation.edit', 'Grid', true],
            ['/quotation', 'Convert Quotation', '/quotation/convert', 'quotation.convert', 'Grid', true],
            ['sibling:/sell', 'Sales Orders', '/salesorder', 'salesorder', 'ClipboardList', false],
            ['/salesorder', 'Add Sales Order', '/salesorder/add', 'salesorder.add', 'Grid', true],
            ['/salesorder', 'Edit Sales Order', '/salesorder/:id/edit', 'salesorder.edit', 'Grid', true],
            ['/salesorder', 'Approve Sales Order', '/salesorder/approve', 'salesorder.approve', 'Grid', true],
            ['/salesorder', 'Invoice Sales Order', '/salesorder/invoice', 'salesorder.invoice', 'Grid', true],
            ['sibling:/sell', 'Store Credit', '/storecredit', 'storecredit', 'Wallet', false],
            ['/storecredit', 'Issue Store Credit', '/storecredit/add', 'storecredit.add', 'Grid', true],
            ['/storecredit', 'Refund Store Credit', '/storecredit/refund', 'storecredit.refund', 'Grid', true],
            ['/storecredit', 'Apply Store Credit', '/storecredit/apply', 'storecredit.apply', 'Grid', true],
            ['sibling:/sell', 'Cheque Management', '/chequemanagement', 'chequemanagement', 'Landmark', false],
            ['/chequemanagement', 'Add Cheque', '/chequemanagement/add', 'chequemanagement.add', 'Grid', true],
            ['/chequemanagement', 'Update Cheque Status', '/chequemanagement/:id/edit', 'chequemanagement.edit', 'Grid', true],
            ['sibling:/sell', 'Cashiers', '/cashier', 'cashier', 'UserCheck', false],
            ['/cashier', 'Add Cashier', '/cashier/add', 'cashier.add', 'Grid', true],
            ['/cashier', 'Edit Cashier', '/cashier/:id/edit', 'cashier.edit', 'Grid', true],
            ['/cashier', 'Delete Cashier', '/cashier/delete', 'cashier.delete', 'Grid', true],
            ['sibling:/sell', 'Riders', '/rider', 'rider', 'Bike', false],
            ['/rider', 'Add Rider', '/rider/add', 'rider.add', 'Grid', true],
            ['/rider', 'Edit Rider', '/rider/:id/edit', 'rider.edit', 'Grid', true],
            ['/rider', 'Delete Rider', '/rider/delete', 'rider.delete', 'Grid', true],
            ['sibling:/sell', 'Dispatch Board', '/dispatch', 'dispatch', 'Truck', false],
            ['/dispatch', 'Assign Dispatch', '/dispatch/assign', 'dispatch.assign', 'Grid', true],
            ['sibling:/sell', 'Sales KPI & Commission', '/saleskpi', 'saleskpi', 'Target', false],
            ['/saleskpi', 'Edit Targets and Commission Tiers', '/saleskpi/edit', 'saleskpi.edit', 'Grid', true],
            ['sibling:/sell', 'Sales Routes', '/salesroute', 'salesroute', 'Route', false],
            ['/salesroute', 'Add Sales Route', '/salesroute/add', 'salesroute.add', 'Grid', true],
            ['/salesroute', 'Edit Sales Route', '/salesroute/:id/edit', 'salesroute.edit', 'Grid', true],
            ['/salesroute', 'Delete Sales Route', '/salesroute/delete', 'salesroute.delete', 'Grid', true],
            ['/salesroute', 'Record Route Visit', '/salesroute/visit', 'salesroute.visit', 'Grid', true],
            ['sibling:/sell', 'Recurring Invoices', '/recurringinvoice', 'recurringinvoice', 'Repeat', false],
            ['/recurringinvoice', 'Add Recurring Invoice', '/recurringinvoice/add', 'recurringinvoice.add', 'Grid', true],
            ['/recurringinvoice', 'Edit Recurring Invoice', '/recurringinvoice/:id/edit', 'recurringinvoice.edit', 'Grid', true],
            ['/recurringinvoice', 'Delete Recurring Invoice', '/recurringinvoice/delete', 'recurringinvoice.delete', 'Grid', true],
            ['/recurringinvoice', 'Generate Recurring Invoices', '/recurringinvoice/generate', 'recurringinvoice.generate', 'Grid', true],
            ['sibling:/sell', 'Installments & Layaway', '/installment', 'installment', 'CalendarClock', false],
            ['/installment', 'Add Installment Plan', '/installment/add', 'installment.add', 'Grid', true],
            ['/installment', 'Edit Installment Plan', '/installment/:id/edit', 'installment.edit', 'Grid', true],
            ['/installment', 'Pay Installment', '/installment/pay', 'installment.pay', 'Grid', true],
            ['sibling:/sell', 'E-commerce Sync', '/ecommerce', 'ecommerce', 'ShoppingCart', false],
            ['/ecommerce', 'Add E-commerce Connection', '/ecommerce/add', 'ecommerce.add', 'Grid', true],
            ['/ecommerce', 'Edit E-commerce Connection', '/ecommerce/:id/edit', 'ecommerce.edit', 'Grid', true],
            ['/ecommerce', 'Sync E-commerce Orders', '/ecommerce/sync', 'ecommerce.sync', 'Grid', true],
            ['sibling:/sell', 'Messaging Settings', '/messagingsetting', 'messagingsetting', 'MessageCircle', false],
            ['/messagingsetting', 'Edit Messaging Settings', '/messagingsetting/edit', 'messagingsetting.edit', 'Grid', true],
            ['sibling:/sell', 'Import Sales', '/salesimport', 'salesimport', 'Upload', false],
            ['/sell', 'Import Sales Data', '/sell/import', 'sell.import', 'Grid', true],
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
