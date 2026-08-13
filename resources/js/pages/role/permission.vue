<script setup>
    import useRoles from '@/composables/role';
    import useCommons from '@/composables/common';
    import PermissionCard from '@/components/role/permission/PermissionCard.vue';
    import PermissionHeader from '@/components/role/permission/PermissionHeader.vue';
    import PermissionSkeletonLoader from '@/components/role/permission/PermissionSkeletonLoader.vue';
    import { role } from '@/routes';
    import { Head, usePage } from '@inertiajs/vue3';
    import { computed, onMounted, ref } from 'vue';

    defineOptions({
        layout: {
            title: 'Role Permission',
            subtitle: 'Manage role access permissions',
            breadcrumbs: [
                {
                    title: 'Role Management',
                    href: role().url,
                },
                {
                    title: 'Permission',
                    href: 'NULL',
                },
            ],
        },
    });

    const routeProps = defineProps({
        id: {
            required: true,
            type: String,
        },
    });

    const page = usePage();

    const normalizeRoleName = (name) =>
        String(name ?? '').toLowerCase().replace(/\s+/g, '');

    const authUser = computed(() => page.props.auth?.user ?? null);
    const roleName = computed(() => normalizeRoleName(authUser.value?.rolename));
    const isSuperadmin = computed(() => roleName.value === 'superadmin');
    const isCompanyadmin = computed(() => roleName.value === 'companyadmin');
    const showCompanyFilter = computed(() => isSuperadmin.value);
    const showBranchFilter = computed(() => isSuperadmin.value || isCompanyadmin.value);
    const showDepartmentFilter = computed(() => true);
    const showFilters = computed(() => showCompanyFilter.value || showBranchFilter.value || showDepartmentFilter.value);
    const branchFilterDisabled = computed(
        () => showCompanyFilter.value && !permission.value.company_id,
    );
    const departmentFilterDisabled = computed(
        () => (showCompanyFilter.value || showBranchFilter.value) &&
            (!permission.value.company_id || !permission.value.branch_id),
    );
    const showPermissionGrid = computed(() => {
        if (loading.value) {
            return false;
        }

        if (isSuperadmin.value) {
            return true;
        }

        return permission.value.department_id !== '';
    });

    const {
        Notify,
        menusdata,
        loading,
        getPermission,
        permissiondata,
        getPerMenu1,
        fetchCompany,
        fetchBranch,
        fetchDepartment,
        getBranch,
        getDepartment,
        companiesdata,
        branchesdata,
        departmentsdata,
    } = useCommons();
    const { permission } = useRoles();

    const searchQuery = ref('');

    const collectPermissionIds = (menuItem) => {
        const ids = [];
        for (const child of menuItem.children ?? []) {
            ids.push(child.id);
            for (const grandchild of child.children ?? []) {
                ids.push(grandchild.id);
            }
        }
        return ids;
    };

    const rootModules = computed(() =>
        menusdata.value.filter(
            (item) => item.parent_id === '' && item.is_permission === false,
        ),
    );

    const itemMatchesQuery = (item, query) => {
        const q = query.trim().toLowerCase();
        if (!q) {
            return true;
        }
        if (String(item.name).toLowerCase().includes(q)) {
            return true;
        }
        for (const child of item.children ?? []) {
            if (String(child.name).toLowerCase().includes(q)) {
                return true;
            }
            for (const grandchild of child.children ?? []) {
                if (String(grandchild.name).toLowerCase().includes(q)) {
                    return true;
                }
            }
        }
        return false;
    };

    const moduleMatchesSearch = (item) => itemMatchesQuery(item, searchQuery.value);

    const visibleMenus = computed(() =>
        rootModules.value.filter((item) => moduleMatchesSearch(item)),
    );

    const totalModules = computed(() => rootModules.value.length);

    const totalPermissions = computed(() =>
        rootModules.value.reduce(
            (count, item) => count + collectPermissionIds(item).length,
            0,
        ),
    );

    const grantedCount = computed(() => {
        const allIds = rootModules.value.flatMap((item) => collectPermissionIds(item));

        return allIds.filter((id) => permissiondata.value.includes(id)).length;
    });

    const coveragePercent = computed(() => {
        if (totalPermissions.value === 0) {
            return 0;
        }
        return Math.round((grantedCount.value / totalPermissions.value) * 100);
    });

    const scopeSummary = computed(() => {
        const parts = [];

        if (permission.value.company_id) {
            const company = companiesdata.value.find(
                (item) => String(item.id) === String(permission.value.company_id),
            );
            parts.push(company?.text ?? company?.name ?? 'Company');
        }

        if (permission.value.branch_id) {
            const branch = branchesdata.value.find(
                (item) => String(item.id) === String(permission.value.branch_id),
            );
            parts.push(branch?.text ?? branch?.name ?? 'Branch');
        }

        if (permission.value.department_id) {
            const department = departmentsdata.value.find(
                (item) => String(item.id) === String(permission.value.department_id),
            );
            parts.push(department?.text ?? department?.name ?? 'Department');
        }

        if (parts.length === 0) {
            return isSuperadmin.value ? 'All organizations' : 'Current organization';
        }

        return parts.join(' · ');
    });

    async function reloadPermissions() {
        await getPerMenu1(
            permission.value.company_id,
            permission.value.branch_id,
            permission.value.department_id,
            permission.value.role_id,
        );
    }

    async function handleCompanyChange(companyId) {
        permission.value.branch_id = '';
        permission.value.department_id = '';
        departmentsdata.value = [];

        if (!isSuperadmin.value) {
            menusdata.value = [];
            permissiondata.value = [];
        }

        if (companyId) {
            await getBranch(companyId);
        } else {
            branchesdata.value = [];
        }

        if (isSuperadmin.value) {
            await reloadPermissions();
        }
    }

    async function handleBranchChange(companyId, branchId) {
        permission.value.department_id = '';

        if (!isSuperadmin.value) {
            menusdata.value = [];
            permissiondata.value = [];
        }

        if (companyId && branchId) {
            await getDepartment(companyId, branchId);
        } else {
            departmentsdata.value = [];
        }

        if (isSuperadmin.value) {
            await reloadPermissions();
        }
    }

    async function handleDepartmentChange() {
        if (isSuperadmin.value) {
            await reloadPermissions();
            return;
        }

        if (permission.value.department_id !== '') {
            await reloadPermissions();
            return;
        }

        menusdata.value = [];
        permissiondata.value = [];
    }

    const checksubparent = async (id, event) => {
        const subparentElement = event.target.closest('.my-subparent-list');
        if (!subparentElement) return;

        const blockElement = subparentElement.closest('.permission-group__block');

        subparentElement.classList.add('pending');
        blockElement?.querySelectorAll('.my-subsubparent-list').forEach((element) => {
            element.classList.add('pending');
        });

        event.target.disabled = true;
        blockElement?.querySelectorAll('.form-check-input').forEach((input) => {
            input.disabled = true;
        });

        save(id, event, 'subparent');
    };

    const checksubsubparent = async (id, event) => {
        const subsubparentElement = event.target.closest('.my-subsubparent-list');
        if (!subsubparentElement) return;

        const parentElement = subsubparentElement.closest('.my-parent-list');

        subsubparentElement.classList.add('pending');
        event.target.disabled = true;

        parentElement?.querySelectorAll('.my-subsubparent-list .form-check-input').forEach((input) => {
            input.disabled = true;
        });

        save(id, event, 'subsubparent');
    };

    const MAX_RETRIES = 5;
    const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

    const clearPendingState = (event, type) => {
        setTimeout(() => {
            if (type === 'parent') {
                const parentElement = event.target.closest('.my-parent-list');
                if (!parentElement) return;

                parentElement.classList.remove('pending');
                parentElement.querySelectorAll('.my-subparent-list, .my-subsubparent-list').forEach((element) => {
                    element.classList.remove('pending');
                });
                parentElement.querySelectorAll('.form-check-input').forEach((input) => {
                    input.disabled = false;
                });
            }

            if (type === 'subparent') {
                const subparentElement = event.target.closest('.my-subparent-list');
                if (!subparentElement) return;

                const blockElement = subparentElement.closest('.permission-group__block');

                subparentElement.classList.remove('pending');
                blockElement?.querySelectorAll('.my-subsubparent-list').forEach((element) => {
                    element.classList.remove('pending');
                });
                blockElement?.querySelectorAll('.form-check-input').forEach((input) => {
                    input.disabled = false;
                });
            }

            if (type === 'subsubparent') {
                const subsubparentElement = event.target.closest('.my-subsubparent-list');
                if (!subsubparentElement) return;

                const parentElement = subsubparentElement.closest('.my-parent-list');

                subsubparentElement.classList.remove('pending');
                parentElement?.querySelectorAll('.my-subsubparent-list .form-check-input').forEach((input) => {
                    input.disabled = false;
                });
            }
        }, 1000);
    };

    const save = async (id, event, type) => {
        let attempts = 0;
        while (attempts < MAX_RETRIES) {
            try {
                permission.value.status = event.target.checked === true ? 1 : 0;
                permission.value.menuid = id;

                await window.axios.post('/api/permissions', permission.value);

                await getPermission(
                    permission.value.company_id,
                    permission.value.branch_id,
                    permission.value.department_id,
                    permission.value.role_id,
                );

                clearPendingState(event, type);
                return;
            } catch (error) {
                attempts++;
                if (attempts >= MAX_RETRIES) {
                    if (error.response?.data?.message !== 'Unauthenticated.') {
                        Notify(error.response?.data?.message || 'An error occurred', 'alert');
                    }
                    clearPendingState(event, type);
                    break;
                }
                await sleep(attempts * 1000);
            }
        }
    };

    onMounted(async () => {
        permission.value.role_id = routeProps.id;

        if (isSuperadmin.value) {
            permission.value.company_id = '';
            permission.value.branch_id = '';
            permission.value.department_id = '';
            await fetchCompany();
            await reloadPermissions();
            return;
        }

        permission.value.company_id = authUser.value?.company_id ?? '';
        permission.value.branch_id = authUser.value?.branch_id ?? '';
        permission.value.department_id = '';

        if (isCompanyadmin.value && permission.value.company_id) {
            await fetchBranch(permission.value.company_id);
        }

        if (permission.value.company_id && permission.value.branch_id) {
            await fetchDepartment(permission.value.company_id, permission.value.branch_id);
        }
    });
