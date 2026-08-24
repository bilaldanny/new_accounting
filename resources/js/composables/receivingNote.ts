import { reactive, ref } from "vue";
import useCommons from "./common";
import { API_ENDPOINTS } from './apiEndpoints'

export type ReceivingNoteLine = {
    id?: number | string;
    product_id?: number | string;
    variation_id?: number | string;
    unit_id?: number | string;
    product_name?: string;
    sku?: string;
    unit_name?: string;
    unit_short_name?: string;
    quantity: number | string;
    quantity_received: number | string;
    remaining_qty: number | string;
};

export default function useReceivingNotes(){
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
      invoice_no: '',
      purchase_order_no: '',
      sup_ref_no: '',
      transaction_date: '',
      status: 'received',
      payment_status: 'due',
      business_name: '',
      address: '',
      mobile: '',
      company_name: '',
      company_address: '',
      branch_name: '',
      formatted_amount: '0.00',
      final_amount: 0,
      purchaselines: [] as ReceivingNoteLine[],
    });

    const formData = ref(emptyForm());
    const defaultFormData = ref(emptyForm());
    const viewData = ref(emptyForm());

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
        sort_by: 'created_at',
        sort_type: 'desc' as 'asc' | 'desc',
        show_record: 10,
        page: 1,
        search: '',
        status: 'all',
        payment_status: 'all',
        company_id: '',
        branch_id: '',
        contact_id: '',
        transaction_date: '',
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
        return deleteFn(API_ENDPOINTS.receivingNotes+'/bulk_delete', ids, state);
    }

    const perDeleteBulkRecord = async (ids: Array<number>) => {
        return deleteFn(API_ENDPOINTS.receivingNotes+'/bulk_delete_per', ids, state);
    }

    const checkAll = async (id: number) => {
        return checkAllFn(id, state);
    };

    const getReceivingNotes = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.receivingNotes, data, state)
    };

    const getTrashReceivingNotes = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.receivingNotes+'/trash', data, state);
    };

    const getEditData = async (id: number): Promise<boolean> => {
        if (!id) {
            return false;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, `${API_ENDPOINTS.receivingNotes}/${id}`);
            const note = response.data ?? {};
            const lines = Array.isArray(note.purchaselines) ? note.purchaselines : [];

            formData.value = {
                ...emptyForm(),
                ...note,
                purchaselines: lines,
            };
            viewData.value = { ...formData.value };

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
        return restoreFn(API_ENDPOINTS.receivingNotes+'/restore_records', ids, state)
    }

    return{
        state,
        Notify,
        getReceivingNotes,
        getTrashReceivingNotes,
        getEditData,
        formData,
        defaultFormData,
        emptyForm,
        viewData,
        deleteRecord,
        perDeleteBulkRecord,
        restoreBulkRecord,
        changeOrder,
        checkAll,
        select_data
    }
}
