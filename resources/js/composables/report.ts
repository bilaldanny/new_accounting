import { reactive, ref } from 'vue';
import { API_ENDPOINTS } from './apiEndpoints';
import useCommons from './common';

/**
 * Every report under Reports (the controllers in app/Http/Controllers/Reports, one per family). One page
 * component (pages/report/index.vue) shows all of them; this file is the only place that says how they
 * differ: title, filters, columns and the totals strip. The counting rules (which statuses count,
 * inclusive dates, how stock and cost are valued) live on the server.
 */
export type ReportKey =
    | 'purchase'
    | 'purchase-return'
    | 'sell'
    | 'sell-return'
    | 'purchase-payment'
    | 'sell-payment'
    | 'stock-adjustment'
    | 'expense'
    | 'customer-outstanding'
    | 'supplier-outstanding'
    | 'customer-supplier'
    | 'customer-group'
    | 'customer-aging'
    | 'supplier-aging'
    | 'product-purchase'
    | 'product-sell'
    | 'product-sell-summary'
    | 'item-profit-loss'
    | 'item-purchase'
    | 'item-sell'
    | 'purchase-sale'
    | 'tax'
    | 'trending-products'
    | 'stock'
    | 'stock-transfer'
    | 'account-ledger'
    | 'trial-balance'
    | 'vouchers'
    | 'profit-loss'
    | 'balance-sheet'
    | 'sales-discount'
    | 'purchase-price-trend'
    | 'supplier-performance'
    | 'backorder'
    | 'fsn'
    | 'cash-collection'
    | 'payment-account'
    | 'payment-age'
    | 'consolidated-branch'
    | 'financial-ratios'
    | 'cash-flow'
    | 'register'
    | 'activity-summary'
    | 'change-history'
    | 'price-history'
    | 'cost-center-analysis'
    | 'budget-vs-actual'
    | 'warehouse-stock'
    | 'stock-movement-history'
    | 'warehouse-usage'
    | 'serial-traceability'
    | 'batch-expiry';

export type ReportColumn = {
    key: string;
    label: string;
    type?: 'primary' | 'secondary';
    format?: 'number';
    decimals?: number;
    responsive?: string[];
    emptyDisplay?: string;
};

export type SummaryCard = {
    /** Dot path inside the response's `summary`. */
    key: string;
    label: string;
    /** Amounts get two decimals, counts none. */
    kind?: 'amount' | 'count';
    accent?: boolean;
};

export type ReportFilters = {
    /** Which party the contact filter lists, if any. */
    party?: 'customer' | 'supplier' | 'both';
    statuses?: { value: string; label: string }[];
    /** A warehouse selector (All, Unassigned, then the warehouses of the chosen branch). */
    warehouseFilter?: boolean;
    /** The heading of the status selector when it is not a status (for example a grouping). */
    statusLabel?: string;
    defaultStatus?: string;
    paymentStatus?: boolean;
    methods?: boolean;
    adjustmentTypes?: boolean;
    /** One "as of" day instead of a date range. */
    asOf?: boolean;
    customerGroup?: boolean;
    /** The customer / supplier / all selector of the customer & supplier summary. */
    contactTypes?: boolean;
    /** The option to list contacts whose balance is settled. */
    includeZero?: boolean;
    /** Category, brand and product selectors. */
    productFilters?: boolean;
    /** The number of products to rank. */
    topN?: boolean;
    /** The input / output tax selector. */
    taxSides?: boolean;
    /** The option to show stock per branch instead of all branches together. */
    byBranch?: boolean;
    /** The from and to branch selectors of the transfer report. */
    transferBranches?: boolean;
    /** The report works from one company's books, so nothing loads until a company is chosen. */
    requiresCompany?: boolean;
    /** The chart-of-accounts selector of the account ledger. */
    accountSelector?: boolean;
    /** The account class selector of the trial balance. */
    accountGroups?: boolean;
    /** The receipt / payment selector of the voucher report. */
    voucherTypes?: boolean;
};

export type ReportConfig = {
    title: string;
    subtitle: string;
    exportName: string;
    filters: ReportFilters;
    columns: ReportColumn[];
    summary: SummaryCard[];
    searchPlaceholder: string;
};

const ALL = ['xs', 'sm', 'md', 'lg'];
const WIDE = ['md', 'lg'];
const MID = ['sm', 'md', 'lg'];

const money = (key: string, label: string, responsive: string[] = ALL): ReportColumn => ({
    key,
    label,
    format: 'number',
    decimals: 2,
    responsive,
    emptyDisplay: '0.00',
});

const text = (key: string, label: string, responsive: string[] = ALL, type: 'primary' | 'secondary' = 'secondary'): ReportColumn => ({
    key,
    label,
    type,
    responsive,
    emptyDisplay: '-',
});

const documentStatuses = (values: string[]) => [
    { value: 'all', label: 'All' },
    ...values.map((value) => ({ value, label: value.charAt(0).toUpperCase() + value.slice(1) })),
];

const documentColumns = (partyLabel: string, reference?: ReportColumn, parent?: ReportColumn): ReportColumn[] => [
    text('transaction_date', 'Date'),
    text('invoice_no', 'Invoice No', ALL, 'primary'),
    ...(reference ? [reference] : []),
    ...(parent ? [parent] : []),
    text('contact_name', partyLabel),
    text('branch_name', 'Branch', MID),
    text('status', 'Status', MID),
    text('payment_status', 'Payment', MID),
    money('total_before_tax', 'Before Tax', WIDE),
    money('tax_amount', 'Tax', WIDE),
    money('discount_amount', 'Discount', WIDE),
    money('shipping_charges', 'Shipping', WIDE),
    money('final_amount', 'Total'),
    money('paid', 'Paid', MID),
    money('due', 'Due', MID),
];

const documentSummary = (): SummaryCard[] => [
    { key: 'count', label: 'Documents', kind: 'count' },
    { key: 'final_amount', label: 'Total' },
    { key: 'tax_amount', label: 'Tax' },
    { key: 'paid', label: 'Paid' },
    { key: 'due', label: 'Due', accent: true },
];

const documentFilters = (party: 'customer' | 'supplier', statuses: string[]): ReportFilters => ({
    party,
    statuses: documentStatuses(statuses),
    defaultStatus: 'all',
    paymentStatus: true,
});

const documentSearch = 'Invoice, reference or party';

/** A quantity: up to four decimals are meaningful, so it is not shown as money. */
const qty = (key: string, label: string, responsive: string[] = ALL): ReportColumn => ({
    key,
    label,
    format: 'number',
    decimals: 2,
    responsive,
    emptyDisplay: '0.00',
});

/** A figure that can be unknown: it shows a dash instead of a false zero. */
const known = (key: string, label: string, responsive: string[] = ALL): ReportColumn => ({
    key,
    label,
    format: 'number',
    decimals: 2,
    responsive,
    emptyDisplay: '-',
});

const lineColumns = (party: string, supplierRef: boolean): ReportColumn[] => [
    text('transaction_date', 'Date'),
    text('invoice_no', 'Invoice No', ALL, 'primary'),
    ...(supplierRef ? [text('sup_ref_no', 'Supplier Ref', WIDE)] : []),
    text('contact_name', party, MID),
    text('product_name', 'Product'),
    text('sku', 'SKU', WIDE),
    text('brand_name', 'Brand', WIDE),
    text('branch_name', 'Branch', WIDE),
    text('unit_name', 'Unit', WIDE),
    qty('quantity', 'Quantity', MID),
    qty('net_quantity', 'Net Qty', WIDE),
    money('unit_price', 'Rate', WIDE),
    money('discount_percent', 'Discount %', WIDE),
    money('amount', 'Amount', MID),
    money('net_amount', 'Net Amount'),
];

