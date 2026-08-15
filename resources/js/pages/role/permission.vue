<script setup>
    import useRoles from '@/composables/role';
    import useCommons from '@/composables/common';
    import PermissionCard from '@/components/role/permission/PermissionCard.vue';
    import PermissionHeader from '@/components/role/permission/PermissionHeader.vue';
    import PermissionSkeletonLoader from '@/components/role/permission/PermissionSkeletonLoader.vue';
    import PermissionScopeFields from '@/pages/role/permission/ScopeFields.vue';
    import { role } from '@/routes';
    import { Head, usePage } from '@inertiajs/vue3';
    import { ShieldQuarter } from '@boxicons/vue';
    import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';

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

    const normalizeScopeId = (id) =>
        id === null || id === undefined || id === '' ? '' : String(id);

    const scopeFormData = reactive({
        company_id: '',
        branch_id: '',
        department_id: '',
    });

    const branchFilterDisabled = computed(
        () => showCompanyFilter.value && !normalizeScopeId(scopeFormData.company_id),
    );
    const departmentFilterDisabled = computed(
        () => (showCompanyFilter.value || showBranchFilter.value) &&
            (!normalizeScopeId(scopeFormData.company_id) || !normalizeScopeId(scopeFormData.branch_id)),
    );
    const hasPermissionScope = computed(() => {
        if (normalizeScopeId(scopeFormData.department_id)) {
            return true;
        }

        if (normalizeScopeId(scopeFormData.branch_id)) {
            return true;
        }

        if (isSuperadmin.value) {
            return true;
        }

        return false;
    });
    const showPermissionGrid = computed(() => {
        if (loading.value) {
            return false;
        }

        return hasPermissionScope.value;
    });
    const emptyStateCopy = computed(() => {
        if (showBranchFilter.value) {
            return {
                title: 'Select a branch to begin',
                text: 'Choose a branch to manage branch-level permissions, or select a department for department-specific access.',
            };
        }

        return {
            title: 'Select a department to begin',
            text: 'Choose a department from the filters above to load and manage permissions.',
        };
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

    const selectedRole = ref(null);
    const selectedRoleName = computed(() => selectedRole.value?.name ?? '');
    const scopeForm$ = ref(null);
    const scopeReady = ref(false);
    let skipScopeSync = false;

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

    const authCompanyName = computed(() => String(authUser.value?.company_name ?? '').trim());

    const resolveCompanyLabel = (companyId) => {
        const normalizedCompanyId = normalizeScopeId(companyId);

        if (!normalizedCompanyId) {
            return '';
        }

        const company = companiesdata.value.find(
            (item) => String(item.id) === normalizedCompanyId,
        );

        return company?.text ?? company?.name ?? authCompanyName.value ?? 'Company';
    };

    const scopeSummary = computed(() => {
        const parts = [];

        if (scopeFormData.company_id) {
            parts.push(resolveCompanyLabel(scopeFormData.company_id));
        }

        if (scopeFormData.branch_id) {
            const branch = branchesdata.value.find(
                (item) => String(item.id) === normalizeScopeId(scopeFormData.branch_id),
            );
            parts.push(branch?.text ?? branch?.name ?? 'Branch');
        }

        if (scopeFormData.department_id) {
            const department = departmentsdata.value.find(
                (item) => String(item.id) === normalizeScopeId(scopeFormData.department_id),
            );
            parts.push(department?.text ?? department?.name ?? 'Department');
        }

        if (parts.length === 0) {
            if (isSuperadmin.value) {
                return 'All organizations';
            }

            return authCompanyName.value || 'Current organization';
        }

        return parts.join(' · ');
    });

    async function loadSelectedRole() {
        try {
            const response = await window.axios.get(`/api/roles/${routeProps.id}`);
            selectedRole.value = response.data;
        } catch (error) {
            if (window.axios.isAxiosError(error) && error.response?.data?.message !== 'Unauthenticated.') {
                Notify(error.response?.data?.message || 'Unable to load role details', 'alert');
            }
        }
    }

    function syncScopeToPermission() {
        permission.value.company_id = scopeFormData.company_id ?? '';
        permission.value.branch_id = scopeFormData.branch_id ?? '';
        permission.value.department_id = scopeFormData.department_id ?? '';
    }

    async function reloadPermissions() {
        syncScopeToPermission();
        await getPerMenu1(
            permission.value.company_id,
            permission.value.branch_id,
            permission.value.department_id,
            permission.value.role_id,
        );
    }

    async function handleCompanyChange(companyId) {
        scopeFormData.branch_id = '';
        scopeFormData.department_id = '';
        departmentsdata.value = [];

        scopeForm$.value?.update({
            branch_id: '',
            department_id: '',
        });

        if (companyId) {
            await getBranch(companyId);
        } else {
            branchesdata.value = [];
        }

        if (isSuperadmin.value) {
            await reloadPermissions();
            return;
        }

        menusdata.value = [];
        permissiondata.value = [];
    }

    async function handleBranchChange(companyId, branchId) {
        scopeFormData.department_id = '';
        scopeForm$.value?.update({ department_id: '' });

        if (companyId && branchId) {
            await getDepartment(companyId, branchId);
        } else {
            departmentsdata.value = [];
        }

        if (branchId || isSuperadmin.value) {
            await reloadPermissions();
            return;
        }

        menusdata.value = [];
        permissiondata.value = [];
    }

    async function handleDepartmentChange() {
        if (!hasPermissionScope.value) {
            menusdata.value = [];
            permissiondata.value = [];
            return;
        }

        await reloadPermissions();
    }

    function handleScopeFormUpdate(value) {
        if (skipScopeSync || !value || typeof value !== 'object') {
            return;
        }

        Object.assign(scopeFormData, value);
        syncScopeToPermission();
    }

    watch(
        () => normalizeScopeId(scopeFormData.company_id),
        async (companyId, previousCompanyId) => {
            if (!scopeReady.value || companyId === previousCompanyId) {
                return;
            }

            await handleCompanyChange(companyId);
        },
    );

    watch(
        () => normalizeScopeId(scopeFormData.branch_id),
        async (branchId, previousBranchId) => {
            if (!scopeReady.value || branchId === previousBranchId) {
                return;
            }

            await handleBranchChange(scopeFormData.company_id, branchId);
        },
    );

    watch(
        () => normalizeScopeId(scopeFormData.department_id),
        async (departmentId, previousDepartmentId) => {
            if (!scopeReady.value || departmentId === previousDepartmentId) {
                return;
            }

            await handleDepartmentChange();
        },
    );

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
        skipScopeSync = true;
        permission.value.role_id = routeProps.id;

        await loadSelectedRole();

        if (isSuperadmin.value) {
            scopeFormData.company_id = '';
            scopeFormData.branch_id = '';
            scopeFormData.department_id = '';
            syncScopeToPermission();
            await fetchCompany();
            await reloadPermissions();
            await nextTick();
            scopeForm$.value?.update({ ...scopeFormData });
            skipScopeSync = false;
            scopeReady.value = true;
            return;
        }

        scopeFormData.company_id = authUser.value?.company_id ?? '';
        scopeFormData.branch_id = authUser.value?.branch_id ?? '';
        scopeFormData.department_id = '';
        syncScopeToPermission();

        if (isCompanyadmin.value && scopeFormData.company_id) {
            await fetchBranch(scopeFormData.company_id);
        }

        if (scopeFormData.company_id && scopeFormData.branch_id) {
            await fetchDepartment(scopeFormData.company_id, scopeFormData.branch_id);
        }

        if (scopeFormData.branch_id || scopeFormData.department_id) {
            await reloadPermissions();
        }

        await nextTick();
        scopeForm$.value?.update({ ...scopeFormData });
        skipScopeSync = false;
        scopeReady.value = true;
    });
