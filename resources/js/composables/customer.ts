import { reactive, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import useCommons from './common';
import { API_ENDPOINTS } from './apiEndpoints';

const phoneFields = ['mobile', 'alternate_no'] as const;

function applyPhoneDefaults<T extends Record<string, unknown>>(data: T, defaultDialCode: string): T {
    phoneFields.forEach((field) => {
        const value = data[field];

        if (value === null || value === undefined || value === '') {
            data[field] = defaultDialCode;
        }
    });

    return data;
}

export default function useCustomers() {
    const { props: pageProps } = usePage();
    const defaultDialCode = String(pageProps.dailCode ?? '');
    interface QueryParams {
        sort_by: string;
        sort_type: 'asc' | 'desc';
        show_record: number;
        page: number;
        search: string;
        status?: string;
        company_id?: string | number;
        branch_id?: string | number;
    }

    const formData = ref({
        company_id: '',
        branch_id: '',
        customer_group_id: '',
        currency_id: '',
        country_id: '',
        state_id: '',
        city_id: '',
        prefix: '',
        first_name: '',
        middle_name: '',
        last_name: '',
        business_name: '',
        user_type: 'customer',
        type: 'local',
        mobile: defaultDialCode,
        alternate_no: defaultDialCode,
        landline: '',
        email: '',
        pay_term: '',
        pay_type: 'day',
        opening_balance: 0,
        date_of_birth: '',
        code: '',
        credit_limit: 0,
        landmark: '',
        street_name: '',
        building_number: '',
        secondary_number: '',
        address: '',
        address_line_2: '',
        zipcode: '',
        ntn_number: '',
        active: true,
        link_account: 0,
    });

    const defaultFormData = ref({
        company_id: '',
        branch_id: '',
        customer_group_id: '',
        currency_id: '',
        country_id: '',
        state_id: '',
        city_id: '',
        prefix: '',
        first_name: '',
        middle_name: '',
        last_name: '',
        business_name: '',
        user_type: 'customer',
        type: 'local',
        mobile: defaultDialCode,
        alternate_no: defaultDialCode,
        landline: '',
        email: '',
        pay_term: '',
        pay_type: 'day',
        opening_balance: 0,
        date_of_birth: '',
        code: '',
        credit_limit: 0,
        landmark: '',
        street_name: '',
        building_number: '',
        secondary_number: '',
        address: '',
        address_line_2: '',
        zipcode: '',
        ntn_number: '',
        active: true,
        link_account: 0,
    });

    const {
        Notify,
        select_data,
        fetchWithRetry,
        changeStateFn,
        changeOrderFn,
        deleteFn,
        checkAllFn,
        duplicateFn,
        getData,
        restoreFn,
    } = useCommons();

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
            branch_id: '',
        },
        loading: false,
        modalLoading: true,
        edit_ids: [],
        selectAll: false,
        trash_count: 0,
        loadingIds: new Set(),
    });

    const changeStatus = async (ids: Array<number>, status: string) => {
        return changeStateFn(`${API_ENDPOINTS.customers}/statusupdate`, ids, status, state);
    };

    const changeOrder = async (event: Event) => {
        return changeOrderFn(event, state);
    };

    const deleteRecord = async (ids: Array<number>) => {
        return deleteFn(`${API_ENDPOINTS.customers}/bulk_delete`, ids, state);
    };

    const perDeleteBulkRecord = async (ids: Array<number>) => {
        return deleteFn(`${API_ENDPOINTS.customers}/bulk_delete_per`, ids, state);
    };

    const checkAll = async (id: number) => {
        return checkAllFn(id, state);
    };

    const duplicate = async (id: number) => {
        return duplicateFn(`${API_ENDPOINTS.customers}/duplicate`, id);
    };

    const getCustomers = async (data: QueryParams) => {
        return getData(API_ENDPOINTS.customers, data, state);
    };

    const getTrashCustomers = async (data: QueryParams) => {
        return getData(`${API_ENDPOINTS.customers}/trash`, data, state);
    };

    const getEditData = async (id: number) => {
        if (!id) {
            return;
        }

        try {
            const response = await fetchWithRetry(window.axios.get, `/api/customers/${id}`);
            formData.value = applyPhoneDefaults(response.data, defaultDialCode);
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error)) {
                if (error.response?.data?.message !== 'Unauthenticated.') {
                    Notify(error.response?.data?.message || 'An error occurred', 'alert');
                }
            } else {
                Notify('Unexpected error occurred', 'alert');
            }
        }
    };

    const restoreBulkRecord = async (ids: Array<number>) => {
        return restoreFn(`${API_ENDPOINTS.customers}/restore_records`, ids, state);
    };

    const customersdata = ref<Array<{ id: number | string; text?: string; business_name?: string }>>([]);

    const fetchCustomersDropdown = async (
        companyId: string | number | null | undefined,
        branchId: string | number | null | undefined,
    ) => {
        if (!companyId || !branchId) {
            customersdata.value = [];
            return;
        }

        try {
            const response = await fetchWithRetry(
                window.axios.get,
                `${API_ENDPOINTS.fetchCustomers}?company_id=${companyId}&branch_id=${branchId}`,
            );
            customersdata.value = response.data;
        } catch (error: unknown) {
            customersdata.value = [];

            if (window.axios.isAxiosError(error) && error.response?.data?.message !== 'Unauthenticated.') {
                Notify(error.response?.data?.message || 'An error occurred', 'alert');
            }
        }
    };

    const getContactDetail = async (params: {
        contact_id: string | number;
        company_id?: string | number;
        branch_id?: string | number;
    }) => {
        const response = await fetchWithRetry(window.axios.get, `/api/customers/${params.contact_id}`);

        return response.data;
    };

    const getLedger = async (params: {
        contact_id: string | number;
        company_id?: string | number;
        branch_id?: string | number;
        start_date: string;
        end_date: string;
    }) => {
        const response = await fetchWithRetry(window.axios.get, API_ENDPOINTS.fetchLedger, { params });

        return response.data;
    };

    const linkCustomerCoa = async (id: number | string) => {
        const response = await fetchWithRetry(window.axios.post, API_ENDPOINTS.linkCustomerCoa(id));

        return response.data;
    };

    const generateCustomerCode = async (
        companyId: string | number | null | undefined,
        branchId?: string | number | null | undefined,
    ) => {
        if (! companyId) {
            return '';
        }

        try {
            const params = new URLSearchParams({
                company_id: String(companyId),
            });

            if (branchId) {
                params.set('branch_id', String(branchId));
            }

            const response = await fetchWithRetry(
                window.axios.get,
                `${API_ENDPOINTS.customerGenerateCode}?${params.toString()}`,
            );

            return String(response.data?.code ?? '');
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error) && error.response?.data?.message !== 'Unauthenticated.') {
                Notify(error.response?.data?.message || 'Unable to generate contact ID', 'alert');
            }

            return '';
        }
    };

    return {
        state,
        changeStatus,
        Notify,
        getCustomers,
        getTrashCustomers,
        getEditData,
        formData,
        defaultFormData,
        deleteRecord,
        perDeleteBulkRecord,
        restoreBulkRecord,
        changeOrder,
        checkAll,
        duplicate,
        select_data,
        customersdata,
        fetchCustomersDropdown,
        getContactDetail,
        getLedger,
        linkCustomerCoa,
        generateCustomerCode,
    };
}
