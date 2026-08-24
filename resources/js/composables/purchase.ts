import { reactive, ref } from "vue";
import useCommons from "./common";
import { API_ENDPOINTS } from './apiEndpoints'

export type PurchaseLineRow = {
    id?: number | string;
    product_id: number | string;
    variation_id: number | string;
    itemtype_id?: number | string;
    product_name: string;
    sku?: string;
    unit_id: number | string | '';
    quantity: number | string;
    qunatity_sold?: number | string;
    quantity_returned?: number | string;
    purchase_rate: number | string;
    default_sell_price: number | string;
    discount_percent: number | string;
    packing_qty: number | string;
    profit_percent: number | string;
    pp_without_discount: number | string;
    purchase_price: number | string;
    row_subtotal: number | string;
    units: Array<{ id: number | string; text?: string; short_name?: string; unit_qty?: number; packing_qty?: number }>;
    current_stock?: number | string;
    unit_name?: string;
};

export default function usePurchases(){

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
      sup_ref_no: '',
      transaction_date: today(),
      pay_term: '',
      pay_type: 'day',
      attachment: '',
      attachment_url: '',
      discount_type: 'none',
      discount_amount: 0,
      shipping_charges: 0,
      shipping_details: '',
      shipping_note: '',
      additional_note: '',
      final_amount: 0,
      total_item: 0,
      total_pack_qty: 0,
      net_sub_total: 0,
      discount_val: 0,
      payment_status: 'due',
      type: 'purchaseorder',
      status: 'pending',
      is_direct: false,
      direct_contact_id: '',
      purchaselines: [] as PurchaseLineRow[],
    });

    const formData = ref(emptyForm());
    const defaultFormData = ref(emptyForm());

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
      },
      loading: false,
      modalLoading: true,
      edit_ids: [],
      selectAll: false,
      trash_count: 0,
      loadingIds: new Set(),
    });

    const changeStatus = async (ids: Array<number>, status: string) => {
        return changeStateFn(API_ENDPOINTS.purchases+'/statusupdate',ids,status,state);
    }

    const changeOrder = async (event: Event) => {
        return changeOrderFn(event, state)
    };

    const deleteRecord = async (ids: Array<number>) => {
        return deleteFn(API_ENDPOINTS.purchases+'/bulk_delete', ids, state);
    }

    const perDeleteBulkRecord = async (ids: Array<number>) => {
        return deleteFn(API_ENDPOINTS.purchases+'/bulk_delete_per', ids, state);
    }

    const checkAll = async (id: number) => {
        return checkAllFn(id, state);
    };

    const duplicate = async (id: number) => {
        return duplicateFn(API_ENDPOINTS.purchases+'/duplicate', id)
    }

    const getPurchases = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.purchases, data, state)
    };

    const getTrashPurchases = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.purchases+'/trash', data, state);
    };

    const getEditData = async (id: number): Promise<boolean> => {
        if (!id) {
            return false;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, `/api/purchases/${id}`);
            const purchase = response.data ?? {};
            const lines = Array.isArray(purchase.purchaselines) ? purchase.purchaselines : [];
            const netSubTotal = lines.reduce((sum: number, line: PurchaseLineRow) => sum + Number(line.row_subtotal || 0), 0);
            const totalPackQty = lines.reduce((sum: number, line: PurchaseLineRow) => sum + Number(line.packing_qty || 0), 0);
            const discountVal = Number(purchase.final_amount || 0) === 0
                ? 0
                : Math.max(netSubTotal + Number(purchase.shipping_charges || 0) - Number(purchase.final_amount || 0), 0);

            formData.value = {
                ...emptyForm(),
                ...purchase,
                transaction_date: purchase.transaction_date || today(),
                is_direct: Boolean(purchase.is_direct),
                purchaselines: lines,
                total_item: lines.length,
                total_pack_qty: totalPackQty,
                net_sub_total: netSubTotal,
                discount_val: discountVal,
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
        return restoreFn(API_ENDPOINTS.purchases+'/restore_records', ids, state)
    }

    return{
        state,
        changeStatus,
        Notify,
        getPurchases,
        getTrashPurchases,
        getEditData,
        formData,
        defaultFormData,
        emptyForm,
        deleteRecord,
        perDeleteBulkRecord,
        restoreBulkRecord,
        changeOrder,
        checkAll,
        duplicate,
        select_data
    }

}
