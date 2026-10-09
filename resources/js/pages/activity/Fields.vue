<script setup lang="ts">
    import { usePage } from '@inertiajs/vue3';
    import { CalendarCheck } from '@lucide/vue';
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

    const typeOptions = [
        { value: 'call', label: 'Call' },
        { value: 'meeting', label: 'Meeting' },
        { value: 'task', label: 'Task' },
        { value: 'follow_up', label: 'Follow-up' },
        { value: 'note', label: 'Note' },
        { value: 'email', label: 'Email' },
    ];

    const { fetchCompany, companiesdata } = useCommons();
    const usersdata = ref<Array<{ id: number; text: string }>>([]);
    const leadsdata = ref<Array<{ id: number; text: string }>>([]);
    const opportunitiesdata = ref<Array<{ id: number; text: string }>>([]);
    const contactsdata = ref<Array<{ id: number; text: string }>>([]);

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

    async function fetchOpportunities() {
        try {
            const response = await window.axios.get(API_ENDPOINTS.fetchOpportunities, {
                params: { company_id: authUser.value?.company_id ?? undefined },
            });
            opportunitiesdata.value = response.data ?? [];
        } catch {
            opportunitiesdata.value = [];
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

        await Promise.all([fetchUsers(), fetchLeads(), fetchOpportunities(), fetchContacts()]);
    });
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="isEdit" hidden="true" />
    <TextElement v-if="showHiddenCompanyField" name="company_id" hidden="true" />

    <GroupElement name="group_activity" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_activity" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <CalendarCheck class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Activity information</h2>
                    <p class="product-form-section-copy">A follow-up, task, call, meeting, note or email</p>
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
            info="Assign this activity to a company."
        />

        <SelectElement
            name="type"
            :native="false"
            :items="typeOptions"
            id="Type"
            field-name="Type"
            label="Type"
            :columns="colThird"
            label-prop="label"
            value-prop="value"
            :can-clear="false"
            default="task"
        />

        <TextElement
            id="Subject"
            field-name="Subject"
            name="subject"
            label="Subject"
            placeholder="e.g. Follow up on pricing proposal"
            :columns="colThird"
            autocomplete="off"
            rules="required|min:2|max:200"
        />

        <DateElement
            id="DueAt"
            field-name="DueAt"
            name="due_at"
            label="Due"
            placeholder="Select a due date/time"
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
            label="Related Lead"
            :columns="colThird"
            label-prop="text"
            value-prop="id"
            :search="true"
            :floating="false"
        />

        <SelectElement
            name="opportunity_id"
            :native="false"
            :items="opportunitiesdata"
            id="OpportunityId"
            field-name="OpportunityId"
            placeholder="None"
            label="Related Opportunity"
            :columns="colThird"
            label-prop="text"
            value-prop="id"
            :search="true"
            :floating="false"
        />

        <SelectElement
            name="contact_id"
            :native="false"
            :items="contactsdata"
            id="ContactId"
            field-name="ContactId"
            placeholder="None"
            label="Related Customer"
            :columns="colThird"
            label-prop="text"
            value-prop="id"
            :search="true"
            :floating="false"
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
        />

        <TextareaElement
            id="Description"
            field-name="Description"
            name="description"
            label="Description"
            placeholder="Details, call notes, or the email content"
            :columns="colFull"
            rules="nullable|max:2000"
        />
    </GroupElement>
</template>
