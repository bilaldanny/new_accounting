import { inject, reactive } from "vue";
import { API_ENDPOINTS } from './apiEndpoints'
import useCommons from "./common";

export default function useJournalEntryApprovals(){
    interface QueryParams {
        sort_by: string;
        sort_type: 'asc' | 'desc';
        show_record: number;
        page: number;
        search: string;
    }

    const {Notify, select_data, fetchWithRetry, changeOrderFn, checkAllFn, getData} = useCommons()
    const $swal = inject<any>('$swal', null);

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
        status: 'pending',
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

    const checkAll = async (id: number) => {
        return checkAllFn(id, state);
    };

    const getApprovals = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.journalEntryApprovals, data, state)
    };

    const swalButtons = {
        customClass: {
            confirmButton: 'btn btn-success ms-3',
            cancelButton: 'btn btn-danger',
        },
        buttonsStyling: false,
    };

    const failureMessage = (error: unknown): string => {
        if (window.axios.isAxiosError(error)) {
            return error.response?.data?.message
                || error.response?.data?.errors?.status?.[0]
                || error.response?.data?.errors?.reason?.[0]
                || 'An error occurred';
        }

        return 'Unexpected error occurred';
    };

    const approveJournal = async (id: number): Promise<boolean> => {
        if (!id) {
            return false;
        }

        let confirmed = false;

        if ($swal) {
            const result = await $swal.mixin(swalButtons).fire({
                title: 'Approve this journal entry?',
                text: 'The entry will be marked as approved and will count towards account balances.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, approve it',
                cancelButtonText: 'Cancel',
                reverseButtons: true,
            });
            confirmed = result.isConfirmed;
        } else {
            confirmed = window.confirm('Approve this journal entry?');
        }

        if (!confirmed) {
            return false;
        }

        try {
            const response = await fetchWithRetry(window.axios.post, `${API_ENDPOINTS.journalEntryApprovals}/${id}/approve`);
            Notify(response.data?.message || 'Successfully Approved', 'success');

            return true;
        } catch (error: unknown) {
            Notify(failureMessage(error), 'alert');

            return false;
        }
    }

    const rejectJournal = async (id: number): Promise<boolean> => {
        if (!id) {
            return false;
        }

        let confirmed = false;
        let reason = '';

        if ($swal) {
            const result = await $swal.mixin(swalButtons).fire({
                title: 'Reject this journal entry?',
                text: 'A rejected entry does not count towards account balances.',
                icon: 'warning',
                input: 'textarea',
                inputPlaceholder: 'Reason (optional)',
                inputAttributes: { maxlength: '500' },
                showCancelButton: true,
                confirmButtonText: 'Yes, reject it',
                cancelButtonText: 'Cancel',
                reverseButtons: true,
            });
            confirmed = result.isConfirmed;
            reason = String(result.value ?? '');
        } else {
            confirmed = window.confirm('Reject this journal entry?');
        }

        if (!confirmed) {
            return false;
        }

        try {
            const response = await fetchWithRetry(window.axios.post, `${API_ENDPOINTS.journalEntryApprovals}/${id}/reject`, { reason });
            Notify(response.data?.message || 'Successfully Rejected', 'success');

            return true;
        } catch (error: unknown) {
            Notify(failureMessage(error), 'alert');

            return false;
        }
    }

    return{
        state,
        Notify,
        getApprovals,
        approveJournal,
        rejectJournal,
        changeOrder,
        checkAll,
        select_data
    }
}
