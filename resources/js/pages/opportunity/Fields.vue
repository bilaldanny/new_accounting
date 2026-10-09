<script setup lang="ts">
    import { usePage } from '@inertiajs/vue3';
    import { Target, ShieldCheck } from '@lucide/vue';
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

    const { fetchCompany, companiesdata } = useCommons();
    const usersdata = ref<Array<{ id: number; text: string }>>([]);
    const leadsdata = ref<Array<{ id: number; text: string }>>([]);
    const contactsdata = ref<Array<{ id: number; text: string }>>([]);
    const stagesdata = ref<Array<{ id: number; text: string }>>([]);

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

    async function fetchLeads() {
        try {
            const response = await window.axios.get(API_ENDPOINTS.fetchLeads, {
                params: { company_id: authUser.value?.company_id ?? undefined },
            });
            leadsdata.value = response.data ?? [];
        } catch {
            leadsdata.value = [];
        }
    }

    async function fetchContacts() {
        try {
            const response = await window.axios.get(API_ENDPOINTS.fetchCustomers, {
                params: { company_id: authUser.value?.company_id ?? undefined },
            });
            contactsdata.value = response.data ?? [];
        } catch {
            contactsdata.value = [];
        }
    }

    async function fetchStages() {
        try {
            const response = await window.axios.get(API_ENDPOINTS.fetchPipelineStages, {
                params: { company_id: authUser.value?.company_id ?? undefined },
            });
            stagesdata.value = response.data ?? [];
        } catch {
            stagesdata.value = [];
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

        await Promise.all([fetchUsers(), fetchLeads(), fetchContacts(), fetchStages()]);
    });
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="isEdit" hidden="true" />
    <TextElement v-if="showHiddenCompanyField" name="company_id" hidden="true" />

    <GroupElement name="group_opportunity" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_opportunity" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <Target class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Opportunity information</h2>
                    <p class="product-form-section-copy">The deal, its value, and where it's headed</p>
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
            info="Assign this opportunity to a company."
        />

        <TextElement
            id="Name"
            field-name="Name"
            name="name"
            label="Opportunity Name"
            placeholder="e.g. Raza Traders - Annual Supply Deal"
            :columns="colThird"
            autocomplete="off"
            rules="required|min:3|max:200"
        />

        <TextElement
            id="DealValue"
            field-name="DealValue"
            name="deal_value"
            input-type="number"
            label="Deal Value"
            placeholder="e.g. 150000"
            :columns="colThird"
            autocomplete="off"
            rules="nullable|numeric|min:0"
        />

        <DateElement
            id="ExpectedClosingDate"
            field-name="ExpectedClosingDate"
            name="expected_closing_date"
            label="Expected Closing Date"
            placeholder="Select expected closing date"
            :columns="colThird"
            :floating="false"
            rules="nullable|date"
        />

        <SelectElement
            name="lead_id"
            :native="false"
            :items="leadsdata"
            id="LeadId"
            field-name="LeadId"
            placeholder="None"
            label="From Lead"
            :columns="colThird"
            label-prop="text"
            value-prop="id"
            :search="true"
            :floating="false"
            info="Optional — where this opportunity came from, if it started as a lead."
        />

        <SelectElement
            name="contact_id"
            :native="false"
            :items="contactsdata"
            id="ContactId"
            field-name="ContactId"
            placeholder="None"
            label="Customer"
            :columns="colThird"
            label-prop="text"
            value-prop="id"
            :search="true"
            :floating="false"
            info="Optional — link to an existing customer once one exists."
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
            info="The sales rep working this deal."
        />

        <TextareaElement
            id="Notes"
            field-name="Notes"
            name="notes"
            label="Notes"
            placeholder="Anything worth remembering about this deal"
            :columns="colFull"
            rules="nullable|max:2000"
        />
    </GroupElement>

    <GroupElement name="group_stage" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_stage" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <ShieldCheck class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Pipeline Stage</h2>
                    <p class="product-form-section-copy">Where this deal sits on the board right now</p>
                </div>
            </div>
        </StaticElement>

        <SelectElement
            name="pipeline_stage_id"
            :native="false"
            :items="stagesdata"
            id="PipelineStageId"
            field-name="PipelineStageId"
            placeholder="Select a stage"
            label="Stage"
            :columns="colThird"
            label-prop="text"
            value-prop="id"
            :search="true"
            :floating="false"
            info="Manage the list under CRM > Pipeline Stages. Status (Open/Won/Lost) follows this automatically."
        />

        <TextElement
            id="LostReason"
            field-name="LostReason"
            name="lost_reason"
            label="Lost Reason"
            placeholder="e.g. Went with a competitor"
            :columns="colThird"
            autocomplete="off"
            rules="nullable|max:200"
            info="Only relevant once this moves to a Lost stage."
        />
    </GroupElement>
</template>
