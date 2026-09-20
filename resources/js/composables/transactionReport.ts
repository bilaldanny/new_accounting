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
    | 'supplier-aging';

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
    party?: 'customer' | 'supplier';
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
