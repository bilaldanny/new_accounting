import { reactive, ref } from "vue";
import useCommons from "./common";
import { API_ENDPOINTS } from './apiEndpoints'

export default function useSellPayments(){
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
      contact_id: '',
      transaction_id: '',
      amount: '',
      paid_on: new Date().toISOString().slice(0, 10),
      method: 'cash',
      payment_account: '',
      document: '',
      file_name: '',
      card_number: '',
      card_holder_name: '',
      card_type: '',
      card_transaction_number: '',
      card_month: '',
      card_year: '',
      card_security: '',
      cheque_number: '',
      bank_account_number: '',
      note: '',
      invoice_no: '',
      customer_name: '',
      business_name: '',
      branch_name: '',
      final_amount: '',
      remaining_amount: '',
    });

    const formData = ref(emptyForm());
    const defaultFormData = ref(emptyForm());

    const {Notify, select_data, fetchWithRetry, changeOrderFn, deleteFn, checkAllFn, getData} = useCommons()

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
        contact_id: '',
        transaction_id: '',
        method: 'all',
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
        return deleteFn(API_ENDPOINTS.sellPayments+'/bulk_delete', ids, state);
    }

    const checkAll = async (id: number) => {
        return checkAllFn(id, state);
    };

    const getSellPayments = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.sellPayments, data, state)
    };

    const getEditData = async (id: number) => {
        if (!id) {
            return;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, `${API_ENDPOINTS.sellPayments}/${id}`);
            formData.value = {
                ...emptyForm(),
                ...response.data,
                paid_on: String(response.data?.paid_on ?? '').slice(0, 10),
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

    return{
        state,
        Notify,
        getSellPayments,
        getEditData,
        formData,
        defaultFormData,
        emptyForm,
        deleteRecord,
        changeOrder,
        checkAll,
        select_data
    }
}
