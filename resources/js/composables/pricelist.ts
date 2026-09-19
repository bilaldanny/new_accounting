import { reactive, ref } from "vue";
import { API_ENDPOINTS } from './apiEndpoints'
import useCommons from "./common";

export type PriceListLineRow = {
    id?: number | string;
    product_id: number | string;
    variation_id: number | string;
    product_name: string;
    sku?: string;
    unit_id: number | string | '';
    unit_name?: string;
    purchase_price: number | string;
    sell_price: number | string;
    profit_margin: number | string;
    discount: number | string;
    units: Array<{ id: number | string; text?: string; short_name?: string }>;
};

export default function usePriceLists(){

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
      brand_id: '',
      date: today(),
      discount: 0,
      status: 'pending',
      pricelistdetails: [] as PriceListLineRow[],
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
        sort_by: 'created_at',
        sort_type: 'desc' as 'asc' | 'desc',
        show_record: 10,
        page: 1,
        search: '',
        status: 'all',
        company_id: '',
        branch_id: '',
        brand_id: '',
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
        return deleteFn(API_ENDPOINTS.priceLists+'/bulk_delete', ids, state);
    }

    const perDeleteBulkRecord = async (ids: Array<number>) => {
        return deleteFn(API_ENDPOINTS.priceLists+'/bulk_delete_per', ids, state);
    }

    const checkAll = async (id: number) => {
        return checkAllFn(id, state);
    };

    const getPriceLists = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.priceLists, data, state)
    };

    const getTrashPriceLists = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.priceLists+'/trash', data, state);
    };

    const getEditData = async (id: number): Promise<boolean> => {
        if (!id) {
            return false;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, `${API_ENDPOINTS.priceLists}/${id}`);
            const priceList = response.data ?? {};
            const lines = Array.isArray(priceList.pricelistdetails) ? priceList.pricelistdetails : [];

            formData.value = {
                ...emptyForm(),
                ...priceList,
                date: priceList.date || today(),
                pricelistdetails: lines,
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
        return restoreFn(API_ENDPOINTS.priceLists+'/restore_records', ids, state)
    }

    return{
        state,
        Notify,
        getPriceLists,
        getTrashPriceLists,
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
