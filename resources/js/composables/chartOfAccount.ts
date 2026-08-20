import { reactive, ref } from 'vue';
import useCommons from './common';
import { API_ENDPOINTS } from './apiEndpoints';

export type ChartOfAccountNode = {
    id: number;
    parent_id?: number | string | null;
    company_id?: number | string | null;
    branch_id?: number | string | null;
    code?: string;
    name?: string;
    acc_type?: 't' | 'c';
    acc_nature?: 'cr' | 'dr';
    pl?: boolean;
    bs?: boolean;
    active?: boolean;
    children?: ChartOfAccountNode[];
};

export default function useChartOfAccounts() {
    const formData = ref({
        id: null as number | null,
        company_id: '',
        branch_id: '',
        parent_id: '',
        code: '',
        name: '',
        acc_type: 't',
        acc_nature: 'dr',
        bs: false,
        pl: false,
        active: true,
    });

    const defaultFormData = ref({
        id: null as number | null,
        company_id: '',
        branch_id: '',
        parent_id: '',
        code: '',
        name: '',
        acc_type: 't',
        acc_nature: 'dr',
        bs: false,
        pl: false,
        active: true,
    });

    const controlAccounts = ref<Array<{
        id: number | string;
        parent_id?: number | string | null;
        code?: string;
        text?: string;
        name?: string;
        bs?: boolean;
        pl?: boolean;
        acc_type?: 't' | 'c';
    }>>([]);

    const { Notify, fetchWithRetry } = useCommons();

    const state = reactive({
        records: [] as ChartOfAccountNode[],
        search: {
            company_id: '',
            branch_id: '',
            status: 'all',
        },
        loading: false,
        modalLoading: true,
        edit_ids: [] as number[],
        selectAll: false,
        trash_count: 0,
        loadingIds: new Set<number>(),
    });

    const getChartOfAccounts = async (params: {
        company_id?: string | number;
        branch_id?: string | number;
        status?: string;
    }) => {
        state.loading = true;

        try {
            const response = await fetchWithRetry(window.axios.get, API_ENDPOINTS.chartOfAccounts, {
                params: {
                    company_id: params.company_id || undefined,
                    branch_id: params.branch_id || undefined,
                    status: params.status ?? 'all',
                },
            });

            const payload = response.data;
            state.records = Array.isArray(payload) ? payload : [];
        } catch (error: unknown) {
            state.records = [];

            if (window.axios.isAxiosError(error)) {
                if (error.response?.data?.message !== 'Unauthenticated.') {
                    Notify(error.response?.data?.message || 'An error occurred', 'alert');
                }
            } else {
                Notify('Unexpected error occurred', 'alert');
            }
        } finally {
            state.loading = false;
        }
    };

    const fetchControlAccounts = async (companyId: string | number, branchId: string | number) => {
        if (! companyId || ! branchId) {
            controlAccounts.value = [];

            return;
        }

        const response = await fetchWithRetry(window.axios.get, API_ENDPOINTS.fetchControlAccounts, {
            params: {
                company_id: companyId,
                branch_id: branchId,
            },
        });

        controlAccounts.value = response.data ?? [];
    };

    const generateAccountCode = async (
        parentId: string | number,
        companyId: string | number,
        branchId: string | number,
        accType: string = 't',
    ) => {
        if (! parentId || ! companyId || ! branchId) {
            return;
        }

        const response = await fetchWithRetry(window.axios.get, API_ENDPOINTS.chartOfAccountGenerateCode, {
            params: {
                parent_id: parentId,
                company_id: companyId,
                branch_id: branchId,
                acc_type: accType,
            },
        });

        formData.value.code = response.data?.code ?? '';
    };

    const applyParentClassification = async (
        parentId: string | number,
        companyId: string | number,
        branchId: string | number,
        accGroup: string = 't',
        formRef: { update?: (values: Record<string, unknown>) => void } | null = null,
    ) => {
        if (! parentId || ! companyId || ! branchId) {
            return;
        }

        const response = await fetchWithRetry(window.axios.get, API_ENDPOINTS.chartOfAccountResolveFromParent, {
            params: {
                parent_id: parentId,
                company_id: companyId,
                branch_id: branchId,
                acc_type: accGroup,
            },
        });

        const metadata = response.data ?? {};

        formData.value.bs = Boolean(metadata.bs);
        formData.value.pl = Boolean(metadata.pl);

        if (metadata.default_nature) {
            formData.value.acc_nature = metadata.default_nature;
        }

        formRef?.update?.({
            bs: formData.value.bs,
            pl: formData.value.pl,
            acc_nature: formData.value.acc_nature,
        });
    };

    const getEditData = async (id: number) => {
        if (! id) {
            return;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, `${API_ENDPOINTS.chartOfAccounts}/${id}`);
            formData.value = {
                ...defaultFormData.value,
                ...response.data,
            };
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error)) {
                if (error.response?.data?.message !== 'Unauthenticated.') {
                    Notify(error.response?.data?.message || 'An error occurred', 'alert');
                }
            } else {
                Notify('Unexpected error occurred', 'alert');
            }
        }
    };

    return {
        state,
        formData,
        defaultFormData,
        controlAccounts,
        getChartOfAccounts,
        fetchControlAccounts,
        generateAccountCode,
        applyParentClassification,
        getEditData,
        Notify,
    };
}
