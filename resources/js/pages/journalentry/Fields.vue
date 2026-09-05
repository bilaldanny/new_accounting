<script setup lang="ts">
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useActiveFinancialYear from '@/composables/activeFinancialYear';
    import useCommons from '@/composables/common';
    import type { JournalAttachment, JournalLineRow } from '@/composables/journalentry';
    import { Calendar, FilePlus, Receipt, RefreshCw, Wallet } from '@boxicons/vue';
    import { usePage } from '@inertiajs/vue3';
    import { computed, onMounted, ref, watch } from 'vue';
    import FieldHint from './FieldHint.vue';
    import JournalLinesEditor from './JournalLinesEditor.vue';

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

    const voucherTypes = [
        { id: 'JV', text: 'JV' },
        { id: 'JE', text: 'JE' },
    ];

    const normalizeRoleName = (name: unknown): string =>
        String(name ?? '').toLowerCase().replace(/\s+/g, '');

    const authUser = computed(() => page.props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
        branch_id?: number | string | null;
        currency_symbol?: string | null;
    } | null);

    const roleName = computed(() => normalizeRoleName(authUser.value?.rolename));
    const isSuperadmin = computed(() => roleName.value === 'superadmin');
    const isCompanyadmin = computed(() => roleName.value === 'companyadmin');
    const showCompanyField = computed(() => isSuperadmin.value);
    const showBranchField = computed(() => isSuperadmin.value || isCompanyadmin.value);
    const showHiddenCompanyField = computed(() => ! isSuperadmin.value);
    const showHiddenBranchField = computed(() => ! isSuperadmin.value && ! isCompanyadmin.value);
    const isEdit = computed(() => params.type === 'edit');

    const { fetchCompany, fetchBranch, companiesdata, branchesdata } = useCommons();
    const { fiscalYear, fetchActiveFinancialYear, clampToFiscalYear } = useActiveFinancialYear();

    const accountsdata = ref<Array<{ id: number | string; text?: string; name?: string; code?: string; acc_nature?: string }>>([]);
    const lastFetchedCompanyId = ref('');
    const lastAccountScope = ref('');
    const fetchingVoucher = ref(false);

    const normalizeId = (id: unknown) =>
        id === null || id === undefined || id === '' ? '' : String(id);

    const selectedCompanyId = computed(() => params.formData?.company_id ?? '');
    const selectedBranchId = computed(() => params.formData?.branch_id ?? '');
    const scopeReady = computed(() => Boolean(normalizeId(selectedCompanyId.value) && normalizeId(selectedBranchId.value)));
    const branchDisabled = computed(() => isSuperadmin.value && ! selectedCompanyId.value);
    const companyRules = computed(() => (isSuperadmin.value ? 'required' : ''));
    const journalLines = computed<JournalLineRow[]>(() => (
        Array.isArray(params.formData?.taccountdetails) ? params.formData.taccountdetails : []
    ));
    const attachments = computed<JournalAttachment[]>(() => (
        Array.isArray(params.formData?.attachments) ? params.formData.attachments.filter((file: JournalAttachment) => ! file.deleted) : []
    ));
    const lineCountLabel = computed(() => {
        const count = journalLines.value.length;

        return count === 1 ? '1 line' : `${count} lines`;
    });

    function persist(patch: Record<string, unknown>) {
        if (params.formData) {
            Object.assign(params.formData, patch);
        }

        params.formRef?.update?.(patch);
    }

    function persistLines(lines: JournalLineRow[]) {
        const next = lines.map((line) => ({ ...line }));
        const total = next.reduce((sum, line) => sum + Number(line.debit || 0), 0);
        persist({
            taccountdetails: next,
            total_amount: total,
            net_total: total,
        });
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
        if (! showBranchField.value) {
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
    }

    async function loadAccounts(companyId: string | number | null | undefined, branchId: string | number | null | undefined) {
        const scope = `${normalizeId(companyId)}:${normalizeId(branchId)}`;

        if (! companyId || ! branchId) {
            accountsdata.value = [];
            lastAccountScope.value = '';

            return;
        }

        if (scope === lastAccountScope.value) {
            return;
        }

        lastAccountScope.value = scope;

        try {
            const response = await window.axios.get('/api/fetchchildaccounts', {
                params: { company_id: companyId, branch_id: branchId },
            });
            accountsdata.value = response.data ?? [];
        } catch {
            accountsdata.value = [];
        }
    }

    async function loadVoucherNo(force = false) {
        if (isEdit.value || ! scopeReady.value) {
            return;
        }

        if (! force && params.formData?.voucher_no) {
            return;
        }

        fetchingVoucher.value = true;

        try {
            const response = await window.axios.get(API_ENDPOINTS.journalEntryVoucherNo, {
                params: {
                    company_id: selectedCompanyId.value,
                    branch_id: selectedBranchId.value,
                    type: params.formData?.voucher_type || 'JV',
                },
            });
            persist({ voucher_no: response.data });
        } finally {
            fetchingVoucher.value = false;
        }
    }

    async function handleCompanyChange(companyId: string | number | null | undefined) {
        if (! isSuperadmin.value) {
            return;
        }

        persist({ branch_id: '', taccountdetails: [] });
        lastFetchedCompanyId.value = '';
        lastAccountScope.value = '';
        await loadBranchOptions(companyId);
        await fetchActiveFinancialYear(companyId);
    }

    function addLine(line: JournalLineRow) {
        persistLines([...journalLines.value, line]);
    }

    function updateLine(index: number, line: JournalLineRow) {
        const next = [...journalLines.value];
        next[index] = line;
        persistLines(next);
    }

    function removeLine(index: number) {
        persistLines(journalLines.value.filter((_, lineIndex) => lineIndex !== index));
    }

    function onFilesSelected(event: Event) {
        const input = event.target as HTMLInputElement;
        const files = Array.from(input.files ?? []);

        files.forEach((file) => {
            const reader = new FileReader();
            reader.onload = () => {
                persist({
                    attachments: [
                        ...attachments.value,
                        {
                            file_name: file.name,
                            data_url: String(reader.result ?? ''),
                            ext: file.name.split('.').pop() ?? '',
                        },
                    ],
                });
            };
            reader.readAsDataURL(file);
        });

        input.value = '';
    }

    function removeAttachment(index: number) {
        const next = [...(params.formData?.attachments ?? [])] as JournalAttachment[];
        const visible = attachments.value[index];
        const actualIndex = next.findIndex((file) => file === visible || (visible.id && file.id === visible.id && file.file_name === visible.file_name));

        if (actualIndex < 0) {
            return;
        }

        if (next[actualIndex]?.id) {
            next[actualIndex] = { ...next[actualIndex], deleted: true };
        } else {
            next.splice(actualIndex, 1);
        }

        persist({ attachments: next });
    }

    onMounted(async () => {
        applyScopedDefaults();

        if (showCompanyField.value) {
            await fetchCompany();
        }

        const companyId = isCompanyadmin.value
            ? authUser.value?.company_id
            : selectedCompanyId.value;

        if (companyId) {
            await loadBranchOptions(companyId);
            await fetchActiveFinancialYear(companyId);
        }

        if (scopeReady.value) {
            await loadAccounts(selectedCompanyId.value, selectedBranchId.value);
            await loadVoucherNo();
        }

        if (params.formData?.voucher_date) {
            persist({ voucher_date: clampToFiscalYear(String(params.formData.voucher_date)) });
        }
    });

    watch(
        () => normalizeId(params.formData?.company_id),
        async (companyId, previousCompanyId) => {
            if (companyId === previousCompanyId) {
                return;
            }

            await handleCompanyChange(companyId || undefined);
        },
    );

    watch(
        () => [normalizeId(params.formData?.company_id), normalizeId(params.formData?.branch_id), params.formData?.voucher_type],
        async () => {
            await loadAccounts(selectedCompanyId.value, selectedBranchId.value);
            await loadVoucherNo(true);
        },
    );
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="params.type === 'edit'" hidden="true" />
    <TextElement v-if="showHiddenCompanyField" name="company_id" hidden="true" />
    <TextElement v-if="showHiddenBranchField" name="branch_id" hidden="true" />
    <TextElement name="voucher_type" hidden="true" rules="required" />
    <TextElement name="taccountdetails" hidden="true" />
    <TextElement name="attachments" hidden="true" />
    <TextElement name="total_amount" hidden="true" />
    <TextElement name="net_total" hidden="true" />

    <StaticElement name="section_voucher" :columns="colFull">
        <div class="journal-panel__head">
            <span class="journal-panel__icon is-teal">
                <Receipt size="sm" />
            </span>
            <div>
                <p class="journal-panel__eyebrow">Header</p>
                <h6 class="journal-panel__title">Voucher details</h6>
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
        :columns="colQuarter"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="false"
        :rules="companyRules"
    >
        <template #label>
            <FieldHint label="Company" required>
                Select the company this voucher belongs to.
            </FieldHint>
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
            <FieldHint label="Branch" required>
                Required. Choose the branch where this voucher will be posted.
            </FieldHint>
        </template>
    </SelectElement>

    <TextElement
        id="VoucherNo"
        field-name="VoucherNo"
        name="voucher_no"
        default=""
        placeholder="Auto number"
        :columns="colQuarter"
        autocomplete="off"
        :disabled="isEdit"
        :add-classes="{
            ElementAddon: {
                container: 'p-0',
            },
        }"
    >
        <template #label>
            <FieldHint label="Voucher number">
                Leave it blank to generate a unique number.
                <span class="journal-field__tooltip-rule"><strong>JV:</strong> Journal voucher</span>
                <span class="journal-field__tooltip-rule"><strong>JE:</strong> Journal entry</span>
            </FieldHint>
        </template>
        <template #addon-before>
            <select
                class="journal-voucher-type"
                :value="params.formData?.voucher_type || 'JV'"
                :disabled="isEdit"
                @change="persist({ voucher_type: ($event.target as HTMLSelectElement).value })"
            >
                <option v-for="type in voucherTypes" :key="type.id" :value="type.id">
                    {{ type.text }}
                </option>
            </select>
        </template>
        <template v-if="!isEdit" #addon-after>
            <button
                type="button"
                class="journal-voucher-no__refresh"
                title="Generate next number"
                aria-label="Generate next voucher number"
                :disabled="fetchingVoucher || !scopeReady"
                @click="loadVoucherNo(true)"
            >
                <RefreshCw size="xs" :class="{ 'top-btn-icon-spin': fetchingVoucher }" />
            </button>
        </template>
    </TextElement>

    <DateElement
        id="VoucherDate"
        field-name="VoucherDate"
        name="voucher_date"
        placeholder="Select voucher date"
        :columns="colQuarter"
        :floating="false"
        rules="required"
        :min="fiscalYear?.start_date"
        :max="fiscalYear?.end_date"
        value-format="YYYY-MM-DD"
    >
        <template #label>
            <FieldHint label="Voucher date" required>
                Must fall within the active financial year.
            </FieldHint>
        </template>
    </DateElement>

    <StaticElement name="section_lines" :columns="colFull">
        <div class="journal-panel__head journal-panel__head--spaced">
            <span class="journal-panel__icon is-indigo">
                <Wallet size="sm" />
            </span>
            <div>
                <p class="journal-panel__eyebrow">Ledger</p>
                <h6 class="journal-panel__title">Account lines</h6>
            </div>
            <span class="journal-panel__count">{{ lineCountLabel }}</span>
        </div>
    </StaticElement>

    <StaticElement name="lines_editor" :columns="colFull">
        <JournalLinesEditor
            :lines="journalLines"
            :accounts="accountsdata"
            :disabled="!scopeReady"
            :currency-symbol="authUser?.currency_symbol || ''"
            @add="addLine"
            @update="updateLine"
            @remove="removeLine"
        />
    </StaticElement>

    <StaticElement name="section_notes" :columns="colFull">
        <div class="journal-panel__head journal-panel__head--spaced">
            <span class="journal-panel__icon is-slate">
                <Calendar size="sm" />
            </span>
            <div>
                <p class="journal-panel__eyebrow">Supporting</p>
                <h6 class="journal-panel__title">Notes & attachments</h6>
            </div>
        </div>
    </StaticElement>

    <TextareaElement
        name="comments"
        id="Comments"
        field-name="Comments"
        placeholder="Internal comments for this voucher"
        :columns="colHalf"
        :rows="2"
    >
        <template #label>
            <FieldHint label="Comments">
                Optional internal narration. This is not printed as a line description.
            </FieldHint>
        </template>
    </TextareaElement>

    <StaticElement name="journal_attachments" :columns="colHalf">
        <div class="journal-field">
            <FieldHint label="Attachments">
                Optional. Images, PDF, or office files can be attached to this voucher.
            </FieldHint>
            <label class="journal-upload" for="journal-attachment-input">
                <FilePlus size="sm" />
                <strong>Drop files here or browse</strong>
                <small>PDF, images, or office documents</small>
                <input
                    id="journal-attachment-input"
                    type="file"
                    multiple
                    accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv"
                    @change="onFilesSelected"
                >
            </label>
            <ul v-if="attachments.length" class="journal-upload__list">
                <li v-for="(file, index) in attachments" :key="`${file.file_name}-${index}`">
                    <a v-if="file.data_url" :href="file.data_url" download>{{ file.file_name }}</a>
                    <span v-else>{{ file.file_name }}</span>
                    <button type="button" class="journal-icon-btn is-danger" @click="removeAttachment(index)">Remove</button>
                </li>
            </ul>
        </div>
    </StaticElement>
</template>
