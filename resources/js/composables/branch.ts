import { reactive, ref } from "vue";
import useCommons from "./common";
import { API_ENDPOINTS } from './apiEndpoints'

export default function useBranches(){

    interface QueryParams {
        sort_by: string;
        sort_type: 'asc' | 'desc';
        show_record: number;
        page: number;
        search: string;
        company_id?: string | number;
        country_id?: string | number;
        state_id?: string | number;
        city_id?: string | number;
    }

    const formData = ref({
      'company_id':'',
      'code':'',
      'country_id':'',
      'state_id':'',
      'city_id':'',
      'name':'',
      'description':'',
      'phone':'',
      'mobile':'',
      'email':'',
      'address':'',
      'is_active':true,
      'is_default':false,
    });

    const defaultFormData = ref({
      'company_id':'',
      'code':'',
      'country_id':'',
      'state_id':'',
      'city_id':'',
      'name':'',
      'description':'',
      'phone':'',
      'mobile':'',
      'email':'',
      'address':'',
      'is_active':true,
      'is_default':false,
    });

    const {Notify, select_data, fetchWithRetry, fetchState, fetchCity, changeStateFn, changeOrderFn, deleteFn, checkAllFn, duplicateFn, getData, restoreFn} = useCommons()

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
        sort_type: 'asc',
        show_record: 10,
        page: 1,
        search: '',
        status: 'all',
        company_id: '',
        country_id: '',
        state_id: '',
        city_id: '',
      },
      loading: false,
      modalLoading: true,
      edit_ids: [],
      selectAll: false,
      trash_count:0,
      can_add_branch: true,
      loadingIds: new Set(),
    });

    const changeStatus = async (ids: Array<number>, status: string) => {
        return changeStateFn(API_ENDPOINTS.branches+'/statusupdate',ids,status,state);
    }

    const changeOrder = async (event: Event) => {
        return changeOrderFn(event, state)
    };

    const deleteRecord = async (ids: Array<number>) => {
        return deleteFn(API_ENDPOINTS.branches+'/bulk_delete', ids, state);
    }

    const perDeleteBulkRecord = async (ids: Array<number>) => {
        return deleteFn(API_ENDPOINTS.branches+'/bulk_delete_per', ids, state);
    }

    const checkAll = async (id: number) => {
        return checkAllFn(id, state);
    };

    const duplicate = async (id: number) => {
        return duplicateFn(API_ENDPOINTS.branches+'/duplicate', id)
    }

    const getBranches = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.branches, data, state)
    };

    const getTrashBranches = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.branches+'/trash', data, state);
    };

    const getEditData = async (id: number) => {
        if (!id) {
            return;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, `/api/branches/${id}`);
            formData.value = response.data;

            if (response.data.country_id) {
                await fetchState(response.data.country_id);
            }

            if (response.data.state_id && response.data.country_id) {
                await fetchCity(response.data.country_id, response.data.state_id);
            }
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
        return restoreFn(API_ENDPOINTS.branches+'/restore_records', ids, state)
    }

    const generateBranchCode = async () => {
        try {
            const response = await fetchWithRetry(
                window.axios.get,
                API_ENDPOINTS.branchGenerateCode,
            );

            return response.data.code as string;
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error)) {
                if (error.response?.data?.message !== 'Unauthenticated.') {
                    Notify(error.response?.data?.message || 'An error occurred', 'alert');
                }
            } else {
                Notify('Unexpected error occurred', 'alert');
            }

            return '';
        }
    }

    return{
        state,
        changeStatus,
        Notify,
        getBranches,
        getTrashBranches,
        getEditData,
        formData,
        defaultFormData,
        deleteRecord,
        perDeleteBulkRecord,
        restoreBulkRecord,
        changeOrder,
        checkAll,
        duplicate,
        select_data,
        generateBranchCode,
    }

}
