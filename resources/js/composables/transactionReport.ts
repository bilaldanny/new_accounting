import { reactive, ref } from 'vue';
import { API_ENDPOINTS } from './apiEndpoints';
import useCommons from './common';

/**
 * The transaction list reports under Reports (TransactionReportController). One page component shows
 * all of them; this file is the only place that says how they differ: title, filters, columns and the
 * totals strip. The counting rules (which statuses count, inclusive dates) live on the server.
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
    | 'vouchers';

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

export default function useTransactionReport(report: ReportKey) {
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
