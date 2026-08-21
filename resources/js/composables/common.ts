import { reactive, ref, nextTick, inject, getCurrentInstance } from "vue";
import { toast } from 'vue-sonner';
import { resolvePublicAppBaseUrl } from '@/utils/publicAppUrl';
import { formatNumber } from '@/utils/numberFormat';
import { useNotificationStore } from '@/utils/vueNotification'

/** Shared across all `useCommons()` callers — coalesces concurrent identical fetches. */
const sharedFetchPromises = new Map<string, Promise<void>>();

/** One reactive source for list data consumed across pages/components. */
const sharedMenusdata = ref([]);
const sharedPermissiondata = ref([]);
const sharedRolesdata = ref([]);
const sharedCountriesdata = ref([]);
const sharedStatesdata = ref([]);
const sharedCitiesdata = ref([]);
const sharedCompaniesdata = ref([]);
const sharedBranchesdata = ref([]);
const sharedDepartmentsdata = ref([]);
const sharedCustomerGroupsdata = ref([]);
const sharedUnitsdata = ref([]);
const sharedCategoriesdata = ref([]);
const sharedBrandsdata = ref([]);
const sharedItemTypesdata = ref([]);
const sharedSubcategoriesdata = ref([]);
const sharedWarrantiesdata = ref([]);

type IsotopeInstance = {
    layout: () => void;
};

const suppressedNotificationMessages = new Set([
    'Server Error',
]);

function shouldShowNotification(message: unknown, type: string): boolean {
    if (type === 'success' || type === 'warning') {
        return true;
    }

    return ! suppressedNotificationMessages.has(String(message ?? '').trim());
}