</script>

<template>
    <Head title="Role Permission" />

    <div class="permission-page">
        <section v-if="showFilters" class="permission-page__toolbar card custom-card">
            <div class="card-body permission-page__toolbar-body">
                <div class="permission-page__toolbar-head">
                    <div class="permission-page__toolbar-intro">
                        <div class="permission-page__toolbar-icon" aria-hidden="true">
                            <ShieldQuarter size="md" class="permission-page__toolbar-icon-svg" />
                        </div>
                        <div>
                            <h2 class="permission-page__toolbar-title">Permission scope</h2>
                            <p class="permission-page__toolbar-subtitle">
                                Configure access for this role
                                <span
                                    v-if="selectedRoleName"
                                    class="permission-page__role-badge"
                                >
                                    {{ selectedRoleName }}
                                </span>
                                <span class="permission-page__scope-badge">{{ scopeSummary }}</span>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="permission-page__scope-form">
                    <Vueform
                        :model-value="scopeFormData"
                        sync
                        :endpoint="false"
                        size="sm"
                        :display-errors="false"
                        ref="scopeForm$"
                        @update:model-value="handleScopeFormUpdate"
                    >
                        <PermissionScopeFields
                            :show-company-filter="showCompanyFilter"
                            :show-branch-filter="showBranchFilter"
                            :show-department-filter="showDepartmentFilter"
                            :companiesdata="companiesdata"
                            :branchesdata="branchesdata"
                            :departmentsdata="departmentsdata"
                            :branch-filter-disabled="branchFilterDisabled"
                            :department-filter-disabled="departmentFilterDisabled"
                        />
                    </Vueform>
                </div>

                <PermissionHeader
                    v-if="showPermissionGrid"
                    v-model:search="searchQuery"
                    :total-modules="totalModules"
                    :granted-count="grantedCount"
                    :total-permissions="totalPermissions"
                    :coverage-percent="coveragePercent"
                />
            </div>
        </section>

        <div v-if="!showPermissionGrid && !loading" class="permission-page__empty card custom-card">
            <div class="card-body permission-page__empty-body">
                <div class="permission-page__empty-icon-wrap">
                    <i class="mdi mdi-shield-search permission-page__empty-icon" aria-hidden="true" />
                </div>
                <p class="permission-page__empty-title mb-1">{{ emptyStateCopy.title }}</p>
                <p class="permission-page__empty-text text-muted mb-0">
                    {{ emptyStateCopy.text }}
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

.permission-page__scope-form {
    margin-bottom: 0.25rem;
}

.permission-page__scope-form :deep(.form-gap) {
    row-gap: 1rem;
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

.permission-page__toolbar-icon-svg {
    display: block;
    fill: currentColor;
}

.permission-page__role-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.2rem 0.625rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
    color: #1d4ed8;
    background: rgba(59, 130, 246, 0.1);
    border: 1px solid rgba(59, 130, 246, 0.18);
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
