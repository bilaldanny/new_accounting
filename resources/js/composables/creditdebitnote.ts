import { reactive, ref } from "vue";
import { API_ENDPOINTS } from './apiEndpoints'
import useCommons from "./common";

export type CreditDebitNoteAttachment = {
    id?: number | string;
    file_name: string;
    data_url?: string | null;
    ext?: string;
    deleted?: boolean;
};

export default function useCreditDebitNotes(){

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
      voucher_type: 'CN',
      voucher_no: '',
      voucher_date: today(),
      ref_no: '',
      comments: '',
      contact_id: '',
      account_id: '',
      amount: '',
      total_amount: 0,
      total_tax: 0,
      net_total: 0,
      status: 'pending',
      status_label: '',
      can_approve: false,
      contact_name: '',
      approved_by_name: '',
      approved_at: '',
      rejected_by_name: '',
      rejected_at: '',
      taccountdetails: [] as Array<Record<string, unknown>>,
      attachments: [] as CreditDebitNoteAttachment[],
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
        return deleteFn(API_ENDPOINTS.creditDebitNotes+'/bulk_delete', ids, state);
    }

    const checkAll = async (id: number) => {
        return checkAllFn(id, state);
    };

    const duplicate = async (id: number) => {
        return duplicateFn(API_ENDPOINTS.creditDebitNotes+'/duplicate', id)
    }

    const getCreditDebitNotes = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.creditDebitNotes, data, state)
    };

    const getEditData = async (id: number): Promise<boolean> => {
        if (!id) {
            return false;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, `${API_ENDPOINTS.creditDebitNotes}/${id}`);
            const note = response.data ?? {};
            const lines = Array.isArray(note.taccountdetails) ? note.taccountdetails : [];
            // the note's own two lines are always [contact line, offsetting line], in that order
            const offsetLine = lines[1];

            formData.value = {
                ...emptyForm(),
                ...note,
                voucher_type: note.voucher_type || 'CN',
                voucher_date: note.voucher_date || today(),
                account_id: offsetLine?.account_id ?? '',
                amount: note.total_amount ?? '',
                taccountdetails: lines,
                attachments: Array.isArray(note.attachments) ? note.attachments : [],
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
        getCreditDebitNotes,
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
