<script setup lang="ts">
    import { usePage } from '@inertiajs/vue3';
    import { Banknote } from '@lucide/vue';
    import { computed, onMounted, ref } from 'vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    const params = defineProps({
        type: String,
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
    const colThird = { container: 4, label: 12, wrapper: 12 };
    const colFull = { container: 12, label: 12, wrapper: 12 };
    const cardClasses = {
        ElementLayout: {
            container: 'product-form-card',
        },
        GroupElement: {
            wrapper: 'product-form-card__body',
        },
    };

    const isEdit = computed(() => params.type === 'edit');

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
    const showCompanyField = computed(() => isSuperadmin.value && ! isEdit.value);
    const showHiddenCompanyField = computed(() => ! showCompanyField.value);
    const companyRules = computed(() => (isSuperadmin.value ? 'required' : ''));
    const ownBranchOnly = computed(() => ! isSuperadmin.value && ! isCompanyadmin.value && Boolean(authUser.value?.branch_id));

    const { fetchCompany, fetchBranch, companiesdata, branchesdata } = useCommons();
    const customers = ref<Array<{ id: number; text: string }>>([]);

    async function loadCustomers(companyId: unknown) {
        if (! companyId) {
            customers.value = [];

            return;
        }

        try {
            const response = await window.axios.get(API_ENDPOINTS.fetchCustomers, { params: { company_id: companyId } });
            customers.value = (response.data ?? []).map((customer: { id: number; text?: string | null; first_name?: string; last_name?: string }) => ({
                id: customer.id,
                text: customer.text || `${customer.first_name ?? ''} ${customer.last_name ?? ''}`.trim() || `#${customer.id}`,
            }));
        } catch {
            customers.value = [];
        }
    }

    async function loadScope(companyId: unknown) {
        await Promise.all([fetchBranch(companyId as string), loadCustomers(companyId)]);

        if (isEdit.value) {
            return;
        }

        if (ownBranchOnly.value && authUser.value?.branch_id) {
            params.formRef?.update?.({ branch_id: authUser.value.branch_id });
        } else if (branchesdata.value.length === 1) {
            params.formRef?.update?.({ branch_id: branchesdata.value[0].id });
        }
    }

    function applyScopedDefaults() {
        if (isSuperadmin.value) {
            return;
        }

        if (params.formData && authUser.value?.company_id) {
            Object.assign(params.formData, { company_id: authUser.value.company_id });
            params.formRef?.update?.({ company_id: authUser.value.company_id });
        }
    }

    onMounted(async () => {
        applyScopedDefaults();

        if (showCompanyField.value) {
            await fetchCompany();
        }

        await loadScope(isSuperadmin.value ? params.formData?.company_id : authUser.value?.company_id);
    });
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="isEdit" hidden="true" />
    <TextElement v-if="showHiddenCompanyField" name="company_id" hidden="true" />

    <GroupElement name="group_cashcollection" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_cashcollection" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <Banknote class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Cash collected</h2>
                    <p class="product-form-section-copy">Who paid, how much and when. Nothing is posted until the collection is completed against invoices.</p>
                </div>
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
            label="Company"
            :columns="colThird"
            label-prop="text"
            value-prop="id"
            :search="true"
            :floating="false"
            :can-clear="false"
            :rules="companyRules"
            @change="loadScope"
        />

        <SelectElement
            name="branch_id"
            :native="false"
            :items="branchesdata"
            id="BranchId"
            field-name="BranchId"
            placeholder="Select branch"
            label="Branch"
            :columns="colThird"
            label-prop="text"
            value-prop="id"
            :search="true"
            :floating="false"
            :can-clear="false"
            :disabled="ownBranchOnly"
            rules="required"
        />

        <SelectElement
            name="contact_id"
            :native="false"
            :items="customers"
            id="ContactId"
            field-name="ContactId"
            placeholder="Select customer"
            label="Customer"
            :columns="colThird"
            label-prop="text"
            value-prop="id"
            :search="true"
            :floating="false"
            :can-clear="false"
            rules="required"
        />

        <DateElement
            id="CollectedOn"
            field-name="CollectedOn"
            name="collected_on"
            label="Collected On"
            placeholder="Select date"
            :columns="colThird"
            :floating="false"
            :default="new Date().toISOString().slice(0, 10)"
            rules="required"
        />

        <TextElement
            id="Amount"
            field-name="Amount"
            name="amount"
            input-type="number"
            label="Amount Collected"
            placeholder="e.g. 5000"
            :columns="colThird"
            rules="required|numeric|min:0.01"
        />

        <TextElement
            id="Note"
            field-name="Note"
            name="note"
            label="Note"
            placeholder="e.g. Collected at shop counter"
            :columns="colThird"
            autocomplete="off"
            rules="nullable|max:500"
        />
    </GroupElement>
</template>
