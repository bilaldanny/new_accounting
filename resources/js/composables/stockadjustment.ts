import { reactive, ref } from "vue";
import { API_ENDPOINTS } from './apiEndpoints'
import useCommons from "./common";

export type StockAdjustmentLineRow = {
    id?: number | string;
    product_id: number | string;
    variation_id: number | string;
    itemtype_id?: number | string;
    product_name: string;
    sku?: string;
    unit_id: number | string | '';
    quantity_adjustment: number | string;
    direction?: 'increase' | 'decrease';
    magnitude?: number | string;
    units: Array<{ id: number | string; text?: string; short_name?: string; unit_qty?: number; packing_qty?: number }>;
    current_stock?: number | string;
    unit_name?: string;
    brand_name?: string;
    itemtype_name?: string;
    variation_name?: string;
};

export default function useStockAdjustments(){

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
      invoice_no: '',
      transaction_date: today(),
      adjustment_type: 'normal',
      additional_note: '',
      total_item: 0,
      type: 'adjustment',
      status: 'pending',
      purchaselines: [] as StockAdjustmentLineRow[],
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
        adjustment_type: 'all',
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

    const changeStatus = async (ids: Array<number>, status: string) => {
        return changeStateFn(API_ENDPOINTS.stockAdjustments+'/statusupdate',ids,status,state);
    }

    const changeOrder = async (event: Event) => {
        return changeOrderFn(event, state)
    };

    const deleteRecord = async (ids: Array<number>) => {
        return deleteFn(API_ENDPOINTS.stockAdjustments+'/bulk_delete', ids, state);
    }

    const perDeleteBulkRecord = async (ids: Array<number>) => {
        return deleteFn(API_ENDPOINTS.stockAdjustments+'/bulk_delete_per', ids, state);
    }

    const checkAll = async (id: number) => {
        return checkAllFn(id, state);
    };

    const getStockAdjustments = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.stockAdjustments, data, state)
    };

    const getTrashStockAdjustments = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.stockAdjustments+'/trash', data, state);
    };

    const getEditData = async (id: number): Promise<boolean> => {
        if (!id) {
            return false;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, `/api/stockadjustments/${id}`);
            const adjustment = response.data ?? {};
            const lines = Array.isArray(adjustment.purchaselines) ? adjustment.purchaselines : [];

            formData.value = {
                ...emptyForm(),
                ...adjustment,
                transaction_date: adjustment.transaction_date || today(),
                purchaselines: lines,
                total_item: lines.length,
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
        return restoreFn(API_ENDPOINTS.stockAdjustments+'/restore_records', ids, state)
    }

    return{
        state,
        changeStatus,
        Notify,
        getStockAdjustments,
        getTrashStockAdjustments,
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
