<script setup lang="ts">
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useActiveFinancialYear from '@/composables/activeFinancialYear';
    import useCommons from '@/composables/common';
    import type { JournalAttachment, JournalLineRow } from '@/composables/journalentry';
    import { Banknote, BookOpen, FileText, Globe, Landmark, Paperclip, RefreshCw, UploadCloud, Wallet } from '@lucide/vue';
    import { usePage } from '@inertiajs/vue3';
    import { computed, onMounted, ref, watch } from 'vue';
    import FieldHint from '@/pages/journalentry/FieldHint.vue';
    import JournalLinesEditor from '@/pages/journalentry/JournalLinesEditor.vue';

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
    const colThird = { container: 4, label: 12, wrapper: 12 };
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
    const ledgerCardClasses = {
        ElementLayout: {
            container: 'product-form-card journal-form-card--ledger',
        },
        GroupElement: {
            wrapper: 'product-form-card__body journal-form-card__body--flush',
        },
    };
    const isDraggingFile = ref(false);

    const paymentKinds = [
        { id: 'BP', title: 'Bank Payment', hint: 'Issue payment from a commercial bank account or corporate cheque', icon: 'bank' },
        { id: 'CP', title: 'Cash Payment', hint: 'Disburse payout directly from counter safe or petty cash in hand', icon: 'cash' },
        { id: 'OP', title: 'Online Payment', hint: 'Issue payment through Raast, RTGS, 1Link IBFT, or card gateway', icon: 'online' },
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
    const canManageBranch = computed(() => isSuperadmin.value || isCompanyadmin.value);
    const showBranchField = computed(() => canManageBranch.value && branchesdata.value.length > 1);
    const showHiddenCompanyField = computed(() => ! isSuperadmin.value);
    const showHiddenBranchField = computed(() => ! showBranchField.value);
    const isEdit = computed(() => params.type === 'edit');
    const selectedKind = computed(() => String(params.formData?.voucher_type || 'BP'));

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
    const commentsLength = computed(() => String(params.formData?.comments ?? '').length);
    const currencyLabel = computed(() => {
        const symbol = String(authUser.value?.currency_symbol ?? '').trim();

        return symbol ? `Currency: ${symbol}` : 'Currency';
    });
    const chequeLabel = computed(() => {
        if (selectedKind.value === 'CP') {
            return 'Cash Receipt Voucher #';
        }

        if (selectedKind.value === 'OP') {
            return 'Transaction ID / UTR #';
        }

        return 'Cheque # / Instrument Ref';
    });
    const chequePlaceholder = computed(() => (
        selectedKind.value === 'BP' ? 'e.g. CHQ-99014' : 'e.g. TR-891023'
    ));

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

    function selectPaymentKind(id: string) {
        if (isEdit.value) {
            return;
        }

        persist({
            voucher_type: id,
            cheque_no: id === 'OP' ? 'ONLINE' : '',
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
            const response = await window.axios.get(API_ENDPOINTS.paymentVoucherNo, {
                params: {
                    company_id: selectedCompanyId.value,
                    branch_id: selectedBranchId.value,
                    type: params.formData?.voucher_type || 'BP',
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

    function clearLines() {
        persistLines([]);
    }

    function addFiles(files: File[]) {
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
    }

    function onFilesSelected(event: Event) {
        const input = event.target as HTMLInputElement;
        addFiles(Array.from(input.files ?? []));
        input.value = '';
    }

    function onFilesDropped(event: DragEvent) {
        event.preventDefault();
        isDraggingFile.value = false;
        addFiles(Array.from(event.dataTransfer?.files ?? []));
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

    <GroupElement name="group_method" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_kind" :columns="colFull">
            <div class="product-form-section-head journal-card-head">
                <div class="journal-card-head__lead">
                    <span class="product-form-section-icon">
                        <Wallet class="h-4 w-4" />
                    </span>
                    <div>
                        <span class="journal-card-eyebrow">Payment method</span>
                        <h2 class="product-form-section-title">How is this payment being made?</h2>
                    </div>
                </div>
                <span class="journal-card-meta">Select disbursement instrument</span>
            </div>
            <div class="payment-kind-grid" role="radiogroup" aria-label="Payment method">
                <button
                    v-for="kind in paymentKinds"
                    :key="kind.id"
                    type="button"
                    class="payment-kind-card"
                    :class="{ 'is-active': selectedKind === kind.id }"
                    :disabled="isEdit"
                    role="radio"
                    :aria-checked="selectedKind === kind.id"
                    @click="selectPaymentKind(kind.id)"
                >
                    <span class="payment-kind-card__lead">
                        <span class="payment-kind-card__icon" :class="`is-${kind.icon}`">
                            <Landmark v-if="kind.icon === 'bank'" class="h-5 w-5" />
                            <Banknote v-else-if="kind.icon === 'cash'" class="h-5 w-5" />
                            <Globe v-else class="h-5 w-5" />
                        </span>
                        <span class="payment-kind-card__copy">
                            <strong>{{ kind.title }}</strong>
                            <small>{{ kind.hint }}</small>
                        </span>
                    </span>
                    <span class="payment-kind-card__code">{{ kind.id }}</span>
                </button>
            </div>
        </StaticElement>
    </GroupElement>

    <GroupElement name="group_voucher" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_voucher" :columns="colFull">
            <div class="product-form-section-head journal-card-head">
                <div class="journal-card-head__lead">
                    <span class="product-form-section-icon">
                        <FileText class="h-4 w-4" />
                    </span>
                    <div>
                        <span class="journal-card-eyebrow">Header</span>
                        <h2 class="product-form-section-title">Voucher details</h2>
                    </div>
                </div>
                <span class="journal-card-currency">{{ currencyLabel }}</span>
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
                    Select the company this payment belongs to.
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
                    Required. Choose the branch where this payment will be posted.
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
                    <span class="journal-field__tooltip-rule"><strong>BP:</strong> Bank payment</span>
                    <span class="journal-field__tooltip-rule"><strong>CP:</strong> Cash payment</span>
                    <span class="journal-field__tooltip-rule"><strong>OP:</strong> Online payment</span>
                </FieldHint>
            </template>
            <template #addon-before>
                <span class="journal-voucher-type payment-voucher-prefix">{{ selectedKind }}</span>
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
                    <RefreshCw class="h-3.5 w-3.5" :class="{ 'top-btn-icon-spin': fetchingVoucher }" />
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

        <TextElement
            id="ChequeNo"
            field-name="ChequeNo"
            name="cheque_no"
            default=""
            :placeholder="chequePlaceholder"
            :columns="colThird"
            autocomplete="off"
        >
            <template #label>
                <FieldHint :label="chequeLabel">
                    Optional instrument or transfer reference for this payment.
                </FieldHint>
            </template>
        </TextElement>
    </GroupElement>

    <GroupElement name="group_lines" :columns="colFull" :add-classes="ledgerCardClasses">
        <StaticElement name="section_lines" :columns="colFull">
            <div class="product-form-section-head journal-card-head">
                <div class="journal-card-head__lead">
                    <span class="product-form-section-icon">
                        <BookOpen class="h-4 w-4" />
                    </span>
                    <div>
                        <span class="journal-card-eyebrow">Ledger</span>
                        <div class="journal-card-title-row">
                            <h2 class="product-form-section-title">Account lines</h2>
                            <span class="journal-panel__count">{{ lineCountLabel }}</span>
                        </div>
                    </div>
                </div>
                <button
                    v-if="journalLines.length > 0"
                    type="button"
                    class="journal-clear-all"
                    :disabled="!scopeReady"
                    @click="clearLines"
                >
                    Clear All
                </button>
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
    </GroupElement>

    <GroupElement name="group_notes" :columns="colFull" :add-classes="cardClasses">
        <StaticElement name="section_notes" :columns="colFull">
            <div class="product-form-section-head journal-card-head">
                <div class="journal-card-head__lead">
                    <span class="product-form-section-icon">
                        <Paperclip class="h-4 w-4" />
                    </span>
                    <div>
                        <span class="journal-card-eyebrow">Supporting</span>
                        <h2 class="product-form-section-title">Notes & attachments</h2>
                    </div>
                </div>
                <span class="journal-card-meta">Audit trail & documentary verification</span>
            </div>
        </StaticElement>

        <TextareaElement
            name="comments"
            id="Comments"
            field-name="Comments"
            placeholder="Internal comments for this payment, business purpose, or tax notes…"
            :columns="colHalf"
            :rows="4"
        >
            <template #label>
                <span class="journal-comments-label">
                    <FieldHint label="Comments">
                        Optional internal narration. This is not printed as a line description.
                    </FieldHint>
                    <span class="journal-comments-count">{{ commentsLength }} characters</span>
                </span>
            </template>
        </TextareaElement>

        <StaticElement name="payment_attachments" :columns="colHalf">
            <div class="journal-field">
                <span class="journal-comments-label">
                    <FieldHint label="Attachments">
                        Optional. Images, PDF, or office files can be attached to this voucher.
                    </FieldHint>
                    <span class="journal-comments-count">{{ attachments.length }} file(s) attached</span>
                </span>
                <label
                    class="journal-upload"
                    :class="{ 'is-dragging': isDraggingFile }"
                    for="payment-attachment-input"
                    @dragover.prevent="isDraggingFile = true"
                    @dragleave.prevent="isDraggingFile = false"
                    @drop="onFilesDropped"
                >
                    <UploadCloud class="journal-upload__icon" />
                    <strong>Drop files here or <span>browse</span></strong>
                    <small>PDF, images, or office documents</small>
                    <input
                        id="payment-attachment-input"
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
    </GroupElement>
</template>
