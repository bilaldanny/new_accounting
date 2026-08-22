import { reactive, ref } from "vue";
import useCommons from "./common";
import { API_ENDPOINTS } from './apiEndpoints'

export default function useProducts(){

    interface QueryParams {
        sort_by: string;
        sort_type: 'asc' | 'desc';
        show_record: number;
        page: number;
        search: string;
    }

    const emptyDetail = () => ({
      variation_name: 'dummy',
      default_purchase_price: '',
      largequantity: '',
      smallquantity: '',
      profit_percent: '',
      default_sell_price: '',
      variation_image: '',
      sku: '',
    });

    const formData = ref({
      'company_id':'',
      'name':'',
      'type':'single',
      'unit_id':'',
      'brand_id':'',
      'category_id':'',
      'subcategory_id':'',
      'itemtype_id':'',
      'warranty_id':'',
      'alert_qty':'',
      'sku':'',
      'weight':'',
      'product_desc':'',
      'product_image':'',
      'product_image_url':'',
      'active':true,
      'productdetail': [emptyDetail()],
    });

    const defaultFormData = ref({
      'company_id':'',
      'name':'',
      'type':'single',
      'unit_id':'',
      'brand_id':'',
      'category_id':'',
      'subcategory_id':'',
      'itemtype_id':'',
      'warranty_id':'',
      'alert_qty':'',
      'sku':'',
      'weight':'',
      'product_desc':'',
      'product_image':'',
      'product_image_url':'',
      'active':true,
      'productdetail': [emptyDetail()],
    });

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
        sort_type: 'desc',
        show_record: 10,
        page: 1,
        search: '',
        status: 'all',
        company_id: '',
        category_id: '',
        subcategory_id: '',
        itemtype_id: '',
        brand_id: '',
        type: 'all',
      },
      loading: false,
      modalLoading: true,
      edit_ids: [],
      selectAll: false,
      trash_count:0,
      loadingIds: new Set(),
    });

    const changeStatus = async (ids: Array<number>, status: string) => {
        return changeStateFn(API_ENDPOINTS.products+'/statusupdate',ids,status,state);
    }

    const changeOrder = async (event: Event) => {
        return changeOrderFn(event, state)
    };

    const deleteRecord = async (ids: Array<number>) => {
        return deleteFn(API_ENDPOINTS.products+'/bulk_delete', ids, state);
    }

    const perDeleteBulkRecord = async (ids: Array<number>) => {
        return deleteFn(API_ENDPOINTS.products+'/bulk_delete_per', ids, state);
    }

    const checkAll = async (id: number) => {
        return checkAllFn(id, state);
    };

    const duplicate = async (id: number) => {
        return duplicateFn(API_ENDPOINTS.products+'/duplicate', id)
    }

    const getProducts = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.products, data, state)
    };

    const getTrashProducts = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.products+'/trash', data, state);
    };

    const getEditData = async (id: number): Promise<boolean> => {
        if (!id) {
            return false;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, `/api/products/${id}`);
            formData.value = {
                ...response.data,
                productdetail: Array.isArray(response.data?.productdetail) && response.data.productdetail.length > 0
                    ? response.data.productdetail
                    : [emptyDetail()],
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
        return restoreFn(API_ENDPOINTS.products+'/restore_records', ids, state)
    }

    return{
        state,
        changeStatus,
        Notify,
        getProducts,
        getTrashProducts,
        getEditData,
        formData,
        defaultFormData,
        emptyDetail,
        deleteRecord,
        perDeleteBulkRecord,
        restoreBulkRecord,
        changeOrder,
        checkAll,
        duplicate,
        select_data
    }

}
