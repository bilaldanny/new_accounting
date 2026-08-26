import { inject, reactive, ref } from "vue";
import useCommons from "./common";
import { API_ENDPOINTS } from './apiEndpoints'

export type SellApprovalLine = {
    id?: number | string;
    product_name?: string;
    sku?: string;
    brand_name?: string;
    itemtype_name?: string;
    variation_name?: string;
    quantity?: number | string;
    display_quantity?: number | string;
    packing_qty?: number | string;
    unit_name?: string;
    unit_price?: number | string;
    unit_rate?: number | string;
    line_amount?: number | string;
    discount_percent?: number | string;
    discount_rate?: number | string;
    unit_price_after_discount?: number | string;
    row_subtotal?: number | string;
    net_amount?: number | string;
};

export type SellApprovalLineGroup = {
    itemtype_name: string;
    lines: SellApprovalLine[];
};

export type SellApprovalPayment = {
    paid_on?: string;
    payment_ref_no?: string;
    amount?: number | string;
    method?: string;
    note?: string;
};

export type SellApprovalView = {
    id: number | string;
    invoice_no?: string;
    transaction_date?: string;
    transaction_date_label?: string;
    status?: string;
    status_label?: string;
    payment_status?: string;
    payment_status_label?: string;
    shipping_status?: string;
    shipping_status_label?: string;
    business_name?: string;
    customer_name?: string;
    address?: string;
    mobile?: string;
    company_name?: string;
    company_address?: string;
    branch_name?: string;
    pay_term?: number | string;
    pay_type?: string;
    shipping_details?: string;
    shipping_address?: string;
    shipping_note?: string;
    delivered_to?: string;
    billty_no?: string;
    billty_date?: string;
    billty_date_label?: string;
    billty_image_url?: string | null;
    packing?: string;
    additional_note?: string;
    discount_type?: string;
    discount_amount?: number | string;
    discount_val?: number | string;
    net_sub_total?: number | string;
    tax_amount?: number | string;
    shipping_charges?: number | string;
    final_amount?: number | string;
    formatted_amount?: string;
    credit_limit?: number | string;
    approved_by?: number | string | null;
    approved_by_name?: string;
    approved_date?: string;
    selllines?: SellApprovalLine[];
    line_groups?: SellApprovalLineGroup[];
    payments?: SellApprovalPayment[];
    can_approve?: boolean;
};

export default function useSellApprovals(){
    interface QueryParams {
        sort_by: string;
        sort_type: 'asc' | 'desc';
        show_record: number;
        page: number;
        search: string;
    }

    const emptyView = (): SellApprovalView => ({
        id: 0,
        invoice_no: '',
        transaction_date: '',
        transaction_date_label: '',
        status: '',
        status_label: '',
        payment_status: '',
        payment_status_label: '',
        shipping_status: '',
        shipping_status_label: '',
        business_name: '',
        customer_name: '',
        address: '',
        mobile: '',
        company_name: '',
        company_address: '',
        branch_name: '',
        pay_term: '',
        pay_type: '',
        shipping_details: '',
        shipping_address: '',
        shipping_note: '',
        delivered_to: '',
        billty_no: '',
        billty_date: '',
        billty_date_label: '',
        billty_image_url: null,
        packing: '',
        additional_note: '',
        discount_type: 'none',
        discount_amount: 0,
        discount_val: 0,
        net_sub_total: 0,
        tax_amount: 0,
        shipping_charges: 0,
        final_amount: 0,
        formatted_amount: '0.00',
        credit_limit: 0,
        approved_by: null,
        approved_by_name: '',
        approved_date: '',
        selllines: [],
        line_groups: [],
        payments: [],
        can_approve: false,
    });

    const viewData = ref<SellApprovalView>(emptyView());

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
        status: 'final',
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
        return deleteFn(API_ENDPOINTS.sells+'/bulk_delete', ids, state);
    }

    const checkAll = async (id: number) => {
        return checkAllFn(id, state);
    };

    const getApprovals = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.sellApprovals, data, state)
    };

    const getViewData = async (id: number): Promise<boolean> => {
        if (!id) {
            return false;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, `${API_ENDPOINTS.sellApprovals}/${id}`);
            viewData.value = {
                ...emptyView(),
                ...response.data,
                line_groups: Array.isArray(response.data?.line_groups) ? response.data.line_groups : [],
                selllines: Array.isArray(response.data?.selllines) ? response.data.selllines : [],
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

    const approveSell = async (id: number): Promise<boolean> => {
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
                title: 'Approve this sell?',
                text: 'The invoice will be marked as approved.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, approve it',
                cancelButtonText: 'Cancel',
                reverseButtons: true,
            });
            confirmed = result.isConfirmed;
        } else {
            confirmed = window.confirm('Approve this sell?');
        }

        if (!confirmed) {
            return false;
        }

        try {
            const response = await fetchWithRetry(window.axios.post, `${API_ENDPOINTS.sellApprovals}/${id}/approve`);
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
        approveSell,
        viewData,
        emptyView,
        deleteRecord,
        changeOrder,
        checkAll,
        select_data
    }
}
