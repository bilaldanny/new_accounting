import { computed, reactive, ref } from 'vue';
import useCommons from './common';
import { API_ENDPOINTS } from './apiEndpoints';

export type OpeningBalanceAccount = {
    id: number;
    code?: string | null;
    name?: string | null;
    opening_balance: number | string;
    acc_nature: 'cr' | 'dr';
    acc_type?: 't' | 'c';
    text?: string;
};

export default function useAccountBalances() {
    const { Notify, fetchWithRetry } = useCommons();

    const obAccounts = ref<Array<{ id: number | string; text?: string; name?: string }>>([]);
    const financialYears = ref<Array<{ id: number | string; text?: string }>>([]);

    const formData = reactive({
        company_id: '',
        branch_id: '',
        financial_id: '',
        account_id: '',
        accounts: [] as OpeningBalanceAccount[],
    });

    const state = reactive({
        loading: false,
        saving: false,
        hasLoaded: false,
    });

    const grandTotal = computed(() =>
        formData.accounts.reduce(
            (total, account) => total + Number(account.opening_balance || 0),
            0,
        ),
    );

    const fetchObAccounts = async (
        companyId: string | number | null | undefined,
        branchId: string | number | null | undefined,
    ) => {
        if (! companyId || ! branchId) {
            obAccounts.value = [];
            return;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, API_ENDPOINTS.fetchObAccounts, {
                params: {
                    company_id: companyId,
                    branch_id: branchId,
                },
            });

            obAccounts.value = response.data;
        } catch (error: unknown) {
            const axiosError = error as { response?: { data?: { message?: string } } };

            if (axiosError.response?.data?.message !== 'Unauthenticated.') {
                Notify(axiosError.response?.data?.message || 'An error occurred', 'alert');
            }
        }
    };

    const fetchFinancialYears = async (companyId: string | number | null | undefined) => {
        if (! companyId) {
            financialYears.value = [];
            return;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, API_ENDPOINTS.fetchFinancialYears, {
                params: {
                    company_id: companyId,
                },
            });

            financialYears.value = response.data;
        } catch (error: unknown) {
            const axiosError = error as { response?: { data?: { message?: string } } };

            if (axiosError.response?.data?.message !== 'Unauthenticated.') {
                Notify(axiosError.response?.data?.message || 'An error occurred', 'alert');
            }
        }
    };

    const fetchBalance = async (
        companyId?: string | number | null,
        branchId?: string | number | null,
    ) => {
        const resolvedCompanyId = companyId ?? formData.company_id;
        const resolvedBranchId = branchId ?? formData.branch_id;

        if (
            ! resolvedCompanyId ||
            ! resolvedBranchId ||
            ! formData.financial_id ||
            ! formData.account_id
        ) {
            formData.accounts = [];
            state.hasLoaded = false;

            return;
        }

        state.loading = true;

        try {
            const response = await fetchWithRetry(
                window.axios.get,
                API_ENDPOINTS.accountBalancesFetchBalance,
                {
                    params: {
                        company_id: resolvedCompanyId,
                        branch_id: resolvedBranchId,
                        financial_id: formData.financial_id,
                        account_id: formData.account_id,
                    },
                },
            );

            formData.accounts = response.data;
            state.hasLoaded = true;
        } catch (error: unknown) {
            const axiosError = error as { response?: { data?: { message?: string } } };

            if (axiosError.response?.data?.message !== 'Unauthenticated.') {
                Notify(axiosError.response?.data?.message || 'An error occurred', 'alert');
            }

            formData.accounts = [];
            state.hasLoaded = true;
        } finally {
            state.loading = false;
        }
    };

    const saveBalances = async (
        companyId?: string | number | null,
        branchId?: string | number | null,
    ) => {
        if (formData.accounts.length === 0) {
            return;
        }

        const resolvedCompanyId = companyId ?? formData.company_id;
        const resolvedBranchId = branchId ?? formData.branch_id;

        state.saving = true;

        try {
            const response = await fetchWithRetry(window.axios.post, API_ENDPOINTS.accountBalances, {
                company_id: resolvedCompanyId,
                branch_id: resolvedBranchId,
                financial_id: formData.financial_id,
                accounts: formData.accounts.map((account) => ({
                    id: account.id,
                    opening_balance: account.opening_balance,
                    acc_nature: account.acc_nature,
                })),
            });

            Notify(response.data.message, 'success');
        } catch (error: unknown) {
            const axiosError = error as { response?: { data?: { message?: string; errormessage?: string } } };

            Notify(
                axiosError.response?.data?.message
                    || axiosError.response?.data?.errormessage
                    || 'An error occurred',
                'alert',
            );
        } finally {
            state.saving = false;
        }
    };

    const resetAccounts = () => {
        formData.accounts = [];
        state.hasLoaded = false;
    };

    return {
        formData,
        state,
        obAccounts,
        financialYears,
        grandTotal,
        fetchObAccounts,
        fetchFinancialYears,
        fetchBalance,
        saveBalances,
        resetAccounts,
    };
}
