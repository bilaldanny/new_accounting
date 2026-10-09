import { reactive, ref } from "vue";
import { API_ENDPOINTS } from './apiEndpoints'
import useCommons from "./common";

export default function useCustomerSubscriptionPlans(){

    interface QueryParams {
        sort_by: string;
        sort_type: 'asc' | 'desc';
        show_record: number;
        page: number;
        search: string;
    }

    const emptyForm = () => ({
      company_id: '',
      name: '',
      code: '',
      billing_cycle: 'monthly',
      price: 0,
      setup_fee: 0,
      trial_days: 0,
      is_metered: false,
      unit_label: '',
      included_units: null,
      overage_rate: null,
      sort_order: 0,
      is_active: true,
    });

    const formData = ref(emptyForm());
    const defaultFormData = ref(emptyForm());

    const {Notify, select_data, fetchWithRetry, changeOrderFn, deleteFn, checkAllFn, getData, restoreFn} = useCommons()

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
        sort_by: 'sort_order',
        sort_type: 'asc' as 'asc' | 'desc',
        show_record: 10,
        page: 1,
        search: '',
        status: 'all',
      },
      loading: false,
      modalLoading: true,
      edit_ids: [],
      selectAll: false,
      trash_count: 0,
      loadingIds: new Set(),
    });

    const changeOrder = async (event: Event) => {
        return changeOrderFn(event, state)
    };

    const deleteRecord = async (ids: Array<number>) => {
        return deleteFn(API_ENDPOINTS.customerSubscriptionPlans+'/bulk_delete', ids, state);
    }

    const perDeleteBulkRecord = async (ids: Array<number>) => {
        return deleteFn(API_ENDPOINTS.customerSubscriptionPlans+'/bulk_delete_per', ids, state);
    }

    const checkAll = async (id: number) => {
        return checkAllFn(id, state);
    };

    const getCustomerSubscriptionPlans = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.customerSubscriptionPlans, data, state)
    };

    const getTrashCustomerSubscriptionPlans = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.customerSubscriptionPlans+'/trash', data, state);
    };

    const getEditData = async (id: number): Promise<boolean> => {
        if (!id) {
            return false;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, `${API_ENDPOINTS.customerSubscriptionPlans}/${id}`);
            formData.value = {
                ...emptyForm(),
                ...response.data,
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
        return restoreFn(API_ENDPOINTS.customerSubscriptionPlans+'/restore_records', ids, state)
    }

    return{
        state,
        Notify,
        getCustomerSubscriptionPlans,
        getTrashCustomerSubscriptionPlans,
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