const lineSummary = (): SummaryCard[] => [
    { key: 'count', label: 'Lines', kind: 'count' },
    { key: 'quantity', label: 'Quantity (base units)' },
    { key: 'net_quantity', label: 'Net quantity' },
    { key: 'amount', label: 'Amount' },
    { key: 'net_amount', label: 'Net amount', accent: true },
];

const count = (key: string, label: string, responsive: string[] = ALL): ReportColumn => ({
    key,
    label,
    format: 'number',
    decimals: 0,
    responsive,
    emptyDisplay: '0',
});

/** Customer and supplier aging differ only in wording. */
const agingReport = (title: string, party: 'customer' | 'supplier', document: string, owed: string): ReportConfig => ({
    title: `${title} Aging Report`,
    subtitle: `What is still ${owed} on each open ${document}, by days past its due date on the day. The due date is the invoice date plus its pay term, or the invoice date itself when there is none.`,
    exportName: `${party}-aging-report`,
    filters: { party, asOf: true },
    columns: [
        text('contact_name', title, ALL, 'primary'),
        text('code', 'Code', WIDE),
        count('invoices', 'Invoices', MID),
        count('oldest_days', 'Oldest (days)', MID),
        money('not_due', 'Not Yet Due', WIDE),
        money('days_0_30', '0 - 30', MID),
        money('days_31_60', '31 - 60', MID),
        money('days_61_90', '61 - 90', MID),
        money('days_90_plus', '90+', MID),
        money('total', 'Total'),
    ],
    summary: [
        { key: 'count', label: `${title}s`, kind: 'count' },
        { key: 'not_due', label: 'Not yet due' },
        { key: 'days_0_30', label: '0 - 30' },
        { key: 'days_31_60', label: '31 - 60' },
        { key: 'days_61_90', label: '61 - 90' },
        { key: 'days_90_plus', label: '90+' },
        { key: 'total', label: 'Total', accent: true },
    ],
    searchPlaceholder: `${title} name or code`,
});
export const REPORTS: Record<ReportKey, ReportConfig> = {
    purchase: {
        title: 'Purchase Report',
        subtitle: 'Purchase orders by date, supplier and branch, with what was paid and what is still due',
        exportName: 'purchase-report',
        filters: documentFilters('supplier', ['pending', 'approved', 'ordered', 'received']),
        columns: documentColumns('Supplier', text('sup_ref_no', 'Supplier Ref', MID)),
        summary: documentSummary(),
        searchPlaceholder: documentSearch,
    },
    'purchase-return': {
        title: 'Purchase Return Report',
        subtitle: 'Purchase returns by date, supplier and branch',
        exportName: 'purchase-return-report',
        filters: documentFilters('supplier', ['pending', 'approved']),
        columns: documentColumns('Supplier', undefined, text('parent_invoice_no', 'Purchase Invoice', MID)),
        summary: documentSummary(),
        searchPlaceholder: documentSearch,
    },
    sell: {
        title: 'Sell Report',
        subtitle: 'Sales by date, customer and branch, with what was paid and what is still due. Drafts and quotations are not sales and are left out.',
        exportName: 'sell-report',
        filters: documentFilters('customer', ['final', 'approved', 'issue']),
        columns: documentColumns('Customer'),
        summary: documentSummary(),
        searchPlaceholder: documentSearch,
    },
    'sell-return': {
        title: 'Sell Return Report',
        subtitle: 'Sale returns by date, customer and branch',
        exportName: 'sell-return-report',
        filters: documentFilters('customer', ['pending', 'approved']),
        columns: documentColumns('Customer', undefined, text('parent_invoice_no', 'Sell Invoice', MID)),
        summary: documentSummary(),
        searchPlaceholder: documentSearch,
    },
    'purchase-payment': {
        title: 'Purchase Payment Report',
        subtitle: 'Payments made to suppliers, by the day they were paid',
        exportName: 'purchase-payment-report',
        filters: { party: 'supplier', methods: true },
        columns: [
            text('paid_on', 'Paid On'),
            text('payment_ref_no', 'Payment Ref', ALL, 'primary'),
            text('invoice_no', 'Invoice No'),
            text('contact_name', 'Supplier'),
            text('branch_name', 'Branch', MID),
            text('method', 'Method', MID),
            text('cheque_number', 'Cheque No', WIDE),
            money('invoice_amount', 'Invoice Total', WIDE),
            text('payment_status', 'Invoice Payment', WIDE),
            money('amount', 'Amount Paid'),
            text('note', 'Note', WIDE),
        ],
        summary: [
            { key: 'count', label: 'Payments', kind: 'count' },
            { key: 'total_amount', label: 'Total paid', accent: true },
            { key: 'by_method.cash', label: 'Cash' },
            { key: 'by_method.bank_transfer', label: 'Bank transfer' },
            { key: 'by_method.cheque', label: 'Cheque' },
            { key: 'by_method.card', label: 'Card' },
        ],
        searchPlaceholder: 'Payment ref, invoice or supplier',
    },
    'sell-payment': {
        title: 'Sell Payment Report',
        subtitle: 'Payments received from customers, by the day they were received',
        exportName: 'sell-payment-report',
        filters: { party: 'customer', methods: true },
        columns: [
            text('paid_on', 'Paid On'),
            text('payment_ref_no', 'Payment Ref', ALL, 'primary'),
            text('invoice_no', 'Invoice No'),
            text('contact_name', 'Customer'),
            text('branch_name', 'Branch', MID),
            text('method', 'Method', MID),
            text('cheque_number', 'Cheque No', WIDE),
            money('invoice_amount', 'Invoice Total', WIDE),
            text('payment_status', 'Invoice Payment', WIDE),
            money('amount', 'Amount Received'),
            text('note', 'Note', WIDE),
        ],
        summary: [
            { key: 'count', label: 'Payments', kind: 'count' },
            { key: 'total_amount', label: 'Total received', accent: true },
            { key: 'by_method.cash', label: 'Cash' },
            { key: 'by_method.bank_transfer', label: 'Bank transfer' },
            { key: 'by_method.cheque', label: 'Cheque' },
            { key: 'by_method.card', label: 'Card' },
        ],
        searchPlaceholder: 'Payment ref, invoice or customer',
    },
    'stock-adjustment': {
        title: 'Stock Adjustment Report',
        subtitle: 'Stock adjustments by date and branch, with their value by type',
        exportName: 'stock-adjustment-report',
        filters: {
            statuses: [
                { value: 'all', label: 'All' },
                { value: 'completed', label: 'Completed' },
                { value: 'pending', label: 'Pending' },
            ],
            defaultStatus: 'all',
            adjustmentTypes: true,
        },
        columns: [
            text('transaction_date', 'Date'),
            text('invoice_no', 'Reference', ALL, 'primary'),
            text('branch_name', 'Branch', MID),
            text('adjustment_type', 'Type'),
            text('status', 'Status', MID),
            { key: 'total_item', label: 'Items', format: 'number', decimals: 0, responsive: MID, emptyDisplay: '0' },
            money('final_amount', 'Value'),
            text('created_by_name', 'Made By', WIDE),
            text('additional_note', 'Note', WIDE),
        ],
        summary: [
            { key: 'count', label: 'Adjustments', kind: 'count' },
            { key: 'total', label: 'Total value', accent: true },
            { key: 'by_type.normal', label: 'Normal' },
            { key: 'by_type.abnormal', label: 'Abnormal' },
            { key: 'by_type.unboxing', label: 'Unboxing' },
            { key: 'by_type.opening', label: 'Opening' },
        ],
        searchPlaceholder: 'Reference or note',
    },
    expense: {
        title: 'Expense Report',
        subtitle: 'Approved expense vouchers, one line per expense account',
        exportName: 'expense-report',
        filters: {},
        columns: [
            text('voucher_date', 'Date'),
            text('voucher_no', 'Voucher No', ALL, 'primary'),
            text('ref_no', 'Reference', MID),
            text('account_code', 'Account Code', MID),
            text('account_name', 'Expense Account'),
            text('description', 'Description', WIDE),
            text('branch_name', 'Branch', MID),
            money('amount', 'Amount'),
        ],
        summary: [
            { key: 'vouchers', label: 'Vouchers', kind: 'count' },
            { key: 'count', label: 'Expense lines', kind: 'count' },
            { key: 'total_amount', label: 'Total expense', accent: true },
        ],
        searchPlaceholder: 'Voucher, reference, account or description',
    },
    'customer-outstanding': {
        title: 'Customer Outstanding Report',
        subtitle: 'What each customer still owes on the day, the same figure their ledger closes on. A negative amount is an advance.',
        exportName: 'customer-outstanding-report',
        filters: { party: 'customer', asOf: true, customerGroup: true, includeZero: true },
        columns: [
            text('contact_name', 'Customer', ALL, 'primary'),
            text('code', 'Code', MID),
            text('group_name', 'Group', WIDE),
            text('account_code', 'Account', WIDE),
            text('position', 'Position', MID),
            money('balance', 'Outstanding'),
        ],
        summary: [
            { key: 'count', label: 'Customers', kind: 'count' },
            { key: 'total_due', label: 'Total due', accent: true },
            { key: 'total_advance', label: 'Advances' },
            { key: 'net', label: 'Net' },
        ],
        searchPlaceholder: 'Customer name or code',
    },
    'supplier-outstanding': {
        title: 'Supplier Outstanding Report',
        subtitle: 'What is still owed to each supplier on the day, the same figure their ledger closes on. A negative amount is an advance paid.',
        exportName: 'supplier-outstanding-report',
        filters: { party: 'supplier', asOf: true, includeZero: true },
        columns: [
            text('contact_name', 'Supplier', ALL, 'primary'),
            text('code', 'Code', MID),
            text('account_code', 'Account', WIDE),
            text('position', 'Position', MID),
            money('balance', 'Outstanding'),
        ],
        summary: [
            { key: 'count', label: 'Suppliers', kind: 'count' },
            { key: 'total_due', label: 'Total payable', accent: true },
            { key: 'total_advance', label: 'Advances paid' },
            { key: 'net', label: 'Net' },
        ],
        searchPlaceholder: 'Supplier name or code',
    },
    'customer-supplier': {
        title: 'Customer & Supplier Report',
        subtitle: 'Sales, purchases and returns per customer and supplier over a period. Both dues are positive while money is still to move and negative for an overpayment.',
        exportName: 'customer-supplier-report',
        filters: { contactTypes: true, customerGroup: true },
        columns: [
            text('contact_name', 'Contact', ALL, 'primary'),
            text('user_type', 'Type', MID),
            text('group_name', 'Group', WIDE),
            money('purchases', 'Purchases', WIDE),
            money('purchase_returns', 'Purchase Returns', WIDE),
            money('sales', 'Sales', MID),
            money('sell_returns', 'Sell Returns', WIDE),
            money('received', 'Received', WIDE),
            money('paid', 'Paid', WIDE),
            money('receivable_due', 'Receivable Due'),
            money('payable_due', 'Payable Due'),
        ],
        summary: [
            { key: 'count', label: 'Contacts', kind: 'count' },
            { key: 'sales', label: 'Sales' },
            { key: 'purchases', label: 'Purchases' },
            { key: 'receivable_due', label: 'Receivable due', accent: true },
            { key: 'payable_due', label: 'Payable due', accent: true },
        ],
        searchPlaceholder: 'Contact name or code',
    },
    'customer-group': {
        title: 'Customer Group Report',
        subtitle: 'Sales per customer group over a period. A sale belongs to the group its customer is in; sell returns are taken off.',
        exportName: 'customer-group-report',
        filters: { customerGroup: true },
        columns: [
            text('group_name', 'Customer Group', ALL, 'primary'),
            count('customers', 'Customers', MID),
            count('invoices', 'Invoices', MID),
            money('sales', 'Sales', MID),
            money('sell_returns', 'Sell Returns', WIDE),
            money('net_sales', 'Net Sales'),
        ],
        summary: [
            { key: 'count', label: 'Groups', kind: 'count' },
            { key: 'customers', label: 'Customers', kind: 'count' },
            { key: 'sales', label: 'Sales' },
            { key: 'sell_returns', label: 'Sell returns' },
            { key: 'net_sales', label: 'Net sales', accent: true },
        ],
        searchPlaceholder: 'Group name',
    },
    'customer-aging': agingReport('Customer', 'customer', 'customer invoice', 'owed'),
    'supplier-aging': agingReport('Supplier', 'supplier', 'purchase invoice', 'payable'),
    'product-purchase': {
        title: 'Product Purchase Report',
        subtitle: 'Every purchase line by date, supplier and product. Drafts are left out; what was returned comes off the net figures.',
        exportName: 'product-purchase-report',
        filters: { party: 'supplier', productFilters: true },
        columns: lineColumns('Supplier', true),
        summary: lineSummary(),
        searchPlaceholder: 'Product, SKU, invoice or supplier',
    },
    'product-sell': {
        title: 'Product Sell Report',
        subtitle: 'Every sell line by date, customer and product. Drafts and quotations are left out; what was returned comes off the net figures.',
        exportName: 'product-sell-report',
        filters: { party: 'customer', productFilters: true },
        columns: lineColumns('Customer', false),
        summary: lineSummary(),
        searchPlaceholder: 'Product, SKU, invoice or customer',
    },
    'product-sell-summary': {
        title: 'Product Sell Summary',
        subtitle: 'Sales per product over a period, with the stock left on the last day of the period.',
        exportName: 'product-sell-summary',
        filters: { party: 'customer', productFilters: true },
        columns: [
            text('product_name', 'Product', ALL, 'primary'),
            text('sku', 'SKU', WIDE),
            text('brand_name', 'Brand', WIDE),
            text('unit_name', 'Unit', WIDE),
            qty('quantity', 'Sold', MID),
            qty('returned_quantity', 'Returned', WIDE),
            qty('net_quantity', 'Net Sold', MID),
            money('net_amount', 'Net Amount'),
            qty('current_stock', 'Stock', MID),
        ],
        summary: [
            { key: 'count', label: 'Products', kind: 'count' },
            { key: 'quantity', label: 'Sold (base units)' },
            { key: 'net_quantity', label: 'Net sold' },
            { key: 'net_amount', label: 'Net amount', accent: true },
        ],
        searchPlaceholder: 'Product, SKU, invoice or customer',
    },
    'item-profit-loss': {
        title: 'Item Profit & Loss Report',
        subtitle: 'Every sold line with its cost and profit. Cost is the weighted average purchase cost on the day of the sale; a product never purchased has no cost and no profit.',
        exportName: 'item-profit-loss-report',
        filters: { party: 'customer', productFilters: true },
        columns: [
            text('transaction_date', 'Date'),
            text('invoice_no', 'Invoice No', ALL, 'primary'),
            text('contact_name', 'Customer', MID),
            text('product_name', 'Product'),
            text('sku', 'SKU', WIDE),
            qty('net_base_quantity', 'Qty', MID),
            money('net_amount', 'Sales', MID),
            known('average_cost', 'Avg Cost', WIDE),
            known('cost', 'Cost', MID),
            known('profit', 'Profit'),
            known('margin', 'Margin %', WIDE),
        ],
        summary: [
            { key: 'count', label: 'Lines', kind: 'count' },
            { key: 'sales', label: 'Sales' },
            { key: 'cost', label: 'Cost (costed lines)' },
            { key: 'profit', label: 'Profit', accent: true },
            { key: 'margin', label: 'Margin %' },
            { key: 'without_cost', label: 'Lines without cost', kind: 'count' },
        ],
        searchPlaceholder: 'Product, SKU, invoice or customer',
    },
    'item-purchase': {
        title: 'Item Purchase Report',
        subtitle: 'How much of each product was bought from each supplier, in the product\'s base unit and net of returns.',
        exportName: 'item-purchase-report',
        filters: { party: 'supplier', productFilters: true },
        columns: [
            text('product_name', 'Product', ALL, 'primary'),
            text('sku', 'SKU', WIDE),
            text('contact_name', 'Supplier'),
            text('unit_name', 'Unit', WIDE),
            count('invoices', 'Lines', WIDE),
            qty('quantity', 'Quantity', MID),
            money('amount', 'Amount'),
        ],
        summary: [
            { key: 'count', label: 'Rows', kind: 'count' },
            { key: 'quantity', label: 'Quantity (base units)' },
            { key: 'amount', label: 'Amount', accent: true },
        ],
        searchPlaceholder: 'Product, SKU, invoice or supplier',
    },
    'item-sell': {
        title: 'Item Sell Report',
        subtitle: 'How much of each product was sold to each customer, in the product\'s base unit and net of returns.',
        exportName: 'item-sell-report',
        filters: { party: 'customer', productFilters: true },
        columns: [
            text('product_name', 'Product', ALL, 'primary'),
            text('sku', 'SKU', WIDE),
            text('contact_name', 'Customer'),
            text('unit_name', 'Unit', WIDE),
            count('invoices', 'Lines', WIDE),
            qty('quantity', 'Quantity', MID),
            money('amount', 'Amount'),
        ],
        summary: [
            { key: 'count', label: 'Rows', kind: 'count' },
            { key: 'quantity', label: 'Quantity (base units)' },
            { key: 'amount', label: 'Amount', accent: true },
        ],
        searchPlaceholder: 'Product, SKU, invoice or customer',
    },
    'purchase-sale': {
        title: 'Purchase & Sale Report',
        subtitle: 'What was bought and sold in the period, what came back, what was paid or received and what is still due. Returns are taken off both sides.',
        exportName: 'purchase-sale-report',
        filters: {},
        columns: [
            text('section', 'Section', MID),
            text('label', 'Item', ALL, 'primary'),
            money('amount', 'Amount'),
        ],
        summary: [
            { key: 'net_sales', label: 'Net sales' },
            { key: 'net_purchases', label: 'Net purchases' },
            { key: 'sales_due', label: 'Sales due' },
            { key: 'purchase_due', label: 'Purchase due' },
            { key: 'overall', label: 'Sales less purchases', accent: true },
        ],
        searchPlaceholder: '',
    },
    tax: {
        title: 'Tax Report',
        subtitle: 'Tax on purchases (input) and sales (output), returns included. Net tax payable = output tax - input tax.',
        exportName: 'tax-report',
        filters: { taxSides: true },
        columns: [
            text('transaction_date', 'Date'),
            text('document', 'Document', MID),
            text('side', 'Side', MID),
            text('invoice_no', 'Invoice No', ALL, 'primary'),
            text('contact_name', 'Contact'),
            text('branch_name', 'Branch', WIDE),
            money('total_before_tax', 'Before Tax', WIDE),
            money('tax_amount', 'Tax'),
            money('final_amount', 'Total', MID),
        ],
        summary: [
            { key: 'count', label: 'Documents', kind: 'count' },
            { key: 'output_tax', label: 'Output tax' },
            { key: 'input_tax', label: 'Input tax' },
            { key: 'net_tax', label: 'Net tax payable', accent: true },
        ],
        searchPlaceholder: 'Invoice or contact',
    },
    'trending-products': {
        title: 'Trending Products Report',
        subtitle: 'The best selling products of the period by base units sold, net of returns.',
        exportName: 'trending-products-report',
        filters: { productFilters: true, topN: true },
        columns: [
            count('rank', 'Rank', ALL),
            text('product_name', 'Product', ALL, 'primary'),
            text('sku', 'SKU', WIDE),
            text('brand_name', 'Brand', WIDE),
            text('unit_name', 'Unit', WIDE),
            count('invoices', 'Lines', WIDE),
            qty('quantity', 'Quantity Sold', MID),
            money('net_amount', 'Net Amount'),
        ],
        summary: [
            { key: 'count', label: 'Products', kind: 'count' },
            { key: 'quantity', label: 'Quantity (base units)' },
            { key: 'net_amount', label: 'Net amount', accent: true },
        ],
        searchPlaceholder: '',
    },
    stock: {
        title: 'Stock Report',
        subtitle: 'Stock per product on a day, in the product\'s base unit, with how it got there. Value uses the weighted average purchase cost on that day and the default sell price.',
        exportName: 'stock-report',
        filters: { asOf: true, productFilters: true, byBranch: true },
        columns: [
            text('product_name', 'Product', ALL, 'primary'),
            text('sku', 'SKU', WIDE),
            text('branch_name', 'Branch', WIDE),
            text('unit_name', 'Unit', WIDE),
            qty('purchased', 'Purchased', MID),
            qty('purchase_returned', 'Purch. Returned', WIDE),
            qty('sold', 'Sold', MID),
            qty('sale_returned', 'Sale Returned', WIDE),
            qty('transferred_in', 'Transfer In', WIDE),
            qty('transferred_out', 'Transfer Out', WIDE),
            qty('adjusted', 'Adjusted', WIDE),
            qty('current_stock', 'Current Stock'),
            known('stock_value', 'Stock Value', MID),
            known('potential_profit', 'Potential Profit', WIDE),
        ],
        summary: [
            { key: 'count', label: 'Products', kind: 'count' },
            { key: 'stock_value', label: 'Stock value', accent: true },
            { key: 'sell_value', label: 'Value at sell price' },
            { key: 'potential_profit', label: 'Potential profit' },
            { key: 'without_cost', label: 'Without cost', kind: 'count' },
        ],
        searchPlaceholder: 'Product or SKU',
    },
    'stock-transfer': {
        title: 'Stock Transfer Report',
        subtitle: 'Transfers between branches by date, with their value by status. Only completed transfers move stock.',
        exportName: 'stock-transfer-report',
        filters: {
            transferBranches: true,
            statuses: [
                { value: 'all', label: 'All' },
                { value: 'completed', label: 'Completed' },
                { value: 'pending', label: 'Pending' },
            ],
            defaultStatus: 'all',
        },
        columns: [
            text('transaction_date', 'Date'),
            text('invoice_no', 'Reference', ALL, 'primary'),
            text('from_branch', 'From Branch'),
            text('to_branch', 'To Branch'),
            text('status', 'Status', MID),
            count('total_item', 'Items', MID),
            money('final_amount', 'Value'),
            text('created_by_name', 'Made By', WIDE),
            text('additional_note', 'Note', WIDE),
        ],
        summary: [
            { key: 'count', label: 'Transfers', kind: 'count' },
            { key: 'total', label: 'Total value', accent: true },
            { key: 'completed', label: 'Completed' },
            { key: 'pending', label: 'Pending' },
        ],
        searchPlaceholder: 'Reference or note',
    },
    'account-ledger': {
        title: 'Account Ledger',
        subtitle: 'The statement of any chart-of-accounts account; a group account is the sum of the accounts below it. Approved vouchers only, balance on the side the account normally carries.',
        exportName: 'account-ledger',
        filters: { requiresCompany: true, accountSelector: true },
        columns: [
            text('voucher_date', 'Date'),
            text('voucher_no', 'Voucher No', ALL, 'primary'),
            text('ref_no', 'Reference', WIDE),
            text('account_code', 'Account', WIDE),
            text('account_name', 'Account Name', WIDE),
            text('description', 'Description', MID),
            text('branch_name', 'Branch', WIDE),
            money('debit', 'Debit', MID),
            money('credit', 'Credit', MID),
            money('balance', 'Balance'),
        ],
        summary: [
            { key: 'opening', label: 'Opening balance' },
            { key: 'debit', label: 'Debit' },
            { key: 'credit', label: 'Credit' },
            { key: 'closing', label: 'Closing balance', accent: true },
        ],
        searchPlaceholder: 'Voucher, reference or description',
    },
    'trial-balance': {
        title: 'Trial Balance',
        subtitle: 'Every posting account with its opening balance, the period\'s debits and credits and its closing balance. Classes follow the first digit of the code. The difference should be zero.',
        exportName: 'trial-balance',
        filters: { requiresCompany: true, accountGroups: true },
        columns: [
            text('code', 'Code', ALL, 'primary'),
            text('name', 'Account'),
            text('class', 'Class', WIDE),
            money('opening_debit', 'Opening Dr', WIDE),
            money('opening_credit', 'Opening Cr', WIDE),
            money('debit', 'Debit', MID),
            money('credit', 'Credit', MID),
            money('closing_debit', 'Closing Dr'),
            money('closing_credit', 'Closing Cr'),
        ],
        summary: [
            { key: 'count', label: 'Accounts', kind: 'count' },
            { key: 'closing_debit', label: 'Closing debit' },
            { key: 'closing_credit', label: 'Closing credit' },
            { key: 'closing_difference', label: 'Difference', accent: true },
        ],
        searchPlaceholder: 'Account code or name',
    },
    vouchers: {
        title: 'Receipts & Payments Report',
        subtitle: 'Approved receipt vouchers (bank, cash, online deposits and customer payments) and payment vouchers (bank, cash, online and supplier payments).',
        exportName: 'receipts-payments-report',
        filters: { party: 'both', voucherTypes: true },
        columns: [
            text('voucher_date', 'Date'),
            text('voucher_no', 'Voucher No', ALL, 'primary'),
            text('label', 'Type', MID),
            text('party_name', 'Party', MID),
            text('accounts', 'Accounts', WIDE),
            text('header_account', 'Bank / Cash', WIDE),
            text('ref_no', 'Reference', WIDE),
            text('cheque_no', 'Cheque No', WIDE),
            text('cheque_date', 'Cheque Date', WIDE),
            text('branch_name', 'Branch', WIDE),
            money('amount', 'Amount'),
        ],
        summary: [
            { key: 'count', label: 'Vouchers', kind: 'count' },
            { key: 'receipts', label: 'Receipts' },
            { key: 'payments', label: 'Payments' },
            { key: 'net', label: 'Receipts less payments', accent: true },
        ],
        searchPlaceholder: 'Voucher, reference, cheque or description',
    },
    'profit-loss': {
        title: 'Profit & Loss',
        subtitle: 'Revenue, cost of goods sold and expenses of the period, classed by the first digit of the account code. Cost of goods sold = opening stock + purchases - closing stock, with stock valued at the weighted average purchase cost on the day (the Stock Report\'s value).',
        exportName: 'profit-and-loss',
        filters: { requiresCompany: true },
        columns: [
            text('code', 'Code', MID),
            text('name', 'Account', ALL, 'primary'),
            known('amount', 'Amount'),
        ],
        summary: [
            { key: 'revenue', label: 'Revenue' },
            { key: 'opening_stock', label: 'Opening stock' },
            { key: 'purchases', label: '+ Purchases' },
            { key: 'closing_stock', label: '- Closing stock' },
            { key: 'cogs', label: '= Cost of goods sold' },
            { key: 'gross_profit', label: 'Gross profit' },
            { key: 'expenses', label: 'Expenses' },
            { key: 'net_profit', label: 'Net profit', accent: true },
            { key: 'uncosted_stock', label: 'Products without cost', kind: 'count' },
        ],
        searchPlaceholder: '',
    },
    'balance-sheet': {
        title: 'Balance Sheet',
        subtitle: 'Assets against liabilities and equity on the day, with the profit of the year to date in equity. That profit comes straight from the ledger, before the stock adjustment of the Profit & Loss. The difference must be zero.',
        exportName: 'balance-sheet',
        filters: { requiresCompany: true, asOf: true },
        columns: [
            text('code', 'Code', MID),
            text('name', 'Account', ALL, 'primary'),
            known('amount', 'Amount'),
        ],
        summary: [
            { key: 'total_assets', label: 'Total assets' },
            { key: 'total_liabilities', label: 'Liabilities' },
            { key: 'total_equity', label: 'Equity (with profit)' },
            { key: 'difference', label: 'Difference', accent: true },
        ],
        searchPlaceholder: '',
    },

    'sales-discount': {
        title: 'Sales Discount Report',
        subtitle: 'Every posted sale that gave a discount: what its lines gave and what the invoice-level discount gave. Drafts and quotations are left out. Defaults to the last 30 days.',
        exportName: 'sales-discount-report',
        filters: {},
        columns: [
            text('transaction_date', 'Date'),
            text('invoice_no', 'Invoice No', ALL, 'primary'),
            text('contact_name', 'Customer', MID),
            money('gross', 'List Value', WIDE),
            money('line_discount', 'Line Discount', MID),
            money('header_discount', 'Invoice Discount', MID),
            money('total_discount', 'Total Discount'),
            money('discount_percent', 'Discount %', MID),
            money('final_amount', 'Invoice Total', WIDE),
        ],
        summary: [
            { key: 'count', label: 'Discounted sales', kind: 'count' },
            { key: 'gross', label: 'List value' },
            { key: 'total_discount', label: 'Discount given', accent: true },
            { key: 'discount_percent', label: 'Discount % of list' },
        ],
        searchPlaceholder: 'Invoice or customer',
    },
    'purchase-price-trend': {
        title: 'Purchase Price Trend',
        subtitle: 'The weighted average, lowest and highest purchase rate of each product by month, and the change against its previous month. Defaults to the last 12 months.',
        exportName: 'purchase-price-trend',
        filters: {},
        columns: [
            text('product_name', 'Product', ALL, 'primary'),
            text('month', 'Month'),
            qty('quantity', 'Quantity', MID),
            money('average_rate', 'Average Rate'),
            money('min_rate', 'Lowest', WIDE),
            money('max_rate', 'Highest', WIDE),
            known('change_percent', 'Change %', MID),
        ],
        summary: [
            { key: 'count', label: 'Product-months', kind: 'count' },
            { key: 'products', label: 'Products', kind: 'count' },
            { key: 'rising', label: 'Prices rising', kind: 'count' },
            { key: 'falling', label: 'Prices falling', kind: 'count' },
        ],
        searchPlaceholder: 'Product name',
    },
    'supplier-performance': {
        title: 'Supplier Performance & Comparison',
        subtitle: 'Suppliers ranked by what was bought from them: returns and return rate, what was paid and what is still owed, the average order and the last purchase. Defaults to the last 365 days.',
        exportName: 'supplier-performance',
        filters: {},
        columns: [
            count('rank', 'Rank', MID),
            text('supplier_name', 'Supplier', ALL, 'primary'),
            count('purchase_count', 'Orders', MID),
            money('purchased', 'Purchased'),
            money('returned', 'Returned', WIDE),
            money('return_rate', 'Return %', MID),
            money('paid', 'Paid', WIDE),
            money('outstanding', 'Outstanding', MID),
            money('average_order', 'Average Order', WIDE),
            text('last_purchase', 'Last Purchase', WIDE),
        ],
        summary: [
            { key: 'suppliers', label: 'Suppliers', kind: 'count' },
            { key: 'purchased', label: 'Purchased' },
            { key: 'return_rate', label: 'Return rate %' },
            { key: 'outstanding', label: 'Outstanding', accent: true },
        ],
        searchPlaceholder: 'Supplier name',
    },
    backorder: {
        title: 'Backorder Report',
        subtitle: 'Purchase order lines still waiting for goods: ordered, received and still pending, and how long the order has been open.',
        exportName: 'backorder-report',
        filters: {},
        columns: [
            text('transaction_date', 'Ordered'),
            text('invoice_no', 'Order No', ALL, 'primary'),
            text('supplier_name', 'Supplier', MID),
            text('product_name', 'Product'),
            qty('ordered', 'Ordered', MID),
            qty('received', 'Received', WIDE),
            qty('pending', 'Pending'),
            count('days_open', 'Days Open', MID),
        ],
        summary: [
            { key: 'lines', label: 'Open lines', kind: 'count' },
            { key: 'orders', label: 'Orders', kind: 'count' },
            { key: 'pending', label: 'Quantity pending', accent: true },
            { key: 'oldest_days', label: 'Oldest (days)', kind: 'count' },
        ],
        searchPlaceholder: 'Order, product or supplier',
    },
    fsn: {
        title: 'Fast / Slow-Moving & Dead Stock',
        subtitle: 'Products with stock or sales, by how much sold in the period (default the last 90 days). No sale in the period is Non-moving (dead stock); of the rest, above the median sold quantity is Fast and the others Slow.',
        exportName: 'fsn-report',
        filters: {},
        columns: [
            text('product_name', 'Product', ALL, 'primary'),
            text('sku', 'SKU', WIDE),
            qty('stock', 'Stock'),
            qty('sold', 'Sold', MID),
            text('last_sale', 'Last Sale', WIDE),
            known('days_since_last_sale', 'Days Since', WIDE),
            text('class', 'Class', ALL),
        ],
        summary: [
            { key: 'products', label: 'Products', kind: 'count' },
            { key: 'fast', label: 'Fast', kind: 'count' },
            { key: 'slow', label: 'Slow', kind: 'count' },
            { key: 'non_moving', label: 'Non-moving', kind: 'count', accent: true },
            { key: 'dead_stock_units', label: 'Dead stock units' },
        ],
        searchPlaceholder: 'Product name',
    },
    'cash-collection': {
        title: 'Cash Collection Report',
        subtitle: 'Cash collections by the day collected, with who collected them, their status and what was kept as advance. Defaults to the last 30 days.',
        exportName: 'cash-collection-report',
        filters: { statuses: [{ value: 'all', label: 'All' }, { value: 'pending', label: 'Pending' }, { value: 'completed', label: 'Completed' }, { value: 'cancelled', label: 'Cancelled' }], defaultStatus: 'all' },
        columns: [
            text('collected_on', 'Collected'),
            text('reference', 'Reference', ALL, 'primary'),
            text('contact_name', 'Customer', MID),
            text('collector', 'Collector', WIDE),
            money('amount', 'Amount'),
            money('advance_amount', 'Advance', WIDE),
            text('status', 'Status', MID),
            text('completed_at', 'Completed', WIDE),
        ],
        summary: [
            { key: 'count', label: 'Collections', kind: 'count' },
            { key: 'total', label: 'Total' },
            { key: 'completed', label: 'Completed' },
            { key: 'pending', label: 'Pending', accent: true },
        ],
        searchPlaceholder: 'Reference, customer or collector',
    },
    'payment-account': {
        title: 'Payments by Payment Account',
        subtitle: 'Money received on sales and paid on purchases through each cash or bank account and method, by the day recorded. Defaults to the last 30 days.',
        exportName: 'payment-account-report',
        filters: {},
        columns: [
            text('account_code', 'Account', WIDE),
            text('account_name', 'Account Name', ALL, 'primary'),
            text('method', 'Method', MID),
            count('count', 'Payments', MID),
            money('received', 'Received'),
            money('paid', 'Paid'),
            money('net', 'Net', MID),
        ],
        summary: [
            { key: 'count', label: 'Payments', kind: 'count' },
            { key: 'received', label: 'Received' },
            { key: 'paid', label: 'Paid' },
            { key: 'net', label: 'Net', accent: true },
        ],
        searchPlaceholder: 'Account name or code',
    },
    'payment-age': {
        title: 'Payments by Age',
        subtitle: 'How long after the invoice date payments were made: 0-30, 31-60, 61-90 and over 90 days, for money received and money paid. Defaults to the last 30 days of payments.',
        exportName: 'payment-age-report',
        filters: {},
        columns: [
            text('direction', 'Direction', ALL, 'primary'),
            text('bucket', 'Age'),
            count('count', 'Payments', MID),
            money('amount', 'Amount'),
        ],
        summary: [
            { key: 'received', label: 'Received' },
            { key: 'received_over_60', label: 'Received after 60 days' },
            { key: 'paid', label: 'Paid' },
            { key: 'paid_over_60', label: 'Paid after 60 days', accent: true },
        ],
        searchPlaceholder: '',
    },
    'consolidated-branch': {
        title: 'Consolidated Multi-Branch Report',
        subtitle: 'The purchase and sale summary of each branch side by side, with the company total. Defaults to the last 30 days.',
        exportName: 'consolidated-branch-report',
        filters: { requiresCompany: true },
        columns: [
            text('branch_name', 'Branch', ALL, 'primary'),
            money('net_sales', 'Net Sales'),
            money('net_purchases', 'Net Purchases'),
            money('result', 'Sales less Purchases', MID),
            money('sales_due', 'Sales Due', WIDE),
            money('purchase_due', 'Purchase Due', WIDE),
        ],
        summary: [
            { key: 'branches', label: 'Branches', kind: 'count' },
            { key: 'net_sales', label: 'Net sales' },
            { key: 'net_purchases', label: 'Net purchases' },
            { key: 'result', label: 'Sales less purchases', accent: true },
        ],
        searchPlaceholder: '',
    },
    'financial-ratios': {
        title: 'Financial Ratios',
        subtitle: 'Margins, returns and leverage from the Balance Sheet on the end day and the Profit & Loss over the range (the financial year to date by default). No current ratio: the chart of accounts has no current / non-current flag.',
        exportName: 'financial-ratios',
        filters: { requiresCompany: true },
        columns: [
            text('group', 'Group', MID),
            text('label', 'Ratio', ALL, 'primary'),
            known('value', 'Value'),
            text('unit', 'Unit', MID),
            text('formula', 'Formula', WIDE),
        ],
        summary: [
            { key: 'gross_margin', label: 'Gross margin %' },
            { key: 'net_margin', label: 'Net margin %' },
            { key: 'debt_ratio', label: 'Debt ratio %' },
            { key: 'debt_to_equity', label: 'Debt to equity', accent: true },
        ],
        searchPlaceholder: '',
    },
    'cash-flow': {
        title: 'Cash Flow Statement',
        subtitle: 'Direct method: the cash and bank accounts\' movement by what the other side of each voucher was, as Operating, Investing and Financing (read from the account class and name). The financial year to date by default.',
        exportName: 'cash-flow-statement',
        filters: { requiresCompany: true },
        columns: [
            text('section', 'Section', MID),
            text('account_code', 'Account', WIDE),
            text('account_name', 'Counter Account', ALL, 'primary'),
            money('inflow', 'Cash In', MID),
            money('outflow', 'Cash Out', MID),
            money('net', 'Net'),
        ],
        summary: [
            { key: 'operating', label: 'Operating' },
            { key: 'investing', label: 'Investing' },
            { key: 'financing', label: 'Financing' },
            { key: 'net_change', label: 'Net change', accent: true },
            { key: 'opening_cash', label: 'Opening cash' },
            { key: 'closing_cash', label: 'Closing cash' },
        ],
        searchPlaceholder: '',
    },
    register: {
        title: 'Register, Z & Cashier Report',
        subtitle: 'Every POS shift opened in the range with its cashier, sales, cash expected, cash counted and variance. A closed shift shows its frozen Z report. Defaults to the last 30 days.',
        exportName: 'register-report',
        filters: { statuses: [{ value: 'all', label: 'All' }, { value: 'open', label: 'Open' }, { value: 'closed', label: 'Closed' }], defaultStatus: 'all' },
        columns: [
            text('opened_at', 'Opened'),
            text('cashier', 'Cashier', ALL, 'primary'),
            text('branch_name', 'Branch', WIDE),
            text('status', 'Status', MID),
            count('sales_count', 'Sales', MID),
            money('sales_total', 'Sales Total', MID),
            money('expected_cash', 'Expected Cash'),
            known('counted_cash', 'Counted', MID),
            known('variance', 'Variance'),
        ],
        summary: [
            { key: 'shifts', label: 'Shifts', kind: 'count' },
            { key: 'sales_total', label: 'Sales' },
            { key: 'variance', label: 'Net variance', accent: true },
            { key: 'short_shifts', label: 'Shifts short', kind: 'count' },
        ],
        searchPlaceholder: 'Cashier or branch',
    },
    'activity-summary': {
        title: 'Activity Log & User Audit Trail',
        subtitle: 'How many records each user created, updated and deleted, by kind of record. The entries themselves are in the Data Change History. Defaults to the last 30 days.',
        exportName: 'activity-summary',
        filters: {},
        columns: [
            text('user_name', 'User', ALL, 'primary'),
            text('model', 'Record', MID),
            count('created', 'Created', MID),
            count('updated', 'Updated', MID),
            count('deleted', 'Deleted', MID),
            count('total', 'Total'),
            text('last_activity', 'Last Activity', WIDE),
        ],
        summary: [
            { key: 'users', label: 'Users', kind: 'count' },
            { key: 'created', label: 'Created', kind: 'count' },
            { key: 'updated', label: 'Updated', kind: 'count' },
            { key: 'deleted', label: 'Deleted', kind: 'count' },
        ],
        searchPlaceholder: 'User or record kind',
    },
    'change-history': {
        title: 'Data Change History',
        subtitle: 'The Activity Log, one row per changed field, with the old and the new value. Search by a kind of record, a record number or a field name. Defaults to the last 30 days.',
        exportName: 'change-history',
        filters: {},
        columns: [
            text('created_at', 'When'),
            text('user_name', 'User', MID),
            text('model', 'Record', MID),
            count('record_id', 'No.', MID),
            text('event', 'Event', WIDE),
            text('field', 'Field', ALL, 'primary'),
            text('old_value', 'Old Value'),
            text('new_value', 'New Value'),
        ],
        summary: [
            { key: 'changes', label: 'Changes', kind: 'count' },
            { key: 'records', label: 'Records', kind: 'count' },
            { key: 'users', label: 'Users', kind: 'count' },
        ],
        searchPlaceholder: 'Record kind, number or field',
    },
    'price-history': {
        title: 'Product Cost & Price Change History',
        subtitle: 'Every change of a variation\'s purchase price, sell price, profit % and minimum or maximum price, with the old and new value, from the Activity Log. Defaults to the last 90 days.',
        exportName: 'price-change-history',
        filters: {},
        columns: [
            text('created_at', 'When'),
            text('product_name', 'Product', ALL, 'primary'),
            text('field_label', 'Field', MID),
            known('old_value', 'Old'),
            known('new_value', 'New'),
            known('change_percent', 'Change %', MID),
            text('user_name', 'User', WIDE),
        ],
        summary: [
            { key: 'changes', label: 'Changes', kind: 'count' },
            { key: 'products', label: 'Products', kind: 'count' },
            { key: 'increases', label: 'Increases', kind: 'count' },
            { key: 'decreases', label: 'Decreases', kind: 'count' },
        ],
        searchPlaceholder: 'Product name',
    },
    'cost-center-analysis': {
        title: 'Cost Center & Department Analysis',
        subtitle: 'Revenue, cost of goods sold and expenses of the approved vouchers, grouped by cost center, by department or by branch. Defaults to the last 30 days.',
        exportName: 'cost-center-analysis',
        filters: {
            requiresCompany: true,
            statusLabel: 'Group by',
            defaultStatus: 'cost_center',
            statuses: [
                { value: 'cost_center', label: 'Cost center' },
                { value: 'department', label: 'Department' },
                { value: 'branch', label: 'Branch' },
            ],
        },
        columns: [
            text('name', 'Group', ALL, 'primary'),
            money('revenue', 'Revenue'),
            money('cogs', 'Cost of goods sold', MID),
            money('expenses', 'Expenses'),
            money('net', 'Net'),
        ],
        summary: [
            { key: 'groups', label: 'Groups', kind: 'count' },
            { key: 'revenue', label: 'Revenue', kind: 'amount' },
            { key: 'expenses', label: 'Expenses', kind: 'amount' },
            { key: 'net', label: 'Net', kind: 'amount', accent: true },
        ],
        searchPlaceholder: 'Group name',
    },
    'budget-vs-actual': {
        title: 'Budget vs Actual',
        subtitle: 'Each budget line against what the approved ledger booked in the same branch, cost center and account. A positive variance is favourable. Defaults to the year so far; every month the range touches counts whole.',
        exportName: 'budget-vs-actual',
        filters: { requiresCompany: true },
        columns: [
            text('cost_center_name', 'Cost center', ALL, 'primary'),
            text('account_name', 'Account'),
            text('branch_name', 'Branch', WIDE),
            money('budget', 'Budget'),
            money('actual', 'Actual'),
            money('variance', 'Variance'),
            known('variance_percent', 'Variance %', MID),
            text('status', 'Status', MID),
        ],
        summary: [
            { key: 'lines', label: 'Budget lines', kind: 'count' },
            { key: 'budget', label: 'Expense budget', kind: 'amount' },
            { key: 'actual', label: 'Actual expenses', kind: 'amount' },
            { key: 'variance', label: 'Variance', kind: 'amount', accent: true },
            { key: 'over_budget', label: 'Lines over budget', kind: 'count' },
        ],
        searchPlaceholder: 'Cost center or account',
    },
    'warehouse-stock': {
        title: 'Warehouse Stock',
        subtitle: 'The stock of each product in each warehouse of each branch, on a day (default today). Stock from documents that name no warehouse is shown as Unassigned, so the warehouses of a branch always add up to its stock. Read-only.',
        exportName: 'warehouse-stock',
        filters: {
            asOf: true,
            warehouseFilter: true,
            statusLabel: 'Show',
            defaultStatus: 'all',
            statuses: [
                { value: 'all', label: 'All stock' },
                { value: 'assigned', label: 'In a warehouse' },
                { value: 'unassigned', label: 'Unassigned' },
            ],
        },
        columns: [
            text('product_name', 'Product', ALL, 'primary'),
            text('variation_name', 'Variation', WIDE),
            text('sku', 'SKU', WIDE),
            text('branch_name', 'Branch', MID),
            text('warehouse_name', 'Warehouse'),
            qty('on_hand', 'In warehouse'),
            qty('branch_total', 'Branch stock', MID),
            text('unit_name', 'Unit', WIDE),
        ],
        summary: [
            { key: 'rows', label: 'Rows', kind: 'count' },
            { key: 'warehouses', label: 'Warehouses', kind: 'count' },
            { key: 'assigned_qty', label: 'In warehouses', kind: 'count' },
            { key: 'unassigned_qty', label: 'Unassigned', kind: 'count' },
        ],
        searchPlaceholder: 'Product name or SKU',
    },
    'stock-movement-history': {
        title: 'Stock Movement History',
        subtitle: 'Every stock movement in the range, one per row with its document, branch and warehouse, going in or out in base units. Movements of documents that name no warehouse are Unassigned. Defaults to the last 30 days.',
        exportName: 'stock-movement-history',
        filters: { warehouseFilter: true },
        columns: [
            text('document_date', 'Date'),
            text('document_no', 'Document', ALL, 'primary'),
            text('movement', 'Movement'),
            text('product_name', 'Product'),
            text('sku', 'SKU', WIDE),
            text('branch_name', 'Branch', MID),
            text('warehouse_name', 'Warehouse', MID),
            qty('qty_in', 'In'),
            qty('qty_out', 'Out'),
            text('unit_name', 'Unit', WIDE),
        ],
        summary: [
            { key: 'movements', label: 'Movements', kind: 'count' },
            { key: 'qty_in', label: 'In', kind: 'count' },
            { key: 'qty_out', label: 'Out', kind: 'count' },
            { key: 'net', label: 'Net', kind: 'count', accent: true },
        ],
        searchPlaceholder: 'Product, SKU or document',
    },
    'warehouse-usage': {
        title: 'Warehouse Capacity & Usage',
        subtitle: 'Each active warehouse with its capacity, the stock it holds on a day (default today) and how full it is, in base units of the products. Set the capacity on the warehouse. Stock of documents that name no warehouse is in none of them.',
        exportName: 'warehouse-usage',
        filters: { asOf: true },
        columns: [
            text('warehouse_name', 'Warehouse', ALL, 'primary'),
            text('branch_name', 'Branch', MID),
            known('capacity', 'Capacity'),
            qty('used', 'In stock'),
            known('free', 'Free', MID),
            known('usage_percent', 'Used %'),
            text('status', 'Status', MID),
        ],
        summary: [
            { key: 'warehouses', label: 'Warehouses', kind: 'count' },
            { key: 'capacity', label: 'Capacity', kind: 'count' },
            { key: 'used', label: 'In stock', kind: 'count' },
            { key: 'over_capacity', label: 'Over capacity', kind: 'count', accent: true },
        ],
        searchPlaceholder: 'Warehouse name',
    },
    'serial-traceability': {
        title: 'Serial / IMEI Traceability Log',
        subtitle: 'Every movement of every serial number - received, moved between branches, sold, registered by hand or written off - with its document, branch and note. A movement whose document was deleted or is still a draft shows as Reversed: it no longer counts. Search a serial number to follow one unit from the supplier to the customer.',
        exportName: 'serial-traceability',
        filters: {
            statuses: [
                { value: '', label: 'All movements' },
                { value: 'receive', label: 'Received' },
                { value: 'sale', label: 'Sold' },
                { value: 'transfer_out', label: 'Transfer out' },
                { value: 'transfer_in', label: 'Transfer in' },
                { value: 'register', label: 'Registered' },
                { value: 'write_off', label: 'Written off' },
            ],
            statusLabel: 'Movement',
            defaultStatus: '',
        },
        columns: [
            text('document_date', 'Date'),
            text('serial_no', 'Serial / IMEI', ALL, 'primary'),
            text('product_name', 'Product'),
            text('movement', 'Movement'),
            text('direction', 'In / Out', WIDE),
            text('branch_name', 'Branch', MID),
            text('document_no', 'Document', MID),
            text('document_type', 'Type', WIDE),
            text('counts', 'Counts', MID),
            text('note', 'Note', WIDE),
        ],
        summary: [
            { key: 'movements', label: 'Movements', kind: 'count' },
            { key: 'serials', label: 'Serials', kind: 'count' },
            { key: 'reversed', label: 'Reversed', kind: 'count', accent: true },
        ],
        searchPlaceholder: 'Serial number, product or document',
    },
    'batch-expiry': {
        title: 'Batch Expiry Report',
        subtitle: 'Every batch that still has stock, per branch, with its expiry date and the days left, the earliest expiry first. Expiring soon means within 30 days. The end date keeps the batches that expire on or before it.',
        exportName: 'batch-expiry',
        filters: {
            statuses: [
                { value: '', label: 'All batches' },
                { value: 'Expired', label: 'Expired' },
                { value: 'Expiring soon', label: 'Expiring soon' },
                { value: 'OK', label: 'OK' },
                { value: 'No expiry date', label: 'No expiry date' },
            ],
            statusLabel: 'Expiry',
            defaultStatus: '',
            asOf: true,
        },
        columns: [
            text('product_name', 'Product', ALL, 'primary'),
            text('batch_no', 'Batch'),
            text('branch_name', 'Branch', MID),
            text('expiry_date', 'Expires'),
            known('days_left', 'Days left'),
            qty('qty', 'In stock'),
            text('status', 'Status'),
        ],
        summary: [
            { key: 'batches', label: 'Batches', kind: 'count' },
            { key: 'qty', label: 'In stock', kind: 'count' },
            { key: 'expired_qty', label: 'Expired', kind: 'count', accent: true },
            { key: 'expiring_qty', label: 'Expiring soon', kind: 'count' },
        ],
        searchPlaceholder: 'Product or batch number',
    },
};

