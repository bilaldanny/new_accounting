import { reactive, ref } from "vue";
import { API_ENDPOINTS } from './apiEndpoints'
import useCommons from "./common";

export default function useCashCollections(){

    interface QueryParams {
        sort_by: string;
        sort_type: 'asc' | 'desc';
        show_record: number;
        page: number;
        search: string;
    }

    const emptyForm = () => ({
      company_id: '',
      branch_id: '',
      contact_id: null,
      collected_on: new Date().toISOString().slice(0, 10),
      amount: null,
      note: '',
    });

    const formData = ref(emptyForm());
    const defaultFormData = ref(emptyForm());

    const {Notify, select_data, fetchWithRetry, changeStateFn, changeOrderFn, deleteFn, checkAllFn, getData, restoreFn} = useCommons()

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
        sort_type: 'desc' as 'asc' | 'desc',
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
      loadingIds: new Set(),
    });

    const changeStatus = async (ids: Array<number>, status: string) => {
        return changeStateFn(API_ENDPOINTS.cashCollections+'/statusupdate',ids,status,state);
    }

    const changeOrder = async (event: Event) => {
        return changeOrderFn(event, state)
    };

    const deleteRecord = async (ids: Array<number>) => {
        return deleteFn(API_ENDPOINTS.cashCollections+'/bulk_delete', ids, state);
    }

    const perDeleteBulkRecord = async (ids: Array<number>) => {
        return deleteFn(API_ENDPOINTS.cashCollections+'/bulk_delete_per', ids, state);
    }

    const checkAll = async (id: number) => {
        return checkAllFn(id, state);
    };

    const getCashCollections = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.cashCollections, data, state)
    };

    const getTrashCashCollections = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.cashCollections+'/trash', data, state);
    };

    const getEditData = async (id: number): Promise<boolean> => {
        if (!id) {
            return false;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, `${API_ENDPOINTS.cashCollections}/${id}`);
            const cashcollection = response.data ?? {};

            formData.value = {
                ...emptyForm(),
                ...cashcollection,
            };

            return true;
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error)) {
                if(error.response?.data?.message !== 'Unauthenticated.'){
                    Notify(error.response?.data?.message || 'An error occurred', 'alert');
                }
            } else {
                Notify('Unexpected error occurred', 'alert');
            }

            return false;
        }
    }

    const restoreBulkRecord = async (ids: Array<number>) => {
        return restoreFn(API_ENDPOINTS.cashCollections+'/restore_records', ids, state)
    }

    return{
        state,
        changeStatus,
        Notify,
        getCashCollections,
        getTrashCashCollections,
        getEditData,
        formData,
        defaultFormData,
        emptyForm,
        deleteRecord,
        perDeleteBulkRecord,
        restoreBulkRecord,
        changeOrder,
        checkAll,
        select_data
    }

}
