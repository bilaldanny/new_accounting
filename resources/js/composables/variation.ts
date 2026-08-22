import { reactive, ref } from "vue";
import useCommons from "./common";
import { API_ENDPOINTS } from './apiEndpoints'

export default function useVariations(){

    interface QueryParams {
        sort_by: string;
        sort_type: 'asc' | 'desc';
        show_record: number;
        page: number;
        search: string;
    }

    const formData = ref({
      'company_id':'',
      'category_id':'',
      'subcategory_id':'',
      'itemtype_id':'',
      'name':'',
      'values':[{ name: '', active: true }],
      'priority':0,
      'active':true,
    });

    const defaultFormData = ref({
      'company_id':'',
      'category_id':'',
      'subcategory_id':'',
      'itemtype_id':'',
      'name':'',
      'values':[{ name: '', active: true }],
      'priority':0,
      'active':true,
    });

    const {Notify, select_data, fetchWithRetry, changeStateFn, changeOrderFn, deleteFn, checkAllFn, duplicateFn, getData, restoreFn} = useCommons()

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
        category_id: '',
        subcategory_id: '',
        itemtype_id: '',
      },
      loading: false,
      modalLoading: true,
      edit_ids: [],
      selectAll: false,
      trash_count:0,
      loadingIds: new Set(),
    });

    const changeStatus = async (ids: Array<number>, status: string) => {
        return changeStateFn(API_ENDPOINTS.variations+'/statusupdate',ids,status,state);
    }

    const changeOrder = async (event: Event) => {
        return changeOrderFn(event, state)
    };

    const deleteRecord = async (ids: Array<number>) => {
        return deleteFn(API_ENDPOINTS.variations+'/bulk_delete', ids, state);
    }

    const perDeleteBulkRecord = async (ids: Array<number>) => {
        return deleteFn(API_ENDPOINTS.variations+'/bulk_delete_per', ids, state);
    }

    const checkAll = async (id: number) => {
        return checkAllFn(id, state);
    };

    const duplicate = async (id: number) => {
        return duplicateFn(API_ENDPOINTS.variations+'/duplicate', id)
    }

    const getVariations = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.variations, data, state)
    };

    const getTrashVariations = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.variations+'/trash', data, state);
    };

    const getEditData = async (id: number) => {
        if (!id) {
            return;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, `/api/variations/${id}`);
            let values = response.data.values;

            if (typeof values === 'string' && values !== '') {
                try {
                    values = JSON.parse(values);
                } catch {
                    values = [];
                }
            }

            formData.value = {
                ...response.data,
                values: Array.isArray(values) && values.length
                    ? values
                    : [{ name: '', active: true }],
            };
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error)) {
                if(error.response?.data?.message !== 'Unauthenticated.'){
                    Notify(error.response?.data?.message || 'An error occurred', 'alert');
                }
            } else {
                Notify('Unexpected error occurred', 'alert');
            }
        }
    }

    const restoreBulkRecord = async (ids: Array<number>) => {
        return restoreFn(API_ENDPOINTS.variations+'/restore_records', ids, state)
    }

    return{
        state,
        changeStatus,
        Notify,
        getVariations,
        getTrashVariations,
        getEditData,
        formData,
        defaultFormData,
        deleteRecord,
        perDeleteBulkRecord,
        restoreBulkRecord,
        changeOrder,
        checkAll,
        duplicate,
        select_data
    }

}
