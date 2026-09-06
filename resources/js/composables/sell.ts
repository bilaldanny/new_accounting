import { reactive, ref } from "vue";
import useCommons from "./common";
import { API_ENDPOINTS } from './apiEndpoints'

export type SellLineRow = {
    id?: number | string;
    product_id: number | string;
    variation_id: number | string;
    itemtype_id?: number | string;
    product_name: string;
    sku?: string;
    unit_id: number | string | '';
    quantity: number | string;
    quantity_issue?: number | string;
    quantity_returned?: number | string;
    unit_price: number | string;
    discount_percent: number | string;
    unit_price_after_discount: number | string;
    packing_qty: number | string;
    row_subtotal: number | string;
    subtotal?: number | string;
    units: Array<{ id: number | string; text?: string; short_name?: string; unit_qty?: number; packing_qty?: number }>;
    current_stock?: number | string;
    unit_name?: string;
};

export default function useSells(){

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
      invoice_no: '',
      transaction_date: today(),
      pay_term: '',
      pay_type: 'day',
      discount_type: 'none',
      discount_amount: 0,
      shipping_charges: 0,
      shipping_details: '',
      shipping_address: '',
      shipping_note: '',
      shipping_status: '',
      delivered_to: '',
      billty_no: '',
      billty_date: '',
      packing: '',
      billty_image: '',
      billty_image_url: '',
      additional_note: '',
      final_amount: 0,
      total_item: 0,
      total_pack_qty: 0,
      net_sub_total: 0,
      discount_val: 0,
      credit_limit: 0,
      payment_status: 'due',
      type: 'sell',
      status: 'final',
      is_direct: false,
      direct_contact_id: '',
      selllines: [] as SellLineRow[],
    });

    const formData = ref(emptyForm());
    const defaultFormData = ref(emptyForm());
    const viewData = ref<Record<string, unknown>>({});

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
        order_status: 'all',
      },
      loading: false,
      modalLoading: true,
      edit_ids: [],
      selectAll: false,
      trash_count: 0,
      loadingIds: new Set(),
    });

    const changeStatus = async (ids: Array<number>, status: string) => {
        return changeStateFn(API_ENDPOINTS.sells+'/statusupdate',ids,status,state);
    }

    const changeOrder = async (event: Event) => {
        return changeOrderFn(event, state)
    };

    const deleteRecord = async (ids: Array<number>) => {
        return deleteFn(API_ENDPOINTS.sells+'/bulk_delete', ids, state);
    }

    const perDeleteBulkRecord = async (ids: Array<number>) => {
        return deleteFn(API_ENDPOINTS.sells+'/bulk_delete_per', ids, state);
    }

    const checkAll = async (id: number) => {
        return checkAllFn(id, state);
    };

    const duplicate = async (id: number) => {
        return duplicateFn(API_ENDPOINTS.sells+'/duplicate', id)
    }

    const getSells = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.sells, data, state)
    };

    const getTrashSells = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.sells+'/trash', data, state);
    };

    const mapSellResponse = (sell: Record<string, any>) => {
        const lines = Array.isArray(sell.selllines) ? sell.selllines : [];
        const netSubTotal = lines.reduce((sum: number, line: SellLineRow) => sum + Number(line.row_subtotal || line.subtotal || 0), 0);
        const totalPackQty = lines.reduce((sum: number, line: SellLineRow) => sum + Number(line.packing_qty || 0), 0);
        const discountVal = Number(sell.final_amount || 0) === 0
            ? 0
            : Math.max(netSubTotal + Number(sell.shipping_charges || 0) - Number(sell.final_amount || 0), 0);

        return {
            ...emptyForm(),
            ...sell,
            transaction_date: sell.transaction_date || today(),
            billty_date: sell.billty_date || '',
            is_direct: Boolean(sell.is_direct),
            billty_image_url: sell.billty_image_url || '',
            selllines: lines,
            total_item: lines.length,
            total_pack_qty: totalPackQty,
            net_sub_total: netSubTotal,
            discount_val: discountVal,
            credit_limit: Number(sell.credit_limit || 0),
        };
    };

    const getEditData = async (id: number): Promise<boolean> => {
        if (!id) {
            return false;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, `/api/sells/${id}`);
            const sell = response.data ?? {};
            formData.value = mapSellResponse(sell);
            viewData.value = {
                ...sell,
                selllines: formData.value.selllines,
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
        return restoreFn(API_ENDPOINTS.sells+'/restore_records', ids, state)
    }

    return{
        state,
        changeStatus,
        Notify,
        getSells,
        getTrashSells,
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
        duplicate,
        select_data
    }

}
