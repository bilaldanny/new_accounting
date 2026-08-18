import { ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import useCommons from './common';
import { API_ENDPOINTS } from './apiEndpoints';

export const defaultPurchaseColumns = () => [
    { name: 'Packing Quantity', show: true },
    { name: 'Unit Cost (Before Discount)', show: true },
    { name: 'Discount %', show: true },
    { name: 'Unit Cost (Before Tax)', show: true },
    { name: 'Subtotal (Before Tax)', show: true },
    { name: 'Product Tax', show: true },
    { name: 'Net Cost', show: true },
    { name: 'Line Total', show: true },
    { name: 'Profit Margin %', show: true },
    { name: 'Unit Selling Price (Inc. tax)', show: true },
];

const phoneFields = ['phone', 'cell', 'whatsapp_no'] as const;

function applyPhoneDefaults<T extends Record<string, unknown>>(data: T, defaultDialCode: string): T {
    phoneFields.forEach((field) => {
        const value = data[field];

        if (value === null || value === undefined || value === '') {
            data[field] = defaultDialCode;
        }
    });

    return data;
}

export default function useCompanySettings() {
    const { props: pageProps } = usePage();
    const defaultDialCode = String(pageProps.dailCode ?? '');

    const { Notify, fetchWithRetry, fetchBranch, branchesdata } = useCommons();

    const loading = ref(false);
    const saving = ref(false);
    const currenciesdata = ref([]);
    const timezonesdata = ref([]);
    const customersdata = ref([]);
    const parentaccountdata = ref([]);
    const parentsaleaccountdata = ref([]);
    const parentpurchaseaccountdata = ref([]);

    const defaultFormData = {
        id: null,
        company_id: '',
        branch_id: '',
        business_name: '',
        start_date: '',
        currency_placement: '',
        currency_id: '',
        profit_percent: '',
        logo: '',
        logo_url: '',
        timezone_id: '',
        financial_start_month: '',
        date_format: '',
        time_format: '',
        search_type: 'searchbox',
        accounting_method: 'lifo',
        default_customer: '',
        default_pos_unit: '0',
        update_packing_qty: false,
        purchase_column: defaultPurchaseColumns(),
        transaction_edit_days: '',
        email: '',
        phone: defaultDialCode,
        cell: defaultDialCode,
        whatsapp_no: defaultDialCode,
        fb_link: '',
        address: '',
        country_id: '',
        state_id: '',
        city_id: '',
        account_setup: [],
        purchase_order: 'PO',
        purchase_return: 'PR',
        stock_transfer: 'ST',
        stock_adjustment: 'SA',
        sell_return: 'SR',
        invoice: 'INV',
        expenses: 'EXP',
        supplier: 'SU',
        customer: 'CU',
        bank: 'BA',
        product: 'PRO',
        purchase_payment: 'PP',
        sell_payment: 'SP',
        expense_payment: 'EP',
        business_location: 'BL',
        subscription_no: 'SN',
        draft: 'DRA',
        opening_stock: 'OS',
        grn: 'GRN',
        gin: 'GIN',
        purchase_approval: false,
        sell_approval: false,
        journal_entry: false,
        show_sku: false,
        cash_collection: false,
        payment: false,
        limit_account: false,
        auto_grn: false,
        auto_gin: false,
    };

    const formData = ref({ ...defaultFormData });

    const fetchCurrencies = async () => {
        try {
            const response = await fetchWithRetry(window.axios.get, API_ENDPOINTS.fetchCurrencies);
            currenciesdata.value = response.data;
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error) && error.response?.data?.message !== 'Unauthenticated.') {
                Notify(error.response?.data?.message || 'Unable to load currencies', 'alert');
            }
        }
    };

    const fetchTimezones = async () => {
        try {
            const response = await fetchWithRetry(window.axios.get, API_ENDPOINTS.fetchTimezones);
            timezonesdata.value = response.data;
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error) && error.response?.data?.message !== 'Unauthenticated.') {
                Notify(error.response?.data?.message || 'Unable to load timezones', 'alert');
            }
        }
    };

    const loadBranchOptions = async (companyId: string | number) => {
        if (!companyId) {
            branchesdata.value = [];
            return;
        }

        await fetchBranch(String(companyId));
    };

    const fetchCustomers = async (companyId: string | number, branchId: string | number) => {
        if (!companyId || !branchId) {
            customersdata.value = [];
            return;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, API_ENDPOINTS.fetchCustomers, {
                params: { company_id: companyId, branch_id: branchId },
            });
            customersdata.value = response.data;
        } catch {
            customersdata.value = [];
        }
    };

    const fetchParentAccounts = async (companyId: string | number, branchId: string | number) => {
        if (!companyId || !branchId) {
            parentaccountdata.value = [];
            return;
        }

        const response = await fetchWithRetry(window.axios.get, API_ENDPOINTS.fetchParentAccounts, {
            params: { company_id: companyId, branch_id: branchId },
        });
        parentaccountdata.value = response.data;
    };

    const fetchParentSaleAccounts = async (
        companyId: string | number,
        branchId: string | number,
        parentId: string | number,
    ) => {
        const response = await fetchWithRetry(window.axios.get, API_ENDPOINTS.fetchParentSaleAccounts, {
            params: { company_id: companyId, branch_id: branchId, parent_id: parentId },
        });
        parentsaleaccountdata.value = response.data;
    };

    const fetchParentPurchaseAccounts = async (
        companyId: string | number,
        branchId: string | number,
        parentId: string | number,
    ) => {
        const response = await fetchWithRetry(window.axios.get, API_ENDPOINTS.fetchParentPurchaseAccounts, {
            params: { company_id: companyId, branch_id: branchId, parent_id: parentId },
        });
        parentpurchaseaccountdata.value = response.data;
    };

    const getCompanySetting = async (companyId: number | string, branchId: number | string = '') => {
        if (!companyId) {
            return;
        }

        loading.value = true;

        try {
            const response = await fetchWithRetry(
                window.axios.get,
                `${API_ENDPOINTS.companySettings}/${companyId}`,
                {
                    params: branchId ? { branch_id: branchId } : {},
                },
            );

            formData.value = applyPhoneDefaults({
                ...defaultFormData,
                ...response.data.companySetting,
                branch_id: branchId || formData.value.branch_id || '',
                account_setup: response.data.account_setup ?? formData.value.account_setup ?? [],
                purchase_column: response.data.companySetting?.purchase_column ?? defaultPurchaseColumns(),
            }, defaultDialCode);

            const saleAccount = formData.value.account_setup.find((item: { key?: string }) => item.key === 'sale');
            const purchaseAccount = formData.value.account_setup.find((item: { key?: string }) => item.key === 'purchase');

            if (branchId) {
                await fetchParentAccounts(companyId, branchId);

                if (saleAccount?.value) {
                    await fetchParentSaleAccounts(companyId, branchId, saleAccount.value);
                }

                if (purchaseAccount?.value) {
                    await fetchParentPurchaseAccounts(companyId, branchId, purchaseAccount.value);
                }
            }
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error)) {
                if (error.response?.data?.message !== 'Unauthenticated.') {
                    Notify(error.response?.data?.message || 'Unable to load company settings', 'alert');
                }
            } else {
                Notify('Unexpected error occurred', 'alert');
            }
        } finally {
            loading.value = false;
        }
    };

    const saveCompanySetting = async (companyId: number | string, payload: Record<string, unknown>) => {
        saving.value = true;

        try {
            const response = await fetchWithRetry(
                window.axios.put,
                `${API_ENDPOINTS.companySettings}/${companyId}`,
                payload,
            );

            formData.value = applyPhoneDefaults({
                ...formData.value,
                ...response.data.companySetting,
                branch_id: payload.branch_id ?? formData.value.branch_id,
                account_setup: response.data.account_setup ?? formData.value.account_setup,
            }, defaultDialCode);

            Notify(response.data.message || 'Successfully Saved', 'success');

            return response.data;
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error)) {
                if (error.response?.data?.message !== 'Unauthenticated.') {
                    Notify(error.response?.data?.message || 'Unable to save company settings', 'alert');
                }
            } else {
                Notify('Unexpected error occurred', 'alert');
            }

            throw error;
        } finally {
            saving.value = false;
        }
    };

    return {
        loading,
        saving,
        formData,
        defaultFormData,
        currenciesdata,
        timezonesdata,
        branchesdata,
        customersdata,
        parentaccountdata,
        parentsaleaccountdata,
        parentpurchaseaccountdata,
        fetchCurrencies,
        fetchTimezones,
        loadBranchOptions,
        fetchCustomers,
        fetchParentAccounts,
        fetchParentSaleAccounts,
        fetchParentPurchaseAccounts,
        getCompanySetting,
        saveCompanySetting,
        Notify,
    };
}
