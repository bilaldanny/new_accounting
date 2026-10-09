import { reactive, ref } from "vue";
import { API_ENDPOINTS } from './apiEndpoints'
import useCommons from "./common";

export type RequisitionLineRow = {
    product_id: number | string | '';
    variation_id: number | string | '';
    unit_id: number | string | '';
    product_name?: string;
    unit_name?: string;
    requested_quantity: number | string;
    note?: string;
};

export default function usePurchaseRequisitions(){

    interface QueryParams {
        sort_by: string;
        sort_type: 'asc' | 'desc';
        show_record: number;
        page: number;
        search: string;
    }

    const today = () => {
        const date = new Date();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');

        return `${date.getFullYear()}-${month}-${day}`;
    };

    const emptyForm = () => ({
      company_id: '',
      branch_id: '',
      contact_id: '',
      requisition_no: '',
      requisition_date: today(),
      note: '',
      status: 'pending',
      status_label: '',
      is_editable: true,
      can_approve: false,
      contact_name: '',
      requested_by_name: '',
      purchase_order_id: null as number | null,
      approved_by_name: '',
      approved_at: '',
      rejected_by_name: '',
      rejected_at: '',
      lines: [] as RequisitionLineRow[],
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
        sort_type: 'desc' as 'asc' | 'desc',
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
      trash_count: 0,
      loadingIds: new Set(),
    });

    const changeOrder = async (event: Event) => {
        return changeOrderFn(event, state)
    };

    const deleteRecord = async (ids: Array<number>) => {
        return deleteFn(API_ENDPOINTS.purchaseRequisitions+'/bulk_delete', ids, state);
    }

    const checkAll = async (id: number) => {
        return checkAllFn(id, state);
    };

    const getRequisitions = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.purchaseRequisitions, data, state)
    };

    const getEditData = async (id: number): Promise<boolean> => {
        if (!id) {
            return false;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, `${API_ENDPOINTS.purchaseRequisitions}/${id}`);
            const requisition = response.data ?? {};

            formData.value = {
                ...emptyForm(),
                ...requisition,
                requisition_date: requisition.requisition_date || today(),
                lines: Array.isArray(requisition.lines) ? requisition.lines.map((line: Record<string, unknown>) => ({
                    ...line,
                    requested_quantity: line.requested_quantity,
                })) : [],
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

    return{
        state,
        Notify,
        getRequisitions,
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