</script>

<template>
    <Head title="Role Permission" />

    <div class="permission-page">
        <section v-if="showFilters" class="permission-page__toolbar card custom-card">
            <div class="card-body permission-page__toolbar-body">
                <div class="permission-page__toolbar-head">
                    <div class="permission-page__toolbar-intro">
                        <div class="permission-page__toolbar-icon">
                            <i class="mdi mdi-shield-key-outline" aria-hidden="true" />
                        </div>
                        <div>
                            <h2 class="permission-page__toolbar-title">Permission scope</h2>
                            <p class="permission-page__toolbar-subtitle">
                                Configure access for this role
                                <span class="permission-page__scope-badge">{{ scopeSummary }}</span>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="permission-page__filters row g-3">
                    <div v-if="showCompanyFilter" class="col-md-4 col-lg-3">
                        <label class="permission-page__filter-label" for="permission-filter-company">
                            <i class="mdi mdi-domain" aria-hidden="true" />
                            Company
                        </label>
                        <select
                            id="permission-filter-company"
                            v-model="permission.company_id"
                            class="form-select permission-page__select"
                            @change="handleCompanyChange(permission.company_id)"
                        >
                            <option value="">All companies</option>
                            <option v-for="company in companiesdata" :key="company.id" :value="company.id">
                                {{ company.text ?? company.name }}
                            </option>
                        </select>
                    </div>

                    <div v-if="showBranchFilter" class="col-md-4 col-lg-3">
                        <label class="permission-page__filter-label" for="permission-filter-branch">
                            <i class="mdi mdi-source-branch" aria-hidden="true" />
                            Branch
                        </label>
                        <select
                            id="permission-filter-branch"
                            v-model="permission.branch_id"
                            class="form-select permission-page__select"
                            :disabled="branchFilterDisabled"
                            @change="handleBranchChange(permission.company_id, permission.branch_id)"
                        >
                            <option value="">All branches</option>
                            <option v-for="branch in branchesdata" :key="branch.id" :value="branch.id">
                                {{ branch.text ?? branch.name }}
                            </option>
                        </select>
                    </div>

                    <div v-if="showDepartmentFilter" class="col-md-4 col-lg-3">
                        <label class="permission-page__filter-label" for="permission-filter-department">
                            <i class="mdi mdi-sitemap-outline" aria-hidden="true" />
                            Department
                        </label>
                        <select
                            id="permission-filter-department"
                            v-model="permission.department_id"
                            class="form-select permission-page__select"
                            :disabled="departmentFilterDisabled"
                            @change="handleDepartmentChange"
                        >
                            <option value="">All departments</option>
                            <option v-for="department in departmentsdata" :key="department.id" :value="department.id">
                                {{ department.text ?? department.name }}
                            </option>
                        </select>
                    </div>
                </div>

                <PermissionHeader
                    v-if="showPermissionGrid || isSuperadmin"
                    v-model:search="searchQuery"
                    :total-modules="totalModules"
                    :granted-count="grantedCount"
                    :total-permissions="totalPermissions"
                    :coverage-percent="coveragePercent"
                />
            </div>
        </section>

        <div v-if="!showPermissionGrid && !loading && !isSuperadmin" class="permission-page__empty card custom-card">
            <div class="card-body permission-page__empty-body">
                <div class="permission-page__empty-icon-wrap">
                    <i class="mdi mdi-shield-search permission-page__empty-icon" aria-hidden="true" />
                </div>
                <p class="permission-page__empty-title mb-1">Select a department to begin</p>
                <p class="permission-page__empty-text text-muted mb-0">
                    Choose a department from the filters above to load and manage permissions.
                </p>
            </div>
        </div>

        <PermissionSkeletonLoader v-if="loading" />

        <template v-else-if="showPermissionGrid && menusdata.length !== 0">
            <div
                v-if="visibleMenus.length === 0"
                class="permission-page__empty card custom-card"
            >
                <div class="card-body permission-page__empty-body">
                    <div class="permission-page__empty-icon-wrap">
                        <i class="mdi mdi-file-search-outline permission-page__empty-icon" aria-hidden="true" />
                    </div>
                    <p class="permission-page__empty-title mb-1">No matching permissions</p>
                    <p class="permission-page__empty-text text-muted mb-3">
                        Nothing matched "{{ searchQuery }}". Try another keyword.
                    </p>
                    <button type="button" class="btn btn-sm btn-outline-primary" @click="searchQuery = ''">
                        Clear search
                    </button>
                </div>
            </div>

            <div v-else class="permission-page__grid">
                <div
                    v-for="item in visibleMenus"
                    :key="item.id"
                    class="permission-page__grid-item"
                >
                    <PermissionCard
                        :item="item"
                        :permissiondata="permissiondata"
                        :search-query="searchQuery"
                        @check-subparent="checksubparent"
                        @check-subsubparent="checksubsubparent"
                    />
                </div>
            </div>

            <p class="permission-page__footer-note">
                Showing {{ visibleMenus.length }} of {{ totalModules }} modules
                <span v-if="searchQuery"> matching "{{ searchQuery }}"</span>
            </p>
        </template>
    </div>
