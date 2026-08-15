import { reactive, ref } from "vue";
import useCommons from "./common";
import { API_ENDPOINTS } from './apiEndpoints'

export default function useUsers(){

    interface QueryParams {
        sort_by: string;
        sort_type: 'asc' | 'desc';
        show_record: number;
        page: number;
        search: string;
    }

    const formData = ref({
      'company_id':'',
      'branch_id':'',
      'department_id':'',
      'role_id':'',
      'first_name':'',
      'last_name':'',
      'username':'',
      'email':'',
      'password':'',
      'password_confirmation':'',
      'phone':'',
      'user_image':'',
      'is_active':true,
    });

    const defaultFormData = ref({
      'company_id':'',
      'branch_id':'',
      'department_id':'',
      'role_id':'',
      'first_name':'',
      'last_name':'',
      'username':'',
      'email':'',
      'password':'',
      'password_confirmation':'',
      'phone':'',
      'user_image':'',
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
        company_id: '',
        branch_id: '',
      },
      loading: false,
      modalLoading: true,
      edit_ids: [],
      selectAll: false,
      usersdata: [],
      trash_count:0,
      loadingIds: new Set(),
    });

    const changeStatus = async (ids: Array<number>, status: string) => {
        return changeStateFn(API_ENDPOINTS.users+'/statusupdate',ids,status,state);
    }

    const changeOrder = async (event: Event) => {
        return changeOrderFn(event, state)
    };

    const deleteRecord = async (ids: Array<number>) => {
        return deleteFn(API_ENDPOINTS.users+'/bulk_delete', ids, state);
    }

    const perDeleteBulkRecord = async (ids: Array<number>) => {
        return deleteFn(API_ENDPOINTS.users+'/bulk_delete_per', ids, state);
    }

    const checkAll = async (id: number) => {
        return checkAllFn(id, state);
    };

    const duplicate = async (id: number) => {
        return duplicateFn(API_ENDPOINTS.users+'/duplicate', id)
    }

    const getUsers = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.users, data, state)
    };

    const getTrashUsers = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.users+'/trash', data, state);
    };

    const getEditData = async (id: number) => {
        if (!id) {
            return;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, `/api/users/${id}`);
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
        return restoreFn(API_ENDPOINTS.users+'/restore_records', ids, state)
    }

    return{
        state,
        changeStatus,
        Notify,
        getUsers,
        getTrashUsers,
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
