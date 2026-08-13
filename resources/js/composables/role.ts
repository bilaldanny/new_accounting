import { reactive, ref } from "vue";
import useCommons from "./common";
import { API_ENDPOINTS } from './apiEndpoints'

export default function useRoles(){

    interface QueryParams {
        sort_by: string;
        sort_type: 'asc' | 'desc'; // More specific than string
        show_record: number;
        page: number;
        search: string;
    }

    //Form Data
    const formData = ref({
      'company_id':'',
      'branch_id':'',
      'name':'',
      'is_hide':false,
      'is_active':true,
    });

    const defaultFormData = ref({
      'company_id':'',
      'branch_id':'',
      'name':'',
      'is_hide':false,
      'is_active':true,
    });

    const permission = ref({
      'company_id': '',
      'branch_id': '',
      'department_id': '',
      'role_id': '',
      'menu_id': '',
      'menuid': '',
      'status': '',
    })

    const {Notify, select_data, fetchWithRetry, changeStateFn, changeOrderFn, deleteFn, checkAllFn, duplicateFn, getData, restoreFn} = useCommons()

    // State Management with reactive
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
      rolesdata: [],
      typedata: [],
      trash_count:0,
      loadingIds: new Set(),
    });

    /* Change Status */
        const changeStatus = async (ids: Array<number>, status: string) => {
            return changeStateFn(API_ENDPOINTS.roles+'/statusupdate',ids,status,state);
        }
    /* Change Status */

    /* Change sorting order */
        const changeOrder = async (event: Event) => {
            return changeOrderFn(event, state)
        };
    /* Change sorting order */

    /* Delete Function */
        const deleteRecord = async (ids: Array<number>) => {
            return deleteFn(API_ENDPOINTS.roles+'/bulk_delete', ids, state);
        }
    /* Delete Function */

    /* Permanently Delete Function */
        const perDeleteBulkRecord = async (ids: Array<number>) => {
            return deleteFn(API_ENDPOINTS.roles+'/bulk_delete_per', ids, state);
        }
    /* Permanently Delete Function */

    // Check all records or a specific one
        const checkAll = async (id: number) => {
            return checkAllFn(id, state);
        };
    // Check all records or a specific one

    /* Duplicate Record */
        const duplicate = async (id: number) => {
            return duplicateFn(API_ENDPOINTS.roles+'/duplicate', id)
        }
    /* Duplicate Record */

    /* Get Role Function */
        const getRoles = async (data: QueryParams) => {
            return getData(API_ENDPOINTS.roles, data, state)
        };
    /* Get Role Function */

    /* Get Trash Role Function */
        const getTrashRoles = async (data: QueryParams) => {
            return getData(API_ENDPOINTS.roles+'/trash', data, state);
        };
    /* Get Trash Role Function */

    /* Fetch Data To Edit Form */
    const getEditData = async (id: number) => {
        if (!id) {
            return;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, `/api/roles/${id}`);
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
    /* Fetch Data To Edit Form */

    /* Restore Function */
        const restoreBulkRecord = async (ids: Array<number>) => {
            return restoreFn(API_ENDPOINTS.roles+'/restore_records', ids, state)
        }
    /* Restore Function */



    return{
        permission,
        state,
        changeStatus,
        Notify,
        getRoles,
        getTrashRoles,
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
