import { inject, reactive } from "vue";
import { API_ENDPOINTS } from './apiEndpoints'
import useCommons from "./common";

/**
 * Approval for the manual vouchers kept in t_accounts. Every family shares one backend controller
 * (VoucherApprovalController) and one set of screens; only the endpoint, the wording and the two
 * menu permission keys differ.
 */
export type VoucherFamily = 'journal' | 'payment' | 'expense' | 'deposit' | 'fundtransfer';

type VoucherApprovalConfig = {
    endpoint: string;
    /** Lower-case noun used in the confirmation dialogs. */
    label: string;
    approvePath: string;
    rejectPath: string;
};

export const VOUCHER_APPROVAL: Record<VoucherFamily, VoucherApprovalConfig> = {
    journal: {
        endpoint: API_ENDPOINTS.journalEntryApprovals,
        label: 'journal entry',
        approvePath: '/journalentry/:id/approve',
        rejectPath: '/journalentry/:id/reject',
    },
    payment: {
        endpoint: API_ENDPOINTS.paymentApprovals,
        label: 'payment',
        approvePath: '/acpayment/:id/approve',
        rejectPath: '/acpayment/:id/reject',
    },
    expense: {
        endpoint: API_ENDPOINTS.expenseApprovals,
        label: 'expense',
        approvePath: '/expense/:id/approve',
        rejectPath: '/expense/:id/reject',
    },
    deposit: {
        endpoint: API_ENDPOINTS.depositApprovals,
        label: 'deposit',
        approvePath: '/deposit/:id/approve',
        rejectPath: '/deposit/:id/reject',
    },
    fundtransfer: {
        endpoint: API_ENDPOINTS.fundTransferApprovals,
        label: 'fund transfer',
        approvePath: '/fundtransfer/:id/approve',
        rejectPath: '/fundtransfer/:id/reject',
    },
};

export default function useVoucherApprovals(family: VoucherFamily){
    interface QueryParams {
        sort_by: string;
        sort_type: 'asc' | 'desc';
        show_record: number;
        page: number;
        search: string;
    }

    const config = VOUCHER_APPROVAL[family];

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
        return getData(config.endpoint, data, state)
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

    const approveVoucher = async (id: number): Promise<boolean> => {
        if (!id) {
            return false;
        }

        let confirmed = false;

        if ($swal) {
            const result = await $swal.mixin(swalButtons).fire({
                title: `Approve this ${config.label}?`,
                text: `The ${config.label} will be marked as approved and will count towards account balances.`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, approve it',
                cancelButtonText: 'Cancel',
                reverseButtons: true,
            });
            confirmed = result.isConfirmed;
        } else {
            confirmed = window.confirm(`Approve this ${config.label}?`);
        }

        if (!confirmed) {
            return false;
        }

        try {
            const response = await fetchWithRetry(window.axios.post, `${config.endpoint}/${id}/approve`);
            Notify(response.data?.message || 'Successfully Approved', 'success');

            return true;
        } catch (error: unknown) {
            Notify(failureMessage(error), 'alert');

            return false;
        }
    }

    const rejectVoucher = async (id: number): Promise<boolean> => {
        if (!id) {
            return false;
        }

        let confirmed = false;
        let reason = '';

        if ($swal) {
            const result = await $swal.mixin(swalButtons).fire({
                title: `Reject this ${config.label}?`,
                text: `A rejected ${config.label} does not count towards account balances.`,
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
            confirmed = window.confirm(`Reject this ${config.label}?`);
        }

        if (!confirmed) {
            return false;
        }

        try {
            const response = await fetchWithRetry(window.axios.post, `${config.endpoint}/${id}/reject`, { reason });
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
        config,
        getApprovals,
        approveVoucher,
        rejectVoucher,
        changeOrder,
        checkAll,
        select_data
    }
}
