import { inject, reactive, ref } from "vue";
import useCommons from "./common";
import { API_ENDPOINTS } from './apiEndpoints'

export type PurchaseApprovalLine = {
    id?: number | string;
    product_name?: string;
    brand_name?: string;
    itemtype_name?: string;
    variation_name?: string;
    quantity?: number | string;
    packing_qty?: number | string;
    unit_name?: string;
    unit_rate?: number | string;
    line_amount?: number | string;
    discount_percent?: number | string;
    discount_rate?: number | string;
    net_amount?: number | string;
};

export type PurchaseApprovalLineGroup = {
    itemtype_name: string;
    lines: PurchaseApprovalLine[];
};

export type PurchaseApprovalPayment = {
    paid_on?: string;
    payment_ref_no?: string;
    amount?: number | string;
    method?: string;
    note?: string;
};

export type PurchaseApprovalView = {
    id: number | string;
    invoice_no?: string;
    sup_ref_no?: string;
    transaction_date?: string;
    transaction_date_label?: string;
    status?: string;
    status_label?: string;
    payment_status?: string;
    payment_status_label?: string;
    business_name?: string;
    address?: string;
    mobile?: string;
    company_name?: string;
    company_address?: string;
    branch_name?: string;
    shipping_details?: string;
    shipping_note?: string;
    additional_note?: string;
    discount_type?: string;
    discount_amount?: number | string;
    discount_val?: number | string;
    net_sub_total?: number | string;
    tax_amount?: number | string;
    shipping_charges?: number | string;
    final_amount?: number | string;
    formatted_amount?: string;
    approved_by?: number | string | null;
    approved_by_name?: string;
    approved_date?: string;
    purchaselines?: PurchaseApprovalLine[];
    line_groups?: PurchaseApprovalLineGroup[];
    payments?: PurchaseApprovalPayment[];
    can_approve?: boolean;
};

export default function usePurchaseApprovals(){
    interface QueryParams {
        sort_by: string;
        sort_type: 'asc' | 'desc';
        show_record: number;
        page: number;
        search: string;
    }

    const emptyView = (): PurchaseApprovalView => ({
        id: 0,
        invoice_no: '',
        sup_ref_no: '',
        transaction_date: '',
        transaction_date_label: '',
        status: '',
        status_label: '',
        payment_status: '',
        payment_status_label: '',
        business_name: '',
        address: '',
        mobile: '',
        company_name: '',
        company_address: '',
        branch_name: '',
        shipping_details: '',
        shipping_note: '',
        additional_note: '',
        discount_type: 'none',
        discount_amount: 0,
        discount_val: 0,
        net_sub_total: 0,
        tax_amount: 0,
        shipping_charges: 0,
        final_amount: 0,
        formatted_amount: '0.00',
        approved_by: null,
        approved_by_name: '',
        approved_date: '',
        purchaselines: [],
        line_groups: [],
        payments: [],
        can_approve: false,
    });

    const viewData = ref<PurchaseApprovalView>(emptyView());

    const {Notify, select_data, fetchWithRetry, changeOrderFn, deleteFn, checkAllFn, getData} = useCommons()
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
        payment_status: 'all',
        company_id: '',
        branch_id: '',
        contact_id: '',
        transaction_date: '',
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
        return deleteFn(API_ENDPOINTS.purchases+'/bulk_delete', ids, state);
    }

    const checkAll = async (id: number) => {
        return checkAllFn(id, state);
    };

    const getApprovals = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.purchaseApprovals, data, state)
    };

    const getViewData = async (id: number): Promise<boolean> => {
        if (!id) {
            return false;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, `${API_ENDPOINTS.purchaseApprovals}/${id}`);
            viewData.value = {
                ...emptyView(),
                ...response.data,
                line_groups: Array.isArray(response.data?.line_groups) ? response.data.line_groups : [],
                purchaselines: Array.isArray(response.data?.purchaselines) ? response.data.purchaselines : [],
                payments: Array.isArray(response.data?.payments) ? response.data.payments : [],
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

    const approvePurchase = async (id: number): Promise<boolean> => {
        if (!id) {
            return false;
        }

        let confirmed = false;

        if ($swal) {
            const result = await $swal.mixin({
                customClass: {
                    confirmButton: 'btn btn-success ms-3',
                    cancelButton: 'btn btn-danger',
                },
                buttonsStyling: false,
            }).fire({
                title: 'Approve this purchase?',
                text: 'The purchase will be marked as approved.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, approve it',
                cancelButtonText: 'Cancel',
                reverseButtons: true,
            });
            confirmed = result.isConfirmed;
        } else {
            confirmed = window.confirm('Approve this purchase?');
        }

        if (!confirmed) {
            return false;
        }

        try {
            const response = await fetchWithRetry(window.axios.post, `${API_ENDPOINTS.purchaseApprovals}/${id}/approve`);
            Notify(response.data?.message || 'Successfully Approved', 'success');

            return true;
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error)) {
                const message = error.response?.data?.message
                    || error.response?.data?.errors?.status?.[0]
                    || 'An error occurred';
                Notify(message, 'alert');
            } else {
                Notify('Unexpected error occurred', 'alert');
            }

            return false;
        }
    }

    return{
        state,
        Notify,
        getApprovals,
        getViewData,
        approvePurchase,
        viewData,
        emptyView,
        deleteRecord,
        changeOrder,
        checkAll,
        select_data
    }
}
