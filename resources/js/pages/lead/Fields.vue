<script setup lang="ts">
    import { usePage } from '@inertiajs/vue3';
    import { UserPlus, ShieldCheck } from '@lucide/vue';
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
    } | null);

    const normalizeRoleName = (name: unknown): string =>
        String(name ?? '').toLowerCase().replace(/\s+/g, '');

    const roleName = computed(() => normalizeRoleName(authUser.value?.rolename));
    const isSuperadmin = computed(() => roleName.value === 'superadmin');
    const showCompanyField = computed(() => isSuperadmin.value);
    const showHiddenCompanyField = computed(() => ! isSuperadmin.value);
    const companyRules = computed(() => (isSuperadmin.value ? 'required' : ''));

    const statusOptions = [
        { value: 'new', label: 'New' },
        { value: 'contacted', label: 'Contacted' },
        { value: 'qualified', label: 'Qualified' },
        { value: 'unqualified', label: 'Unqualified' },
        { value: 'converted', label: 'Converted' },
    ];

    const { fetchCompany, companiesdata } = useCommons();
    const usersdata = ref<Array<{ id: number; text: string }>>([]);
    const leadSourcesdata = ref<Array<{ id: number; text: string }>>([]);

    async function fetchUsers() {
        try {
            const response = await window.axios.get(API_ENDPOINTS.fetchUsers, {
                params: { company_id: authUser.value?.company_id ?? undefined },
            });
            usersdata.value = response.data ?? [];
        } catch {
            usersdata.value = [];
        }
    }

    async function fetchLeadSources() {
        try {
            const response = await window.axios.get(API_ENDPOINTS.fetchLeadSources, {
                params: { company_id: authUser.value?.company_id ?? undefined },
            });
            leadSourcesdata.value = response.data ?? [];
        } catch {
            leadSourcesdata.value = [];
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

        await fetchUsers();
        await fetchLeadSources();
    });
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="isEdit" hidden="true" />
    <TextElement v-if="showHiddenCompanyField" name="company_id" hidden="true" />

    <GroupElement name="group_lead" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_lead" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <UserPlus class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Lead information</h2>
                    <p class="product-form-section-copy">Who the prospect is and how they reached us</p>
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
            info="Assign this lead to a company."
        />

        <TextElement
            id="Name"
            field-name="Name"
            name="name"
            label="Lead Name"
            placeholder="e.g. Ahmed Raza"
            :columns="colThird"
            autocomplete="off"
            rules="required|min:3|max:200"
        />

        <TextElement
            id="CompanyName"
            field-name="CompanyName"
            name="company_name"
            label="Business Name"
            placeholder="e.g. Raza Traders"
            :columns="colThird"
            autocomplete="off"
            rules="nullable|max:200"
        />

        <TextElement
            id="Email"
            field-name="Email"
            name="email"
            input-type="email"
            label="Email"
            placeholder="e.g. ahmed@example.com"
            :columns="colThird"
            autocomplete="off"
            rules="nullable|email|max:200"
        />

        <TextElement
            id="Phone"
            field-name="Phone"
            name="phone"
            label="Phone"
            placeholder="e.g. 0300-1234567"
            :columns="colThird"
            autocomplete="off"
            rules="nullable|max:30"
        />

        <SelectElement
            name="lead_source_id"
            :native="false"
            :items="leadSourcesdata"
            id="LeadSourceId"
            field-name="LeadSourceId"
            placeholder="Select a source"
            label="Lead Source"
            :columns="colThird"
            label-prop="text"
            value-prop="id"
            :search="true"
            :floating="false"
            info="Manage the list under CRM > Lead Sources."
        />

        <SelectElement
            name="assigned_to"
            :native="false"
            :items="usersdata"
            id="AssignedTo"
            field-name="AssignedTo"
            placeholder="Unassigned"
            label="Assigned To"
            :columns="colThird"
            label-prop="text"
            value-prop="id"
            :search="true"
            :floating="false"
            info="The sales rep this lead is assigned to."
        />

        <TextareaElement
            id="Notes"
            field-name="Notes"
            name="notes"
            label="Notes"
            placeholder="Anything worth remembering about this lead"
            :columns="colFull"
            rules="nullable|max:2000"
        />
    </GroupElement>

    <GroupElement name="group_status" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_status" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <ShieldCheck class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Status</h2>
                    <p class="product-form-section-copy">Where this lead stands right now</p>
                </div>
            </div>
        </StaticElement>

        <SelectElement
            name="status"
            :native="false"
            :items="statusOptions"
            id="Status"
            field-name="Status"
            label="Lead Status"
            :columns="colThird"
            label-prop="label"
            value-prop="value"
            :can-clear="false"
            default="new"
        />
    </GroupElement>
</template>
