import { reactive, ref } from "vue";
import { API_ENDPOINTS } from './apiEndpoints'
import useCommons from "./common";

export type DepositLineRow = {
    id?: number | string;
    account_id: number | string | '';
    code?: string;
    account_name?: string;
    account_nature?: string;
    description: string;
    debit: number | string;
    credit: number | string;
};

export type DepositAttachment = {
    id?: number | string;
    file_name: string;
    data_url?: string | null;
    ext?: string;
    deleted?: boolean;
};

export default function useDeposits(){

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
      voucher_type: 'BD',
      voucher_no: '',
      voucher_date: today(),
      comments: '',
      cheque_no: '',
      total_amount: 0,
      total_tax: 0,
      net_total: 0,
      status: 'pending',
      taccountdetails: [] as DepositLineRow[],
      attachments: [] as DepositAttachment[],
    });

    const formData = ref(emptyForm());
    const defaultFormData = ref(emptyForm());

    const {Notify, select_data, fetchWithRetry, changeOrderFn, deleteFn, checkAllFn, duplicateFn, getData} = useCommons()

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
        return deleteFn(API_ENDPOINTS.deposits+'/bulk_delete', ids, state);
    }

    const checkAll = async (id: number) => {
        return checkAllFn(id, state);
    };

    const duplicate = async (id: number) => {
        return duplicateFn(API_ENDPOINTS.deposits+'/duplicate', id)
    }

    const getDeposits = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.deposits, data, state)
    };

    const getEditData = async (id: number): Promise<boolean> => {
        if (!id) {
            return false;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, `${API_ENDPOINTS.deposits}/${id}`);
            const deposit = response.data ?? {};

            formData.value = {
                ...emptyForm(),
                ...deposit,
                voucher_type: deposit.voucher_type || 'BD',
                voucher_date: deposit.voucher_date || today(),
                taccountdetails: Array.isArray(deposit.taccountdetails) ? deposit.taccountdetails : [],
                attachments: Array.isArray(deposit.attachments) ? deposit.attachments : [],
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
        getDeposits,
        getEditData,
        formData,
        defaultFormData,
        emptyForm,
        deleteRecord,
        changeOrder,
        checkAll,
        duplicate,
        select_data
    }

}
