export const colThird = { container: 4, label: 12, wrapper: 12 };
export const colHalf = { container: 6, label: 12, wrapper: 12 };
export const colFull = { container: 12, label: 12, wrapper: 12 };

export const currencyPlacementItems = [
    { value: 'ba', label: 'Before Amount' },
    { value: 'aa', label: 'After Amount' },
];

export const searchTypeItems = [
    { value: 'searchbox', label: 'SearchBar' },
    { value: 'selectbox', label: 'SelectBox' },
];

export const financialMonthItems = [
    { value: '1', label: 'January' },
    { value: '2', label: 'February' },
    { value: '3', label: 'March' },
    { value: '4', label: 'April' },
    { value: '5', label: 'May' },
    { value: '6', label: 'June' },
    { value: '7', label: 'July' },
    { value: '8', label: 'August' },
    { value: '9', label: 'September' },
    { value: '10', label: 'October' },
    { value: '11', label: 'November' },
    { value: '12', label: 'December' },
];

export const accountingMethodItems = [
    { value: 'fifo', label: 'FIFO (First In First Out)' },
    { value: 'lifo', label: 'LIFO (Last In First Out)' },
];

export const dateFormatItems = [
    { value: 'DD-MM-Y', label: 'DD-MM-Y' },
    { value: 'MM-DD-Y', label: 'MM-DD-Y' },
    { value: 'DD/MM/Y', label: 'DD/MM/Y' },
    { value: 'MM/DD/Y', label: 'MM/DD/Y' },
];

export const timeFormatItems = [
    { value: '12', label: '12 Hour' },
    { value: '24', label: '24 Hour' },
];

export const posUnitItems = [
    { value: '0', label: 'Converted Current Stock' },
    { value: '1', label: 'Ask Unit Before add product to cart' },
];

export const prefixFields = [
    { name: 'purchase_order', label: 'Purchase Order', placeholder: 'Purchase Order' },
    { name: 'purchase_return', label: 'Purchase Return', placeholder: 'Purchase Return' },
    { name: 'stock_transfer', label: 'Stock Transfer', placeholder: 'Stock Transfer' },
    { name: 'stock_adjustment', label: 'Stock Adjustment', placeholder: 'Stock Adjustment' },
    { name: 'sell_return', label: 'Sell Return', placeholder: 'Sell Return' },
    { name: 'expenses', label: 'Expenses', placeholder: 'Expenses' },
    { name: 'supplier', label: 'Supplier', placeholder: 'Supplier' },
    { name: 'customer', label: 'Customer', placeholder: 'Customer' },
    { name: 'bank', label: 'Bank', placeholder: 'Bank' },
    { name: 'product', label: 'Product', placeholder: 'Product' },
    { name: 'purchase_payment', label: 'Purchase Payment', placeholder: 'Purchase Payment' },
    { name: 'sell_payment', label: 'Sell Payment', placeholder: 'Sell Payment' },
    { name: 'expense_payment', label: 'Expense Payment', placeholder: 'Expense Payment' },
    { name: 'business_location', label: 'Business Location', placeholder: 'Business Location' },
    { name: 'subscription_no', label: 'Subscription No', placeholder: 'Subscription No' },
    { name: 'draft', label: 'Draft', placeholder: 'Draft' },
    { name: 'invoice', label: 'Invoice', placeholder: 'Invoice' },
    { name: 'opening_stock', label: 'Opening Stock', placeholder: 'Opening Stock' },
    { name: 'grn', label: 'GRN', placeholder: 'GRN' },
    { name: 'gin', label: 'GIN', placeholder: 'GIN' },
];

export const approvalFields = [
    {
        name: 'purchase_approval',
        label: 'Purchase Order Approval',
        hint: 'Enable or disable approval of purchase order. This will remove approval step for purchase order.',
    },
    {
        name: 'auto_grn',
        label: 'Auto GRN',
        hint: 'Enable or disable automatically create of good receiving note. This will remove Receiving Note Create Step.',
    },
    {
        name: 'sell_approval',
        label: 'Sell Order Approval',
        hint: 'Enable or disable approval of sell order. This will remove approval step for sell order.',
    },
    {
        name: 'auto_gin',
        label: 'Auto GIN',
        hint: 'Enable or disable automatically create of good Issue note. This will remove Issue Note Create Step.',
    },
    {
        name: 'journal_entry',
        label: 'Journal Entry Approval',
        hint: 'Enable or disable approval of journal entry. This will remove approval step for journal entry.',
    },
    {
        name: 'cash_collection',
        label: 'Cash Collection Approval',
        hint: 'Enable or disable approval of Cash Collection. This will remove approval step for Cash Collection.',
    },
    {
        name: 'payment',
        label: 'Payment Approval',
        hint: 'Enable or disable Add Payment Option Of Invoice. This will remove step Add Payment To Clear Invoice.',
    },
    {
        name: 'limit_account',
        label: 'Show Limit Accounts',
        hint: 'Enable or disable Limitation Of Account. This will only show those chart of account which are required.',
    },
    {
        name: 'show_sku',
        label: 'Show Product Sku',
        hint: 'Enable or disable Display Of Product Sku. This will show product sku.',
    },
];

export type AccountSetupItem = {
    key?: string;
    name?: string;
    value?: string | number;
};

export function accountOptionsForKey(
    key: string | undefined,
    parentaccountdata: Array<{ id: string | number; text: string }>,
    parentsaleaccountdata: Array<{ id: string | number; text: string }>,
    parentpurchaseaccountdata: Array<{ id: string | number; text: string }>,
) {
    if (key === 'localsales' || key === 'exportsale') {
        return parentsaleaccountdata;
    }

    if (key === 'importpurchase' || key === 'localpurchase') {
        return parentpurchaseaccountdata;
    }

    return parentaccountdata;
}
