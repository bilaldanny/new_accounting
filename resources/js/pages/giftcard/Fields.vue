<script setup lang="ts">
    import { usePage } from '@inertiajs/vue3';
    import { Gift, ShieldCheck } from '@lucide/vue';
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

        await loadCustomers(isSuperadmin.value ? params.formData?.company_id : authUser.value?.company_id);
    });
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="isEdit" hidden="true" />
    <TextElement v-if="showHiddenCompanyField" name="company_id" hidden="true" />

    <GroupElement name="group_giftcard" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_giftcard" :columns="colFull">
            <div class="product-form-section-head">
                <span class="product-form-section-icon">
                    <Gift class="h-4 w-4" />
                </span>
                <div>
                    <h2 class="product-form-section-title">Gift card</h2>
                    <p class="product-form-section-copy">The card, its value and who it belongs to</p>
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
            :disabled="isEdit"
            info="Assign this gift card to a company."
            @change="loadCustomers"
        />

        <TextElement
            id="Code"
            field-name="Code"
            name="code"
            label="Card Code"
            placeholder="Leave empty to generate one"
            :columns="colThird"
            autocomplete="off"
            :disabled="isEdit"
            rules="nullable|max:50|regex:/^[A-Za-z0-9_-]+$/"
            info="Letters, numbers, dash and underscore. It cannot be changed once issued."
        />

        <TextElement
            id="InitialValue"
            field-name="InitialValue"
            name="initial_value"
            input-type="number"
            label="Card Value"
            placeholder="e.g. 1000"
            :columns="colThird"
            :disabled="isEdit"
            rules="required|numeric|min:0.01"
            info="The balance the card starts with. Use Top up on the card afterwards to add more."
        />

        <SelectElement
            id="ContactId"
            field-name="ContactId"
            name="contact_id"
            label="Holder"
            placeholder="No holder (bearer card)"
            :native="false"
            :items="customers"
            label-prop="text"
            value-prop="id"
            :search="true"
            :floating="false"
            :can-clear="true"
            :columns="colThird"
            info="Optionally tie the card to a customer."
        />

        <DateElement
            id="ExpiresAt"
            field-name="ExpiresAt"
            name="expires_at"
            label="Expires On"
            placeholder="No expiry"
            :columns="colThird"
            :floating="false"
        />

        <TextElement
            id="Note"
            field-name="Note"
            name="note"
            label="Note"
            placeholder="Optional note"
            :columns="colThird"
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
                    <p class="product-form-section-copy">Control whether this card can be spent or topped up</p>
                </div>
            </div>
        </StaticElement>

        <ToggleElement
            :labels="{ 1: 'Active', 0: 'Inactive' }"
            :columns="colThird"
            id="IsActive"
            field-name="IsActive"
            name="is_active"
            label="Card Status"
            :true-value="true"
            :false-value="false"
            :default="true"
            info="Inactive gift cards cannot be redeemed."
        />
    </GroupElement>
</template>
