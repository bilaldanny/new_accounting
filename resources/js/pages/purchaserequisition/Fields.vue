<script setup lang="ts">
    import { usePage } from '@inertiajs/vue3';
    import { ClipboardList, FileText } from '@lucide/vue';
    import { computed, onMounted, ref } from 'vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';
    import type { RequisitionLineRow } from '@/composables/purchaseRequisition';
    import FieldHint from './FieldHint.vue';
    import LinesEditor from './LinesEditor.vue';

    const params = defineProps({
        type: String,
        recordId: {
            type: Number,
            default: null,
        },
        formData: {
            type: Object,
            default: () => ({}),
        },
        formRef: {
            type: Object,
            default: null,
        },
    });

    const page = usePage();
    const colQuarter = { container: 3, label: 12, wrapper: 12 };
    const colHalf = { container: 6, label: 12, wrapper: 12 };
    const colFull = { container: 12, label: 12, wrapper: 12 };
    const cardClasses = {
        ElementLayout: {
            container: 'product-form-card',
        },
        GroupElement: {
            wrapper: 'product-form-card__body',
        },
    };

    const authUser = computed(() => page.props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
        branch_id?: number | string | null;
    } | null);

    const normalizeRoleName = (name: unknown): string =>
        String(name ?? '').toLowerCase().replace(/\s+/g, '');

    const roleName = computed(() => normalizeRoleName(authUser.value?.rolename));
    const isSuperadmin = computed(() => roleName.value === 'superadmin');
    const isCompanyadmin = computed(() => roleName.value === 'companyadmin');
    const showCompanyField = computed(() => isSuperadmin.value);
    const canManageBranch = computed(() => isSuperadmin.value || isCompanyadmin.value);
    const showBranchField = computed(() => canManageBranch.value && branchesdata.value.length > 1);
    const showHiddenCompanyField = computed(() => ! isSuperadmin.value);
    const showHiddenBranchField = computed(() => ! showBranchField.value);
    const isEditable = computed(() => params.formData?.is_editable !== false);

    const { fetchCompany, fetchBranch, companiesdata, branchesdata } = useCommons();

    const suppliersdata = ref<Array<{ id: number | string; text?: string }>>([]);
    const lastFetchedCompanyId = ref('');
    const lastSupplierScope = ref('');

    const normalizeId = (id: unknown) =>
        id === null || id === undefined || id === '' ? '' : String(id);

    const selectedCompanyId = computed(() => params.formData?.company_id ?? '');
    const selectedBranchId = computed(() => params.formData?.branch_id ?? '');
    const branchDisabled = computed(() => isSuperadmin.value && ! selectedCompanyId.value);
    const companyRules = computed(() => (isSuperadmin.value ? 'required' : ''));
    const lines = computed<RequisitionLineRow[]>(() => (
        Array.isArray(params.formData?.lines) ? params.formData.lines : []
    ));
    const lineCountLabel = computed(() => {
        const count = lines.value.length;

        return count === 1 ? '1 line' : `${count} lines`;
    });

    function persist(patch: Record<string, unknown>) {
        if (params.formData) {
            Object.assign(params.formData, patch);
        }

        params.formRef?.update?.(patch);
    }

    function persistLines(next: RequisitionLineRow[]) {
        persist({ lines: next });
    }

    function addLine(line: RequisitionLineRow) {
        persistLines([...lines.value, line]);
    }

    function updateLine(index: number, line: RequisitionLineRow) {
        const next = [...lines.value];
        next[index] = line;
        persistLines(next);
    }

    function removeLine(index: number) {
        persistLines(lines.value.filter((_, lineIndex) => lineIndex !== index));
    }

    async function searchProducts(term: string) {
        try {
            const response = await window.axios.get(API_ENDPOINTS.purchaseSearchProducts, {
                params: { company_id: selectedCompanyId.value, branch_id: selectedBranchId.value, search: term },
            });

            return response.data ?? [];
        } catch {
            return [];
        }
    }

    function applyScopedDefaults() {
        if (isSuperadmin.value) {
            return;
        }

        const updates: Record<string, string | number> = {};

        if (authUser.value?.company_id) {
            updates.company_id = authUser.value.company_id;
        }

        if (! isCompanyadmin.value && authUser.value?.branch_id) {
            updates.branch_id = authUser.value.branch_id;
        }

        if (Object.keys(updates).length > 0) {
            persist(updates);
        }
    }

    async function loadBranchOptions(companyId: string | number | null | undefined) {
        if (! canManageBranch.value) {
            return;
        }

        const normalizedCompanyId = normalizeId(companyId);

        if (! normalizedCompanyId) {
            branchesdata.value = [];

            return;
        }

        if (normalizedCompanyId === lastFetchedCompanyId.value) {
            return;
        }

        lastFetchedCompanyId.value = normalizedCompanyId;
        await fetchBranch(normalizedCompanyId);

        if (branchesdata.value.length === 1) {
            persist({ branch_id: branchesdata.value[0].id });
        }
    }

    async function loadSuppliers(companyId: string | number | null | undefined) {
        const normalizedCompanyId = normalizeId(companyId);

        if (! normalizedCompanyId) {
            suppliersdata.value = [];
            lastSupplierScope.value = '';

            return;
        }

        if (normalizedCompanyId === lastSupplierScope.value) {
            return;
        }

        lastSupplierScope.value = normalizedCompanyId;

        try {
            const response = await window.axios.get(API_ENDPOINTS.fetchSuppliers, { params: { company_id: normalizedCompanyId } });
            suppliersdata.value = (response.data ?? []).map((supplier: { id: number; text?: string | null; business_name?: string }) => ({
                id: supplier.id,
                text: supplier.text || supplier.business_name || `#${supplier.id}`,
            }));
        } catch {
            suppliersdata.value = [];
        }
    }

    async function handleCompanyChange(companyId: string | number | null | undefined) {
        if (! isSuperadmin.value) {
            return;
        }

        persist({ branch_id: '' });
        lastFetchedCompanyId.value = '';
        await loadBranchOptions(companyId);
        await loadSuppliers(companyId);
    }

    onMounted(async () => {
        applyScopedDefaults();

        if (showCompanyField.value) {
            await fetchCompany();
        }

        const companyId = isCompanyadmin.value ? authUser.value?.company_id : selectedCompanyId.value;

        if (companyId) {
            await loadBranchOptions(companyId);
            await loadSuppliers(companyId);
        }
    });
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="params.type === 'edit'" hidden="true" />
    <TextElement v-if="showHiddenCompanyField" name="company_id" hidden="true" />
    <TextElement v-if="showHiddenBranchField" name="branch_id" hidden="true" />
    <TextElement name="lines" hidden="true" />

    <GroupElement name="group_header" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_header" :columns="colFull">
            <div class="product-form-section-head journal-card-head">
                <div class="journal-card-head__lead">
                    <span class="product-form-section-icon">
                        <ClipboardList class="h-4 w-4" />
                    </span>
                    <div>
                        <span class="journal-card-eyebrow">Requisition</span>
                        <h2 class="product-form-section-title">What do you need, and how much?</h2>
                    </div>
                </div>
                <span v-if="!isEditable" class="journal-card-meta">Approved requisitions can no longer be edited here</span>
            </div>
        </StaticElement>

        <SelectElement
            v-if="showCompanyField"
            name="company_id"
            :native="false"
            :items="companiesdata"
            id="CompanyId"
            field-name="CompanyId"
            placeholder="Select company"
            :columns="colQuarter"
            label-prop="text"
            value-prop="id"
            :search="true"
            :floating="false"
            :can-clear="false"
            :rules="companyRules"
            @change="handleCompanyChange"
        >
            <template #label>
                <FieldHint label="Company" required>Select the company this requisition belongs to.</FieldHint>
            </template>
        </SelectElement>

        <SelectElement
            v-if="showBranchField"
            name="branch_id"
            :native="false"
            :items="branchesdata"
            id="BranchId"
            field-name="BranchId"
            placeholder="Select branch"
            :columns="colQuarter"
            label-prop="text"
            value-prop="id"
            :search="true"
            :floating="false"
            :can-clear="false"
            :disabled="branchDisabled"
            rules="required"
        >
            <template #label>
                <FieldHint label="Branch" required>Required. Which branch is asking for this.</FieldHint>
            </template>
        </SelectElement>

        <SelectElement
            name="contact_id"
            :native="false"
            :items="suppliersdata"
            id="ContactId"
            field-name="ContactId"
            placeholder="Preferred supplier (optional)"
            :columns="colQuarter"
            label-prop="text"
            value-prop="id"
            :search="true"
            :floating="false"
        >
            <template #label>
                <FieldHint label="Preferred supplier">Optional. The actual supplier is chosen when this becomes a Purchase Order.</FieldHint>
            </template>
        </SelectElement>

        <DateElement
            id="RequisitionDate"
            field-name="RequisitionDate"
            name="requisition_date"
            placeholder="Select date"
            :columns="colQuarter"
            :floating="false"
            rules="required"
            value-format="YYYY-MM-DD"
        >
            <template #label>
                <FieldHint label="Requisition date" required></FieldHint>
            </template>
        </DateElement>
    </GroupElement>

    <GroupElement name="group_lines" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_lines" :columns="colFull">
            <div class="product-form-section-head journal-card-head">
                <div class="journal-card-head__lead">
                    <span class="product-form-section-icon">
                        <FileText class="h-4 w-4" />
                    </span>
                    <div>
                        <span class="journal-card-eyebrow">Products</span>
                        <div class="journal-card-title-row">
                            <h2 class="product-form-section-title">Requested lines</h2>
                            <span class="journal-panel__count">{{ lineCountLabel }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </StaticElement>

        <StaticElement name="lines_editor" :columns="colFull">
            <LinesEditor
                :lines="lines"
                :disabled="!isEditable"
                :search-products="searchProducts"
                @add="addLine"
                @update="updateLine"
                @remove="removeLine"
            />
        </StaticElement>
    </GroupElement>

    <GroupElement name="group_notes" :columns="colFull" :add-classes="cardClasses">
        <TextareaElement
            name="note"
            id="Note"
            field-name="Note"
            placeholder="Why this is needed…"
            :columns="colHalf"
            :rows="4"
            :disabled="!isEditable"
        >
            <template #label>
                <FieldHint label="Note">Optional. Shown to whoever approves this requisition.</FieldHint>
            </template>
        </TextareaElement>
    </GroupElement>
</template>