export const ADJUSTMENT_TYPES = [
    { value: 'all', label: 'All' },
    { value: 'normal', label: 'Normal' },
    { value: 'abnormal', label: 'Abnormal' },
    { value: 'unboxing', label: 'Unboxing' },
    { value: 'opening', label: 'Opening' },
];

export const PAYMENT_METHODS = [
    { value: 'all', label: 'All' },
    { value: 'cash', label: 'Cash' },
    { value: 'card', label: 'Card' },
    { value: 'cheque', label: 'Cheque' },
    { value: 'bank_transfer', label: 'Bank transfer' },
    { value: 'other', label: 'Other' },
];

export const PAYMENT_STATUSES = [
    { value: 'all', label: 'All' },
    { value: 'paid', label: 'Paid' },
    { value: 'partial', label: 'Partial' },
    { value: 'due', label: 'Due' },
];

export default function useReport(report: ReportKey) {
    const { select_data, changeOrderFn, checkAllFn, fetchWithRetry, Notify } = useCommons();

    const config = REPORTS[report];

    const state = reactive({
        records: {
            data: [],
            from: 0,
            to: 0,
            total: 0,
            last_page: 0,
            current_page: 1,
        },
        search: {
            sort_by: '',
            sort_type: 'desc' as 'asc' | 'desc',
            show_record: 10,
            page: 1,
            search: '',
            company_id: '' as string | number,
            branch_id: '' as string | number,
            contact_id: '' as string | number,
            customer_group_id: '' as string | number,
            contact_type: 'all',
            include_zero: false,
            product_id: '' as string | number,
            brand_id: '' as string | number,
            category_id: '' as string | number,
            top: 10,
            tax_side: 'all',
            by_branch: false,
            from_branch_id: '' as string | number,
            to_branch_id: '' as string | number,
            warehouse_id: '' as string | number,
            account_code: '',
            account_group: '' as string | number,
            voucher_type: 'all',
            status: config.filters.defaultStatus ?? '',
            payment_status: 'all',
            method: 'all',
            adjustment_type: 'all',
            start_date: '',
            end_date: '',
        },
        loading: false,
        modalLoading: true,
        edit_ids: [],
        selectAll: false,
        trash_count: 0,
        loadingIds: new Set(),
    });

    const summary = ref<Record<string, unknown>>({});

    const changeOrder = async (event: Event) => changeOrderFn(event, state);

    const checkAll = async (id: number) => checkAllFn(id, state);

    /** Loads the current page of rows and the totals for the filters in `state.search`. */
    const load = async (): Promise<void> => {
        if (config.filters.requiresCompany && !state.search.company_id) {
            state.records = { data: [], from: 0, to: 0, total: 0, last_page: 0, current_page: 1 };
            summary.value = {};

            return;
        }

        state.loading = true;

        try {
            const response = await fetchWithRetry(window.axios.get, `${API_ENDPOINTS.reports}/${report}`, {
                params: { ...state.search, cur_page: state.search.page },
            });

            state.records = response.data.data;
            state.trash_count = response.data.trash_count;
            summary.value = response.data.summary ?? {};

            if (state.search.page > state.records.last_page && state.records.last_page > 0) {
                state.search.page = state.records.last_page;
            }
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error) && error.response?.data?.message !== 'Unauthenticated.') {
                Notify(error.response?.data?.message || 'Unable to load the report', 'alert');
            }
        } finally {
            state.loading = false;
        }
    };

    return { state, summary, config, load, changeOrder, checkAll, select_data };
}
