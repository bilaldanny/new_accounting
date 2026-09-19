import { reactive, ref } from "vue";
import { API_ENDPOINTS } from './apiEndpoints'
import useCommons from "./common";

export type StockTransferLineRow = {
    id?: number | string;
    product_id: number | string;
    variation_id: number | string;
    itemtype_id?: number | string;
    product_name: string;
    sku?: string;
    unit_id: number | string | '';
    quantity: number | string;
    packing_qty: number | string;
    units: Array<{ id: number | string; text?: string; short_name?: string; unit_qty?: number; packing_qty?: number }>;
    current_stock?: number | string;
    unit_name?: string;
    brand_name?: string;
    itemtype_name?: string;
    variation_name?: string;
};

export default function useStockTransfers(){

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
      tobranch_id: '',
      invoice_no: '',
      transaction_date: today(),
      additional_note: '',
      total_item: 0,
      type: 'transfer',
      status: 'pending',
      purchaselines: [] as StockTransferLineRow[],
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
        company_id: '',
        branch_id: '',
        tobranch_id: '',
      },
      loading: false,
      modalLoading: true,
      edit_ids: [],
      selectAll: false,
      trash_count: 0,
      loadingIds: new Set(),
    });

    const changeStatus = async (ids: Array<number>, status: string) => {
        return changeStateFn(API_ENDPOINTS.stockTransfers+'/statusupdate',ids,status,state);
    }

    const changeOrder = async (event: Event) => {
        return changeOrderFn(event, state)
    };

    const deleteRecord = async (ids: Array<number>) => {
        return deleteFn(API_ENDPOINTS.stockTransfers+'/bulk_delete', ids, state);
    }

    const perDeleteBulkRecord = async (ids: Array<number>) => {
        return deleteFn(API_ENDPOINTS.stockTransfers+'/bulk_delete_per', ids, state);
    }

    const checkAll = async (id: number) => {
        return checkAllFn(id, state);
    };

    const getStockTransfers = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.stockTransfers, data, state)
    };

    const getTrashStockTransfers = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.stockTransfers+'/trash', data, state);
    };

    const getEditData = async (id: number): Promise<boolean> => {
        if (!id) {
            return false;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, `/api/stocktransfers/${id}`);
            const transfer = response.data ?? {};
            const lines = Array.isArray(transfer.purchaselines) ? transfer.purchaselines : [];

            formData.value = {
                ...emptyForm(),
                ...transfer,
                transaction_date: transfer.transaction_date || today(),
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
        return restoreFn(API_ENDPOINTS.stockTransfers+'/restore_records', ids, state)
    }

    return{
        state,
        changeStatus,
        Notify,
        getStockTransfers,
        getTrashStockTransfers,
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
