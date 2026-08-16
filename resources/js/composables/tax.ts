import { reactive, ref } from 'vue';
import useCommons from './common';
import { API_ENDPOINTS } from './apiEndpoints';

const sharedTaxesdata = ref<Array<{ id: number; text: string; name: string; percentage: number }>>([]);

export default function useTaxes() {
    interface QueryParams {
        sort_by: string;
        sort_type: 'asc' | 'desc';
        show_record: number;
        page: number;
        search: string;
        company_id?: string | number;
        status?: string;
    }

    const formData = ref({
        id: null as number | null,
        company_id: '',
        name: '',
        percentage: '',
        sub_tax: [] as number[],
        type: 0,
        status: true,
    });

    const defaultFormData = ref({
        id: null as number | null,
        company_id: '',
        name: '',
        percentage: '',
        sub_tax: [] as number[],
        type: 0,
        status: true,
    });

    const groupFormData = ref({
        id: null as number | null,
        company_id: '',
        name: '',
        sub_tax: [] as number[],
        type: 1,
        status: true,
    });

    const defaultGroupFormData = ref({
        id: null as number | null,
        company_id: '',
        name: '',
        sub_tax: [] as number[],
        type: 1,
        status: true,
    });

    const { Notify, select_data, fetchWithRetry, changeStateFn, changeOrderFn, deleteFn, checkAllFn, getData } = useCommons();

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
            sort_by: 'created_at',
            sort_type: 'desc',
            show_record: 10,
            page: 1,
            search: '',
            status: 'all',
            company_id: '',
        },
        loading: false,
        modalLoading: true,
        edit_ids: [],
        selectAll: false,
        trash_count: 0,
        loadingIds: new Set<number>(),
    });

    const taxesdata = sharedTaxesdata;

    const changeStatus = async (ids: Array<number>, status: string | null) => {
        return changeStateFn(`${API_ENDPOINTS.taxes}/statusupdate`, ids, status, state);
    };

    const changeOrder = async (event: Event) => {
        return changeOrderFn(event, state);
    };

    const deleteRecord = async (ids: Array<number>) => {
        return deleteFn(`${API_ENDPOINTS.taxes}/bulk_delete`, ids, state);
    };

    const checkAll = async (id?: number) => {
        return checkAllFn(id, state);
    };

    const getTaxes = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.taxes, data, state);
    };

    const getEditData = async (id: number): Promise<number> => {
        if (!id) {
            return 0;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, `${API_ENDPOINTS.taxes}/${id}`);
            const record = response.data;

            if (Number(record.type) === 1) {
                groupFormData.value = {
                    id: record.id,
                    company_id: record.company_id ?? '',
                    name: record.name ?? '',
                    sub_tax: Array.isArray(record.sub_tax) ? record.sub_tax.map(Number) : [],
                    type: 1,
                    status: Boolean(record.status),
                };

                return 1;
            }

            formData.value = {
                id: record.id,
                company_id: record.company_id ?? '',
                name: record.name ?? '',
                percentage: record.percentage ?? '',
                sub_tax: [],
                type: 0,
                status: Boolean(record.status),
            };

            return 0;
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error)) {
                if (error.response?.data?.message !== 'Unauthenticated.') {
                    Notify(error.response?.data?.message || 'An error occurred', 'alert');
                }
            } else {
                Notify('Unexpected error occurred', 'alert');
            }

            return 0;
        }
    };

    const fetchTaxOptions = async (companyId: string | number) => {
        if (!companyId) {
            taxesdata.value = [];

            return;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, API_ENDPOINTS.fetchTaxes, {
                params: { company_id: companyId },
            });
            taxesdata.value = response.data;
        } catch {
            taxesdata.value = [];
        }
    };

    return {
        state,
        formData,
        defaultFormData,
        groupFormData,
        defaultGroupFormData,
        taxesdata,
        changeStatus,
        changeOrder,
        deleteRecord,
        checkAll,
        getTaxes,
        getEditData,
        fetchTaxOptions,
        Notify,
        select_data,
    };
}
