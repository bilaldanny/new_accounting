<script setup lang="ts">
    import { usePage } from '@inertiajs/vue3';
    import { Truck, ShieldCheck } from '@lucide/vue';
    import { computed, onMounted } from 'vue';
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
    });
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="isEdit" hidden="true" />
    <TextElement v-if="showHiddenCompanyField" name="company_id" hidden="true" />

    <GroupElement name="group_transporter" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_transporter" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <Truck class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Transporter information</h2>
                    <p class="product-form-section-copy">Who carries the goods, and how to reach them</p>
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
            info="Assign this transporter to a company."
        />

        <TextElement
            id="Name"
            field-name="Name"
            name="name"
            label="Transporter Name"
            placeholder="e.g. Ali Goods Transport"
            :columns="colThird"
            autocomplete="off"
            rules="required|min:3|max:200"
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

        <TextElement
            id="VehicleNo"
            field-name="VehicleNo"
            name="vehicle_no"
            label="Vehicle Number"
            placeholder="e.g. LEA-1234"
            :columns="colThird"
            autocomplete="off"
            rules="nullable|max:50"
        />

        <TextElement
            id="Address"
            field-name="Address"
            name="address"
            label="Address"
            placeholder="Address of the transporter"
            :columns="colFull"
            autocomplete="off"
            rules="nullable|max:500"
        />
    </GroupElement>

    <GroupElement name="group_settings" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_settings" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <ShieldCheck class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Status</h2>
                    <p class="product-form-section-copy">Control whether this transporter can be chosen</p>
                </div>
            </div>
        </StaticElement>

        <ToggleElement
            :labels="{ 1: 'Active', 0: 'Inactive' }"
            :columns="colThird"
            id="IsActive"
            field-name="IsActive"
            name="is_active"
            label="Transporter Status"
            :true-value="true"
            :false-value="false"
            :default="true"
            info="Inactive transporters cannot be chosen."
        />
    </GroupElement>
</template>
