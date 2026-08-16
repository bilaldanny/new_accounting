import { reactive, ref } from 'vue';
import useCommons from './common';
import { API_ENDPOINTS } from './apiEndpoints';

export default function useTimezones() {
    interface QueryParams {
        sort_by: string;
        sort_type: 'asc' | 'desc';
        show_record: number;
        page: number;
        search: string;
    }

    const formData = ref({
        name: '',
    });

    const defaultFormData = ref({
        name: '',
    });

    const { Notify, select_data, fetchWithRetry, changeOrderFn, deleteFn, checkAllFn, getData, restoreFn } = useCommons();

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
        },
        loading: false,
        modalLoading: true,
        edit_ids: [],
        selectAll: false,
        trash_count: 0,
        loadingIds: new Set(),
    });

    const changeOrder = async (event: Event) => {
        return changeOrderFn(event, state);
    };

    const deleteRecord = async (ids: Array<number>) => {
        return deleteFn(`${API_ENDPOINTS.timezones}/bulk_delete`, ids, state);
    };

    const perDeleteBulkRecord = async (ids: Array<number>) => {
        return deleteFn(`${API_ENDPOINTS.timezones}/bulk_delete_per`, ids, state);
    };

    const checkAll = async (id: number) => {
        return checkAllFn(id, state);
    };

    const getTimezones = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.timezones, data, state);
    };

    const getTrashTimezones = async (data: QueryParams) => {
        return getData(`${API_ENDPOINTS.timezones}/trash`, data, state);
    };

    const getEditData = async (id: number) => {
        if (!id) {
            return;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, `${API_ENDPOINTS.timezones}/${id}`);
            formData.value = response.data;
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

    const restoreBulkRecord = async (ids: Array<number>) => {
        return restoreFn(`${API_ENDPOINTS.timezones}/restore_records`, ids, state);
    };

    return {
        state,
        Notify,
        getTimezones,
        getTrashTimezones,
        getEditData,
        formData,
        defaultFormData,
        deleteRecord,
        perDeleteBulkRecord,
        restoreBulkRecord,
        changeOrder,
        checkAll,
        select_data,
    };
}