export default function useCommons(){

    let isotope: IsotopeInstance | null = null;
    let IsotopeConstructor: (new (
        element: Element,
        options: Record<string, unknown>,
    ) => IsotopeInstance) | null = null;
    const select_data = ref([1,10,25,50,100]);
    const menusdata = sharedMenusdata;
    const permissiondata = sharedPermissiondata;
    const rolesdata = sharedRolesdata;
    const countriesdata = sharedCountriesdata;
    const statesdata = sharedStatesdata;
    const citiesdata = sharedCitiesdata;
    const companiesdata = sharedCompaniesdata;
    const branchesdata = sharedBranchesdata;
    const departmentsdata = sharedDepartmentsdata;
    const customergroupsdata = sharedCustomerGroupsdata;
    const unitsdata = sharedUnitsdata;
    const categoriesdata = sharedCategoriesdata;
    const brandsdata = sharedBrandsdata;
    const itemtypesdata = sharedItemTypesdata;
    const subcategoriesdata = sharedSubcategoriesdata;
    const warrantiesdata = sharedWarrantiesdata;
    const loading = ref(false);
    const MAX_RETRIES = 1;
    const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
    const appUrl = resolvePublicAppBaseUrl();
    let $swal = null;
    if (getCurrentInstance()) {
        try {
            $swal = inject('$swal');
        } catch {
            $swal = null;
        }
    }
    const defaultSearch = ref({
        sort_by: 'created_at',
        sort_type: 'desc',
        show_record: 10,
        page: 1,
        search: '',
        status: 'all',
        parent_id: 'all',
    })

    const { setNotification } = useNotificationStore()

    const playNotificationSound = (audioId: string) => {
        const audio = document.getElementById(audioId) as HTMLAudioElement | null;

        if (!audio) {
            return;
        }

        audio.currentTime = 0;
        void audio.play().catch(() => {});
    };

    /* Notify */
    const Notify = async (message: string, type = 'success') => {
        if (! shouldShowNotification(message, type)) {
            return;
        }

        // toast.dismiss();

        // switch (type) {
        //     case 'success':
        //         toast.success(message);
        //         break;
        //     case 'alert':
        //     case 'error':
        //         toast.error(message);
        //         break;
        //     default:
        //         toast(message);
        // }

        setNotification(
			{
			  "message": message,
			  "type": type,
			  "showIcon": true,
			  "dismiss": {
			    "manually": true,
			    "automatically": true
			  },
			  "duration": 10000,
			  "showDurationProgress": true,
			  "appearance": "light"
			}
		);
		if (type === 'success') {
			playNotificationSound('success-audio');
		}

		if (type === 'error' || type === 'alert') {
			playNotificationSound('error-audio');
		}

		if (type === 'warning') {
			playNotificationSound('warning-audio');
		}
    };

    /* Get Saved Value */
    const getSavedValue = (key, parseFn = (v) => v, defaultValue = null) => {
        if (typeof localStorage === 'undefined') {
            return defaultValue;
        }

        try {
            const savedValue = localStorage.getItem(key);
            return savedValue !== null ? parseFn(savedValue) : defaultValue;
        } catch (error) {
            console.error(`Error accessing localStorage for key: ${key}`, error);
            return defaultValue;
        }
    };

    /* Formated Text */
    const formatedText = (text: unknown) => {
        if (text == null) {
            return '';
        }
        return String(text)
            .replace(/\bindex\b/gi, '') // remove "index"
            .replace(/[-._]/g, ' ') // replace -, . and _ with space
            .replace(/\b\w/g, char => char.toUpperCase()); // capitalize each word
    };

    /* Fetch Menu */
    const fetchMenu = async () => {
        await runDeduped('api/fetchmenus', async () => {
            loading.value = true;
            try {
                const response = await fetchWithRetry(axios.get, '/api/fetchmenus');

                menusdata.value = response.data;

                await nextTick();
                setTimeout(() => {
                    relayoutTheGrid();
                }, 200);
            } catch (error) {
                if (error.response?.data?.message !== 'Unauthenticated.') {
                    Notify(error.response?.data?.message || 'An error occurred', 'alert');
                }
            } finally {
                loading.value = false;
            }
        });
    };

    /* Permission Layout */
    const relayoutTheGrid = async () => {
        if (typeof window === 'undefined') {
            return;
        }

        const elem = document.querySelector('.my-grid');
        if (!elem) {
            return;
        }

        if (!IsotopeConstructor) {
            const module = await import('isotope-layout');
            IsotopeConstructor = module.default;
        }

        if (!isotope) {
            isotope = new IsotopeConstructor(elem, {
                itemSelector: '.my-card',
                layoutMode: 'masonry',
                gutter: 24,
            });
        } else {
            isotope.layout();
        }
    };

    const resolveVueformInstance = (formRef: unknown) => {
        const wrapper = (formRef as { value?: unknown })?.value ?? formRef;

        if (! wrapper || typeof wrapper !== 'object') {
            return null;
        }

        const vueformRef = (wrapper as { vueform$?: { value?: unknown } | unknown }).vueform$;

        if (vueformRef && typeof vueformRef === 'object' && 'value' in vueformRef) {
            return (vueformRef as { value: unknown }).value ?? null;
        }

        return vueformRef ?? null;
    };

    /* Form Error */
    const handleError = (error, details, form$) => {
        const vueform = resolveVueformInstance(form$);
        let notifyMessage = 'An error occurred';

        vueform?.messageBag?.clear?.();

        switch (details?.type) {
            // Error occured while preparing elements (no submit happened)
            case 'prepare':
                notifyMessage = 'Could not prepare form';
                vueform?.messageBag?.append?.(notifyMessage);
                break;

            // Error occured because response status is outside of 2xx
            case 'submit':
                if (error?.response?.status === 419) {
                    window.location.href = 'login';

                    return;
                }

                if (error?.response?.data?.errors) {
                    const validationMessages = Object.values(error.response.data.errors).flat() as string[];

                    validationMessages.forEach((message) => {
                        vueform?.messageBag?.append?.(message);
                    });

                    notifyMessage = validationMessages[0] ?? notifyMessage;
                } else {
                    notifyMessage = error?.response?.data?.message ?? notifyMessage;
                    vueform?.messageBag?.append?.(notifyMessage);
                }
                break;

            // Request cancelled (no response object)
            case 'cancel':
                notifyMessage = 'Request cancelled';
                vueform?.messageBag?.append?.(notifyMessage);
                break;

            // Some other errors happened (no response object)
            case 'other':
            default:
                notifyMessage = 'Couldn\'t submit form';
                vueform?.messageBag?.append?.(notifyMessage);
                break;
        }

        if (error?.response?.data?.message !== 'Unauthenticated.') {
            Notify(notifyMessage, 'alert');
        }
    };

    /* Form Success */
    const handleSuccess = (response, form$) => {
        if(response.data.errormessage){
            if(response.data.errormessage.errorInfo){
                Notify(response.data.errormessage.errorInfo[response.data.errormessage.errorInfo.length-1], 'alert');
            }else{
                Notify(response.data.errormessage, 'alert');
            }
        }else{
            Notify(response.data.message,'success');
            document.getElementById('SearchBtn').click();
            document.querySelectorAll('.btn-close').forEach(el => el.click());
        }
    }

    /* Retry Function */
        const fetchWithRetry = async (fn, ...args) => {
            let attempts = 0;
            while (attempts < MAX_RETRIES) {
                try {
                    return await fn(...args); // Spread args to match fn's expected parameters
                } catch (error) {
                    attempts++;
                    if (attempts >= MAX_RETRIES) {
                        if(error.response?.data?.message !== 'Unauthenticated.'){
                            Notify(error.response?.data?.message || 'An error occurred', 'alert');
                        }
                        throw error;
                    }
                    await sleep(attempts * 1000); // Wait before retrying
                }
            }
        };
    /* Retry Function */

    /**
     * Multiple components often call the same list loaders in parallel (e.g. two modals mounting).
     * One in-flight request per key; additional callers await the same promise.
     */
    const runDeduped = async (key: string, exec: () => Promise<void>) => {
        const existing = sharedFetchPromises.get(key);
        if (existing) {
            await existing;
            return;
        }
        const run = (async () => {
            await exec();
        })();
        sharedFetchPromises.set(key, run);
        try {
            await run;
        } finally {
            sharedFetchPromises.delete(key);
        }
    };

    /* Change Status */
        const changeStateFn = async (url, ids, status, state) => {
            ids.forEach(id => state.loadingIds.add(id))
            try {
                const response = await fetchWithRetry(
                    axios.post,
                    url,
                    { ids, status }
                )

                Notify(response.data.message, 'success')

                if (response.status === 200) {
                    document.getElementById('SearchBtn').click()
                    document.getElementById('MainCheckbox').checked = false
                }

                state.edit_ids = []

            }catch (error) {
                Notify(error.response?.data?.message || 'An error occurred', 'alert')
            } finally {
                // remove these ids from loading state
                ids.forEach(id => state.loadingIds.delete(id))
            }
        }
    /* Change Status */

    /* Change sorting order */
        const changeOrderFn = async (event, state) => {
            const target = event.target;
            const orderType = target.getAttribute('data-ordertype');
            const colName = target.getAttribute('data-colname');

            document.querySelectorAll('[data-colname]').forEach(el => {
                el.classList.remove('sorting_asc', 'sorting_desc');
            });

            state.search.sort_by = colName;
            state.search.sort_type = orderType;

            // Toggle sorting order
            if (orderType === 'asc') {
                target.setAttribute('data-ordertype', 'desc');
                target.classList.add('sorting_desc');
                target.classList.remove('sorting_asc');
            } else {
                target.setAttribute('data-ordertype', 'asc');
                target.classList.add('sorting_asc');
                target.classList.remove('sorting_desc');
            }

            document.getElementById('SearchBtn').click();
        };
    /* Change sorting order */

    /* Delete Function */
        const deleteFn = async (url, ids, state) => {
            let confirmed = false;
            if ($swal) {
                const swalWithBootstrapButtons = $swal.mixin({
                    customClass: {
                    confirmButton: 'btn btn-success ms-3',
                    cancelButton: 'btn btn-danger',
                    },
                    buttonsStyling: false,
                });
                const result = await swalWithBootstrapButtons.fire({
                    title: 'Are you sure?',
                    text: "You won't be able to revert this!",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, delete it!',
                    cancelButtonText: 'No, cancel!',
                    reverseButtons: true,
                });
                confirmed = result.isConfirmed;
            } else {
                confirmed = window.confirm("Are you sure? You won't be able to revert this!");
            }

            if (confirmed) {
                ids.forEach(id => state.loadingIds.add(id))

                try {
                    const response = await fetchWithRetry(axios.post, url, ids);

                    if (response.data === '406') {
                        Notify('Access denied', 'error');
                        if ($swal) await $swal.fire('Not Deleted!', 'Permission denied to delete this.', 'warning');
                    }else{
                        const data = state.records.data.filter(menu => !ids.includes(menu.id));
                        if(data.length <= 0){
                            if(state.records.current_page === 1){
                                state.search.page = 1
                            }else{
                                if(state.records.current_page+1 < state.records.last_page){
                                    state.search.page = state.records.current_page*1 + 1;
                                }else if(state.records.current_page+1 === state.records.last_page){
                                    state.search.page = state.records.last_page*1 - 1;
                                }else{
                                    state.search.page = state.records.current_page*1 - 1;
                                }
                            }
                        }
                        state.edit_ids = [];
                        Notify(response.data.message, 'success');
                        if (response.status === 200) {
                            document.getElementById('SearchBtn').click()
                            document.getElementById('MainCheckbox').checked = false
                        }
                        if ($swal) await $swal.fire('Deleted!', 'Your record was deleted successfully.', 'success');
                    }

                }catch (error) {
                    if(error.response?.data?.message !== 'Unauthenticated.'){
                        Notify(error.response?.data?.message || 'An error occurred', 'alert');
                    }
                } finally {
                    // remove these ids from loading state
                    ids.forEach(id => state.loadingIds.delete(id))
                }
            } else if ($swal) {
                await $swal.fire('Cancelled', 'Your record is safe :)', 'error');
            }

        }
    /* Delete Function */

    // Check all records or a specific one
        const checkAllFn = async (id, state) => {
            if (id !== undefined) {
                const filter = state.records.data.filter((menu) => menu.id === id);
                if (state.edit_ids.includes(id)) {
                    state.edit_ids.splice(state.edit_ids.indexOf(id), 1);
                } else {
                    state.edit_ids.push(id);
                }
            } else {
                if (state.selectAll) {
                    state.edit_ids = state.records.data.map((item) => item.id);
                } else {
                    state.edit_ids = [];
                }
            }
        };
    // Check all records or a specific one

    /* Duplicate Record */
        const duplicateFn = async (url, id) => {
            try {
                const response = await fetchWithRetry(axios.post, url ,{ id });
                if(response.data.errormessage){
                    if(error.response?.data?.message !== 'Unauthenticated.'){
                        Notify(error.response?.data?.message || 'An error occurred', 'alert');
                    }
                }else{
                    Notify(response.data.message, 'success');
                    document.getElementById('SearchBtn').click();
                }
            } catch (error) {
                if(error.response?.data?.message !== 'Unauthenticated.'){
                    Notify(error.response?.data?.message || 'An error occurred', 'alert');
                }
            }
        }
    /* Duplicate Record */

    /* getData */
        const getData = async (url, data, state) => {
            state.loading = true;
            try {
                data.cur_page = state.search.page;

                const response = await fetchWithRetry(axios.get, url, { params: data });
                state.records = response.data.data;
                state.trash_count = response.data.trash_count;

                if ('can_add_branch' in response.data) {
                    state.can_add_branch = response.data.can_add_branch;
                }

                if(state.search.page > state.records.last_page){
                    state.search.page = state.records.last_page;
                }
            }catch (error) {
                if(error.response?.data?.message !== 'Unauthenticated.'){
                    Notify(error.response?.data?.message || 'An error occurred', 'alert');
                }
            } finally {
                state.loading = false;
            }
        }
    /* getData */

    /* Restore */
        const restoreFn = async (url, ids, state) => {
            let confirmed = false;
            if ($swal) {
                const swalWithBootstrapButtons = $swal.mixin({
                    customClass: {
                    confirmButton: 'btn btn-success ms-3',
                    cancelButton: 'btn btn-danger',
                    },
                    buttonsStyling: false,
                });
                const result = await swalWithBootstrapButtons.fire({
                    title: 'Are you sure?',
                    text: "This will restore the deleted record. You can revert it later if needed.",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, restore it!',
                    cancelButtonText: 'No, cancel!',
                    reverseButtons: true,
                });
                confirmed = result.isConfirmed;
            } else {
                confirmed = window.confirm("Are you sure? This will restore the deleted record.");
            }

            if (confirmed) {
                try {
                    const response = await fetchWithRetry(axios.post, url, ids);
                    if (response.data === '406') {
                        Notify('Access denied', 'error');
                        if ($swal) await $swal.fire('Not Restored!', 'Permission denied to restore this.', 'warning');
                    } else {
                        const data = state.records.data.filter(menu => !ids.includes(menu.id));
                        if(data.length <= 0){
                            if(state.records.current_page === 1){
                                state.search.page = 1
                            }else{
                                if(state.records.current_page+1 < state.records.last_page){
                                state.search.page = state.records.current_page*1 + 1;
                                }else if(state.records.current_page+1 === state.records.last_page){
                                state.search.page = state.records.last_page*1 - 1;
                                }else{
                                state.search.page = state.records.current_page*1 - 1;
                                }
                            }
                        }
                        if (response.status === 200) {
                            document.getElementById('SearchBtn').click()
                            document.getElementById('MainCheckbox').checked = false
                        }
                        Notify(response.data.message, 'success');
                        if ($swal) await $swal.fire('Restored!', 'Your record was restored successfully.', 'success');
                    }
                } catch (error) {
                    if(error.response?.data?.message !== 'Unauthenticated.'){
                        Notify(error.response?.data?.message || 'An error occurred', 'alert');
                    }
                }
            } else if ($swal) {
                await $swal.fire('Cancelled', 'Your record is safe :)', 'error');
            }
        }
    /* Restore */

    /* Fetch Permission Menus */
    const fetchPerMenu = async () => {
        await runDeduped('api/fetchpermenus', async () => {
            loading.value = true;
            try {
                const response = await fetchWithRetry(axios.get, '/api/fetchpermenus');

                menusdata.value = response.data;

                await nextTick();
                setTimeout(() => {
                    relayoutTheGrid();
                }, 200);
            } catch (error) {
                if (error.response?.data?.message !== 'Unauthenticated.') {
                    Notify(error.response?.data?.message || 'An error occurred', 'alert');
                }
            } finally {
                loading.value = false;
            }
        });
    };

    const fetchpermission = async (
        company_id: string | number | null | undefined,
        branch_id: string | number | null | undefined,
        department_id: string | number | null | undefined,
        role_id: string | number | null | undefined,
    ) => {
        permissiondata.value = [];
        const response = await axios.get('/api/fetchpermissions', {
            params: {
                company_id,
                branch_id,
                department_id,
                role_id,
            },
        });
        if (response.data.length !== 0) {
            permissiondata.value = response.data;
        } else {
            permissiondata.value = [];
        }
    };

    const getPermission = async (
        company_id: string | number | null | undefined,
        branch_id: string | number | null | undefined,
        department_id: string | number | null | undefined,
        role_id: string | number | null | undefined,
    ) => {
        await fetchpermission(company_id, branch_id, department_id, role_id);
    };

    const getPerMenu1 = async (
        company_id: string | number | null | undefined,
        branch_id: string | number | null | undefined,
        department_id: string | number | null | undefined,
        role_id: string | number | null | undefined,
    ) => {
        await fetchPerMenu();
        await fetchpermission(company_id, branch_id, department_id, role_id);
    };

    const fetchRole = async () => {
        loading.value = true;
        try{
            const response = await fetchWithRetry(axios.get, '/api/fetchroles');

            // Assign values to state
            rolesdata.value = response.data;

            loading.value = false;
            return;
        }catch (error) {
            if(error.response?.data?.message !== 'Unauthenticated.'){
                Notify(error.response?.data?.message || 'An error occurred', 'alert');
            }
        }finally {
            loading.value = false;
        }
    };

    const fetchCountry = async () => {
        await runDeduped('api/fetchcountries', async () => {
            loading.value = true;
            try {
                const response = await fetchWithRetry(axios.post, '/api/fetchcountries');
                countriesdata.value = response.data;
            } catch (error) {
                if (error.response?.data?.message !== 'Unauthenticated.') {
                    Notify(error.response?.data?.message || 'An error occurred', 'alert');
                }
            } finally {
                loading.value = false;
            }
        });
    };

    const fetchState = async (country_id) => {
        if (country_id === null || country_id === undefined || country_id === '') {
            statesdata.value = [];

            return;
        }

        const key = `api/fetchstates:${country_id}`;

        await runDeduped(key, async () => {
            loading.value = true;

            try {
                const response = await fetchWithRetry(axios.post, '/api/fetchstates', { country_id });
                statesdata.value = response.data;
            } catch (error) {
                if (error.response?.data?.message !== 'Unauthenticated.') {
                    Notify(error.response?.data?.message || 'An error occurred', 'alert');
                }
            } finally {
                loading.value = false;
            }
        });
    };

    const fetchCity = async (country_id: string | number | null | undefined, state_id: string | number | null | undefined) => {
        if (state_id === null || state_id === undefined || state_id === '') {
            citiesdata.value = [];

            return;
        }

        const key = `api/fetchcities:${country_id}:${state_id}`;

        await runDeduped(key, async () => {
            loading.value = true;

            try {
                const response = await fetchWithRetry(axios.post, '/api/fetchcities', {
                    country_id,
                    state_id,
                });

                citiesdata.value = response.data;
            } catch (error) {
                if (error.response?.data?.message !== 'Unauthenticated.') {
                    Notify(error.response?.data?.message || 'An error occurred', 'alert');
                }
            } finally {
                loading.value = false;
            }
        });
    };

    const fetchCompany = async () => {
        await runDeduped('api/fetchcompanies', async () => {
            loading.value = true;
            try {
                const response = await fetchWithRetry(window.axios.get, '/api/fetchcompanies');
                companiesdata.value = response.data;
            } catch (error) {
                if (error.response?.data?.message !== 'Unauthenticated.') {
                    Notify(error.response?.data?.message || 'An error occurred', 'alert');
                }
            } finally {
                loading.value = false;
            }
        });
    };

    const fetchBranch = async (company_id: string | number | null | undefined) => {
        if (company_id === null || company_id === undefined || company_id === '') {
            branchesdata.value = [];
            return;
        }

        loading.value = true;
        try {
            const response = await fetchWithRetry(window.axios.get, '/api/fetchbranches', {
                params: { company_id },
            });
            branchesdata.value = response.data;
        } catch (error) {
            if (error.response?.data?.message !== 'Unauthenticated.') {
                Notify(error.response?.data?.message || 'An error occurred', 'alert');
            }
        } finally {
            loading.value = false;
        }
    };

    const fetchDepartment = async (
        company_id: string | number | null | undefined,
        branch_id: string | number | null | undefined,
    ) => {
        if (
            company_id === null || company_id === undefined || company_id === '' ||
            branch_id === null || branch_id === undefined || branch_id === ''
        ) {
            departmentsdata.value = [];
            return;
        }

        loading.value = true;
        try {
            const response = await fetchWithRetry(window.axios.get, '/api/fetchdepartments', {
                params: { company_id, branch_id },
            });
            departmentsdata.value = response.data;
        } catch (error) {
            if (error.response?.data?.message !== 'Unauthenticated.') {
                Notify(error.response?.data?.message || 'An error occurred', 'alert');
            }
        } finally {
            loading.value = false;
        }
    };

    const getBranch = async (company_id: string | number | null | undefined) => {
        await fetchBranch(company_id);
    };

    const getDepartment = async (
        company_id: string | number | null | undefined,
        branch_id: string | number | null | undefined,
    ) => {
        await fetchDepartment(company_id, branch_id);
    };

    const fetchCustomerGroup = async (
        company_id: string | number | null | undefined,
        branch_id: string | number | null | undefined,
    ) => {
        if (
            company_id === null || company_id === undefined || company_id === '' ||
            branch_id === null || branch_id === undefined || branch_id === ''
        ) {
            customergroupsdata.value = [];
            return;
        }

        loading.value = true;
        try {
            const response = await fetchWithRetry(window.axios.get, '/api/fetchcustomergroups', {
                params: { company_id, branch_id },
            });
            customergroupsdata.value = response.data;
        } catch (error) {
            if (error.response?.data?.message !== 'Unauthenticated.') {
                Notify(error.response?.data?.message || 'An error occurred', 'alert');
            }
        } finally {
            loading.value = false;
        }
    };

    const getCustomerGroup = async (
        company_id: string | number | null | undefined,
        branch_id: string | number | null | undefined,
    ) => {
        await fetchCustomerGroup(company_id, branch_id);
    };

    const fetchUnit = async (
        company_id: string | number | null | undefined,
        except_id?: string | number | null | undefined,
    ) => {
        if (company_id === null || company_id === undefined || company_id === '') {
            unitsdata.value = [];
            return;
        }

        loading.value = true;
        try {
            const response = await fetchWithRetry(window.axios.get, '/api/fetchunits', {
                params: {
                    company_id,
                    ...(except_id ? { except_id } : {}),
                },
            });
            unitsdata.value = response.data;
        } catch (error) {
            if (error.response?.data?.message !== 'Unauthenticated.') {
                Notify(error.response?.data?.message || 'An error occurred', 'alert');
            }
        } finally {
            loading.value = false;
        }
    };

    const getUnit = async (
        company_id: string | number | null | undefined,
        except_id?: string | number | null | undefined,
    ) => {
        await fetchUnit(company_id, except_id);
    };

    const fetchCategory = async (
        company_id: string | number | null | undefined,
        except_id?: string | number | null | undefined,
    ) => {
        if (company_id === null || company_id === undefined || company_id === '') {
            categoriesdata.value = [];
            return;
        }

        loading.value = true;
        try {
            const response = await fetchWithRetry(window.axios.get, '/api/fetchcategories', {
                params: {
                    company_id,
                    ...(except_id ? { except_id } : {}),
                },
            });
            categoriesdata.value = response.data;
        } catch (error) {
            if (error.response?.data?.message !== 'Unauthenticated.') {
                Notify(error.response?.data?.message || 'An error occurred', 'alert');
            }
        } finally {
            loading.value = false;
        }
    };

    const getCategory = async (
        company_id: string | number | null | undefined,
        except_id?: string | number | null | undefined,
    ) => {
        await fetchCategory(company_id, except_id);
    };

    const fetchBrand = async (
        company_id: string | number | null | undefined,
    ) => {
        if (company_id === null || company_id === undefined || company_id === '') {
            brandsdata.value = [];
            return;
        }

        loading.value = true;
        try {
            const response = await fetchWithRetry(window.axios.get, '/api/fetchbrands', {
                params: {
                    company_id,
                },
            });
            brandsdata.value = response.data;
        } catch (error) {
            if (error.response?.data?.message !== 'Unauthenticated.') {
                Notify(error.response?.data?.message || 'An error occurred', 'alert');
            }
        } finally {
            loading.value = false;
        }
    };

    const getBrand = async (
        company_id: string | number | null | undefined,
    ) => {
        await fetchBrand(company_id);
    };

    const fetchItemType = async (
        company_id: string | number | null | undefined,
    ) => {
        if (company_id === null || company_id === undefined || company_id === '') {
            itemtypesdata.value = [];
            return;
        }

        loading.value = true;
        try {
            const response = await fetchWithRetry(window.axios.get, '/api/fetchitemtypes', {
                params: {
                    company_id,
                },
            });
            itemtypesdata.value = response.data;
        } catch (error) {
            if (error.response?.data?.message !== 'Unauthenticated.') {
                Notify(error.response?.data?.message || 'An error occurred', 'alert');
            }
        } finally {
            loading.value = false;
        }
    };

    const getItemType = async (
        company_id: string | number | null | undefined,
    ) => {
        await fetchItemType(company_id);
    };

    const fetchSubCategory = async (
        company_id: string | number | null | undefined,
        category_id: string | number | null | undefined,
    ) => {
        if (company_id === null || company_id === undefined || company_id === ''
            || category_id === null || category_id === undefined || category_id === '') {
            subcategoriesdata.value = [];
            return;
        }

        loading.value = true;
        try {
            const response = await fetchWithRetry(window.axios.get, '/api/fetchsubcategories', {
                params: {
                    company_id,
                    category_id,
                },
            });
            subcategoriesdata.value = response.data;
        } catch (error) {
            if (error.response?.data?.message !== 'Unauthenticated.') {
                Notify(error.response?.data?.message || 'An error occurred', 'alert');
            }
        } finally {
            loading.value = false;
        }
    };

    const getSubCategory = async (
        company_id: string | number | null | undefined,
        category_id: string | number | null | undefined,
    ) => {
        await fetchSubCategory(company_id, category_id);
    };

    const fetchWarranty = async (
        company_id: string | number | null | undefined,
    ) => {
        if (company_id === null || company_id === undefined || company_id === '') {
            warrantiesdata.value = [];
            return;
        }

        loading.value = true;
        try {
            const response = await fetchWithRetry(window.axios.get, '/api/fetchwarranties', {
                params: {
                    company_id,
                },
            });
            warrantiesdata.value = response.data;
        } catch (error) {
            if (error.response?.data?.message !== 'Unauthenticated.') {
                Notify(error.response?.data?.message || 'An error occurred', 'alert');
            }
        } finally {
            loading.value = false;
        }
    };

    const getWarranty = async (
        company_id: string | number | null | undefined,
    ) => {
        await fetchWarranty(company_id);
    };

    function changeCountry(id){
        fetchState(id);
    }

    function changeState(state_id){
        fetchCity(null, state_id);
    }

    const placeholder = '/assets/images/no_image_available.png'

    const imageError = (event) => {
        event.target.src = placeholder
    }

    return{
        select_data,
        formatedText,
        Notify,
        getSavedValue,
        fetchMenu,
        menusdata,
        handleSuccess,
        handleError,
        appUrl,
        fetchWithRetry,
        changeStateFn,
        changeOrderFn,
        deleteFn,
        checkAllFn,
        duplicateFn,
        getData,
        restoreFn,
        getPerMenu1,
        getPermission,
        loading,
        permissiondata,
        fetchpermission,
        fetchPerMenu,
        defaultSearch,
        fetchRole,
        rolesdata,
        fetchCountry,
        countriesdata,
        fetchState,
        statesdata,
        fetchCity,
        citiesdata,
        fetchCompany,
        companiesdata,
        fetchBranch,
        branchesdata,
        fetchDepartment,
        departmentsdata,
        fetchCustomerGroup,
        customergroupsdata,
        fetchUnit,
        unitsdata,
        fetchCategory,
        categoriesdata,
        fetchBrand,
        brandsdata,
        fetchItemType,
        itemtypesdata,
        fetchSubCategory,
        subcategoriesdata,
        fetchWarranty,
        warrantiesdata,
        getBranch,
        getDepartment,
        getCustomerGroup,
        getUnit,
        getCategory,
        getBrand,
        getItemType,
        getSubCategory,
        getWarranty,
        changeCountry,
        changeState,
        imageError,
        relayoutTheGrid,
        formatNumber,
    }

}
