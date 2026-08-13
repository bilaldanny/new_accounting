import { reactive, ref, nextTick, inject, getCurrentInstance } from "vue";
import { toast } from 'vue-sonner';
import { resolvePublicAppBaseUrl } from '@/utils/publicAppUrl';
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

type IsotopeInstance = {
    layout: () => void;
};

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

    /* Notify */
    const Notify = async (message: string, type = 'success') => {
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
		if(type === 'success'){
			var x = document.getElementById("success-audio")
			x.play();
		}
		if(type === 'error'){
			var x = document.getElementById("error-audio")
			x.play();
		}
		if(type === 'warning'){
			var x = document.getElementById("warning-audio")
			x.play();
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
            .replace(/[._]/g, ' ') // replace both . and _ with space
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

    /* Form Error */
    const handleError = (error, details, form$) => {
        form$.messageBag.clear() // clear message bag
        switch (details.type) {
            // Error occured while preparing elements (no submit happened)
            case 'prepare':

            form$.messageBag.append('Could not prepare form')
            break

            // Error occured because response status is outside of 2xx
            case 'submit':
                if(error.response.status === 419){
                    window.location.href = 'login';
                }

                if(error.response.data.errors){
                    Object.entries(error.response.data.errors).forEach(([key, messages]) => {
                        form$.messageBag.append(messages[0]);
                    });
                }else{
                    form$.messageBag.append(error.response.data.message)
                }
            break

            // Request cancelled (no response object)
            case 'cancel':

            form$.messageBag.append('Request cancelled')
            break

            // Some other errors happened (no response object)
            case 'other':

            form$.messageBag.append('Couldn\'t submit form')
            break
        }
    }

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
        loading.value = true;
        try{
            const response = await fetchWithRetry(axios.post, '/api/fetchstates', {'country_id': country_id});

            // Assign values to state
            statesdata.value = response.data;

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

    const fetchCity = async (state_id) => {
        loading.value = true;
        try{
            const response = await fetchWithRetry(axios.post, '/api/fetchcities', {'state_id': state_id});

            // Assign values to state
            citiesdata.value = response.data;

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

    function changeCountry(id){
        fetchState(id);
    }

    function changeState(state_id){
        fetchCity(state_id);
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
        getBranch,
        getDepartment,
        changeCountry,
        changeState,
        imageError,
        relayoutTheGrid
    }

}