</template>

<style scoped>
.permission-page {
    padding: 0 0 2rem;
}

.permission-page__toolbar {
    margin-bottom: 1.5rem;
    border: 1px solid #e8ecf0;
    border-radius: 1rem;
    box-shadow: 0 4px 20px rgba(15, 23, 42, 0.05);
    overflow: hidden;
}

.permission-page__toolbar-body {
    padding: 1.25rem 1.5rem 1.5rem;
}

.permission-page__toolbar-head {
    margin-bottom: 1.25rem;
}

.permission-page__filters {
    margin-bottom: 0.25rem;
}

.permission-page__toolbar-intro {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
}

.permission-page__toolbar-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 2.75rem;
    height: 2.75rem;
    border-radius: 0.75rem;
    background: linear-gradient(135deg, rgba(25, 150, 131, 0.14) 0%, rgba(25, 150, 131, 0.06) 100%);
    color: var(--accent-dark, #199683);
    font-size: 1.375rem;
    flex-shrink: 0;
}

.permission-page__toolbar-title {
    font-size: 1.0625rem;
    font-weight: 700;
    color: var(--text-main, #111827);
    margin: 0 0 0.25rem;
    line-height: 1.3;
}

.permission-page__toolbar-subtitle {
    font-size: 0.875rem;
    color: var(--text-muted, #6b7280);
    margin: 0;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
}

.permission-page__scope-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.2rem 0.625rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--accent-dark, #199683);
    background: rgba(25, 150, 131, 0.1);
    border: 1px solid rgba(25, 150, 131, 0.18);
}

.permission-page__filter-label {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--text-muted, #6b7280);
    margin-bottom: 0.5rem;
}

.permission-page__filter-label .mdi {
    font-size: 0.875rem;
    color: var(--accent-dark, #199683);
}

.permission-page__select {
    min-height: 2.625rem;
    border-radius: 0.625rem;
    border-color: #e5e7eb;
    font-size: 0.875rem;
    background-color: #fff;
    box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.04);
}

.permission-page__select:focus {
    border-color: var(--accent-dark, #199683);
    box-shadow: 0 0 0 3px rgba(25, 150, 131, 0.12);
}

.permission-page__select:disabled {
    background-color: #f8fafc;
    opacity: 0.75;
}

.permission-page__grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1.25rem;
    align-items: start;
}

.permission-page__grid-item {
    min-width: 0;
}

@media (max-width: 1399.98px) {
    .permission-page__grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 767.98px) {
    .permission-page__toolbar-body {
        padding: 1rem;
    }

    .permission-page__grid {
        grid-template-columns: 1fr;
    }
}

.permission-page__empty {
    border-radius: 1rem;
    border: 1px dashed #d1d5db;
    background: #fafbfc;
}

.permission-page__empty-body {
    text-align: center;
    padding: 3rem 1.5rem;
}

.permission-page__empty-icon-wrap {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 4rem;
    height: 4rem;
    border-radius: 999px;
    background: rgba(25, 150, 131, 0.08);
    margin-bottom: 1rem;
}

.permission-page__empty-icon {
    font-size: 2rem;
    color: var(--accent-dark, #199683);
}

.permission-page__empty-title {
    font-weight: 600;
    font-size: 1.0625rem;
    color: var(--text-main, #111827);
}

.permission-page__empty-text {
    font-size: 0.875rem;
    max-width: 28rem;
    margin-inline: auto;
}

.permission-page__footer-note {
    margin: 1.25rem 0 0;
    text-align: center;
    font-size: 0.8125rem;
    color: var(--text-muted, #6b7280);
}
</style>
