import { reactive, ref } from "vue";
import { API_ENDPOINTS } from './apiEndpoints'
import useCommons from "./common";

export type FundTransferLineRow = {
    id?: number | string;
    account_id: number | string | '';
    code?: string;
    account_name?: string;
    account_nature?: string;
    description: string;
    debit: number | string;
    credit: number | string;
};

export type FundTransferAttachment = {
    id?: number | string;
    file_name: string;
    data_url?: string | null;
    ext?: string;
    deleted?: boolean;
};

export default function useFundTransfers(){

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
      voucher_type: 'FT',
      voucher_no: '',
      voucher_date: today(),
      ref_no: '',
      comments: '',
      total_amount: 0,
      total_tax: 0,
      net_total: 0,
      status: 'pending',
      status_label: '',
      can_approve: false,
      approved_by_name: '',
      approved_at: '',
      rejected_by_name: '',
      rejected_at: '',
      taccountdetails: [] as FundTransferLineRow[],
      attachments: [] as FundTransferAttachment[],
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
        return deleteFn(API_ENDPOINTS.fundTransfers+'/bulk_delete', ids, state);
    }

    const checkAll = async (id: number) => {
        return checkAllFn(id, state);
    };

    const duplicate = async (id: number) => {
        return duplicateFn(API_ENDPOINTS.fundTransfers+'/duplicate', id)
    }

    const getFundTransfers = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.fundTransfers, data, state)
    };

    const getEditData = async (id: number): Promise<boolean> => {
        if (!id) {
            return false;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, `${API_ENDPOINTS.fundTransfers}/${id}`);
            const fundTransfer = response.data ?? {};

            formData.value = {
                ...emptyForm(),
                ...fundTransfer,
                voucher_type: fundTransfer.voucher_type || 'FT',
                voucher_date: fundTransfer.voucher_date || today(),
                taccountdetails: Array.isArray(fundTransfer.taccountdetails) ? fundTransfer.taccountdetails : [],
                attachments: Array.isArray(fundTransfer.attachments) ? fundTransfer.attachments : [],
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
        getFundTransfers,
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
