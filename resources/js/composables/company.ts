import { reactive, ref } from "vue";
import useCommons from "./common";
import { API_ENDPOINTS } from './apiEndpoints'

export default function useCompanies(){
    interface QueryParams {
        sort_by: string;
        sort_type: 'asc' | 'desc';
        show_record: number;
        page: number;
        search: string;
    }

    const formData = ref({
      'code':'',
      'name':'',
      'email':'',
      'phone': '',
      'ntn_no':'',
      'admin_name':'',
      'admin_username':'',
      'admin_email':'',
      'admin_phone': '',
      'password':'',
      'password_confirmation':'',
      'max_users':10,
      'max_branches':2,
      'logo':'',
      'logo_url':'',
      'user_id':null,
      'is_active':true,
    });

    const defaultFormData = ref({
      'code':'',
      'name':'',
      'email':'',
      'phone': '',
      'ntn_no':'',
      'admin_name':'',
      'admin_username':'',
      'admin_email':'',
      'admin_phone': '',
      'password':'',
      'password_confirmation':'',
      'max_users':10,
      'max_branches':2,
      'logo':'',
      'logo_url':'',
      'user_id':null,
      'is_active':true,
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
      },
      loading: false,
      modalLoading: true,
      edit_ids: [],
      selectAll: false,
      trash_count:0,
      loadingIds: new Set(),
    });

    const changeStatus = async (ids: Array<number>, status: string) => {
        return changeStateFn(API_ENDPOINTS.companies+'/statusupdate',ids,status,state);
    }

    const changeOrder = async (event: Event) => {
        return changeOrderFn(event, state)
    };

    const deleteRecord = async (ids: Array<number>) => {
        return deleteFn(API_ENDPOINTS.companies+'/bulk_delete', ids, state);
    }

    const perDeleteBulkRecord = async (ids: Array<number>) => {
        return deleteFn(API_ENDPOINTS.companies+'/bulk_delete_per', ids, state);
    }

    const checkAll = async (id: number) => {
        return checkAllFn(id, state);
    };

    const duplicate = async (id: number) => {
        return duplicateFn(API_ENDPOINTS.companies+'/duplicate', id)
    }

    const getCompanies = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.companies, data, state)
    };

    const getTrashCompanies = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.companies+'/trash', data, state);
    };

    const getEditData = async (id: number) => {
        if (!id) {
            return;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, `/api/companies/${id}`);
            formData.value = response.data;
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
        return restoreFn(API_ENDPOINTS.companies+'/restore_records', ids, state)
    }

    const generateCompanyCode = async (name = '') => {
        try {
            const response = await fetchWithRetry(
                window.axios.get,
                API_ENDPOINTS.companyGenerateCode,
                { params: { name } },
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
        getCompanies,
        getTrashCompanies,
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
        generateCompanyCode,
    }

}
