<script setup lang="ts">
    import { AlertCircle, BookOpen, CheckCircle2, Copy, Pencil, Plus, Trash2 } from '@lucide/vue';
    import { computed, ref, watch } from 'vue';
    import type { JournalLineRow } from '@/composables/journalentry';

    type AccountOption = {
        id: number | string;
        text?: string;
        name?: string;
        code?: string;
        acc_nature?: string;
    };

    const props = defineProps({
        lines: {
            type: Array as () => JournalLineRow[],
            default: () => [],
        },
        accounts: {
            type: Array as () => AccountOption[],
            default: () => [],
        },
        disabled: {
            type: Boolean,
            default: false,
        },
        currencySymbol: {
            type: String,
            default: '',
        },
        costCenters: {
            type: Array as () => Array<{ id: number | string; text?: string; name?: string; code?: string }>,
            default: () => [],
        },
        /** The company's open projects (empty = no column). */
        projects: {
            type: Array as () => Array<{ id: number | string; text: string }>,
            default: () => [],
        },
        /** Foreign currencies with today's rate, only when multi-currency vouchers are on (empty = no column). */
        currencies: {
            type: Array as () => Array<{ id: number | string; text: string; rate: number }>,
            default: () => [],
        },
    });

    const emit = defineEmits<{
        add: [line: JournalLineRow];
        update: [index: number, line: JournalLineRow];
        remove: [index: number];
    }>();

    const draft = ref<JournalLineRow>({
        account_id: '',
        description: '',
        debit: '',
        credit: '',
        code: '',
        account_name: '',
        account_nature: '',
        cost_center_id: '',
        project_id: '',
        currency_id: '',
        foreign_amount: '',
        exchange_rate: '',
    });
    /** Which side a foreign amount is booked on when no base amount is typed. */
    const foreignSide = ref<'debit' | 'credit'>('debit');
    const columnCount = computed(() => 6 + (props.costCenters.length ? 1 : 0) + (props.projects.length ? 1 : 0) + (props.currencies.length ? 1 : 0));
    const editingIndex = ref<number | null>(null);
    const lineInputError = ref('');

    const totalDebit = computed(() => props.lines.reduce((sum, line) => sum + Number(line.debit || 0), 0));
    const totalCredit = computed(() => props.lines.reduce((sum, line) => sum + Number(line.credit || 0), 0));
    const difference = computed(() => Math.round((totalDebit.value - totalCredit.value) * 100) / 100);
    const isBalanced = computed(() => difference.value === 0 && totalDebit.value > 0);
    const moneySuffix = computed(() => props.currencySymbol ? ` (${props.currencySymbol})` : '');
    const accountGroups = computed(() => {
        const groups = new Map<string, AccountOption[]>();

        props.accounts.forEach((account) => {
            const key = String(account.acc_nature || 'Accounts');
            const items = groups.get(key) ?? [];
            items.push(account);
            groups.set(key, items);
        });

        return Array.from(groups.entries());
    });

    function money(value: number): string {
        return value.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    function accountLabel(account: AccountOption): string {
        return account.text || `${account.code ?? ''} - ${account.name ?? ''}`.replace(/^\s-\s/, '');
    }

    function selectedAccount(accountId: number | string | ''): AccountOption | undefined {
        return props.accounts.find((account) => String(account.id) === String(accountId));
    }

    function applyAccount(line: JournalLineRow, accountId: number | string | ''): JournalLineRow {
        const account = selectedAccount(accountId);

        return {
            ...line,
            account_id: accountId,
            code: account?.code ?? '',
            account_name: account?.name ?? '',
            account_nature: account?.acc_nature ?? '',
        };
    }

    function costCenterLabel(id: number | string | '' | undefined): string {
        const found = props.costCenters.find((center) => String(center.id) === String(id));

        return found ? (found.text || `${found.code ?? ''} ${found.name ?? ''}`.trim()) : '—';
    }

    function projectLabel(id: number | string | '' | null | undefined): string {
        return props.projects.find((project) => String(project.id) === String(id))?.text ?? '—';
    }

    function currencyLabel(line: JournalLineRow): string {
        const found = props.currencies.find((currency) => String(currency.id) === String(line.currency_id));

        return found && Number(line.foreign_amount || 0) > 0 ? `${found.text} ${money(Number(line.foreign_amount))} @ ${line.exchange_rate}` : '—';
    }

    function onCurrencyChange() {
        const found = props.currencies.find((currency) => String(currency.id) === String(draft.value.currency_id));
        draft.value.exchange_rate = found ? found.rate : '';

        if (! found) {
            draft.value.foreign_amount = '';
        }
    }

    function resetDraft() {
        draft.value = {
            account_id: '',
            description: '',
            debit: '',
            credit: '',
            code: '',
            account_name: '',
            account_nature: '',
            cost_center_id: '',
            project_id: '',
            currency_id: '',
            foreign_amount: '',
            exchange_rate: '',
        };
        foreignSide.value = 'debit';
        editingIndex.value = null;
        lineInputError.value = '';
    }

    function onDebitInput(value: string) {
        draft.value.debit = value;
        lineInputError.value = '';

        if (Number(value) > 0) {
            draft.value.credit = '';
        }
    }

    function onCreditInput(value: string) {
        draft.value.credit = value;
        lineInputError.value = '';

        if (Number(value) > 0) {
            draft.value.debit = '';
        }
    }

    function commitDraft() {
        if (props.disabled) {
            return;
        }

        if (! draft.value.account_id) {
            lineInputError.value = 'Select an account before adding a line.';

            return;
        }

        let debit = Number(draft.value.debit || 0);
        let credit = Number(draft.value.credit || 0);
        const foreign = draft.value.currency_id ? Number(draft.value.foreign_amount || 0) : 0;
        const rate = Number(draft.value.exchange_rate || 0);

        // a foreign amount with no base amount typed: the base amount is the foreign amount over the rate (foreign units per base unit)
        if (foreign > 0 && rate > 0 && debit <= 0 && credit <= 0) {
            const base = Math.round((foreign / rate) * 100) / 100;

            foreignSide.value === 'credit' ? credit = base : debit = base;
        }

        if (debit <= 0 && credit <= 0) {
            lineInputError.value = 'Enter a debit or credit amount.';

            return;
        }

        const line = applyAccount({
            ...draft.value,
            debit: debit > 0 ? debit : 0,
            credit: credit > 0 ? credit : 0,
        }, draft.value.account_id);

        if (editingIndex.value === null) {
            emit('add', line);
        } else {
            emit('update', editingIndex.value, line);
        }

        resetDraft();
    }

    function editLine(index: number) {
        const line = props.lines[index];

        if (! line) {
            return;
        }

        draft.value = { ...line };
        editingIndex.value = index;
        lineInputError.value = '';
    }

    function duplicateLine(line: JournalLineRow) {
        if (props.disabled) {
            return;
        }

        emit('add', {
            account_id: line.account_id,
            description: line.description,
            debit: line.debit,
            credit: line.credit,
            code: line.code,
            account_name: line.account_name,
            account_nature: line.account_nature,
            cost_center_id: line.cost_center_id ?? '',
            project_id: line.project_id ?? '',
            currency_id: line.currency_id ?? '',
            foreign_amount: line.foreign_amount ?? '',
            exchange_rate: line.exchange_rate ?? '',
        });
    }

    watch(() => draft.value.account_id, (accountId) => {
        draft.value = applyAccount(draft.value, accountId);
        lineInputError.value = '';
    });
</script>

<template>
    <div class="journal-lines" :class="{ 'is-disabled': disabled }">
        <div class="journal-lines__table-wrap">
            <table class="journal-lines__table">
                <thead>
                    <tr>
                        <th class="is-index">#</th>
                        <th>Account</th>
                        <th>Description</th>
                        <th v-if="costCenters.length">Cost center</th>
                        <th v-if="projects.length">Project</th>
                        <th v-if="currencies.length">Foreign currency</th>
                        <th class="is-amount is-debit-head">Debit{{ moneySuffix }}</th>
                        <th class="is-amount is-credit-head">Credit{{ moneySuffix }}</th>
                        <th class="is-action">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="lines.length === 0 && editingIndex === null">
                        <td :colspan="columnCount" class="journal-lines__empty">
                            <BookOpen class="journal-lines__empty-icon" />
                            <p>No account lines recorded</p>
                            <small>Select an account below, enter a debit or credit amount, then add the line to balance your ledger.</small>
                        </td>
                    </tr>
                    <tr
                        v-for="(line, index) in lines"
                        :key="`${line.account_id}-${index}`"
                        :class="{ 'is-editing': editingIndex === index }"
                    >
                        <td class="is-index">{{ index + 1 }}</td>
                        <td>
                            <div class="journal-lines__account">
                                <span class="journal-lines__code">{{ line.code }}</span>
                                <div>
                                    <span class="journal-lines__name">{{ line.account_name }}</span>
                                    <span v-if="line.account_nature" class="journal-lines__nature">{{ line.account_nature }}</span>
                                </div>
                            </div>
                        </td>
                        <td class="journal-lines__desc">{{ line.description || '—' }}</td>
                        <td v-if="costCenters.length">{{ costCenterLabel(line.cost_center_id) }}</td>
                        <td v-if="projects.length">{{ projectLabel(line.project_id) }}</td>
                        <td v-if="currencies.length">{{ currencyLabel(line) }}</td>
                        <td class="is-amount is-debit">{{ Number(line.debit || 0) > 0 ? money(Number(line.debit)) : '—' }}</td>
                        <td class="is-amount is-credit">{{ Number(line.credit || 0) > 0 ? money(Number(line.credit)) : '—' }}</td>
                        <td class="is-action">
                            <button type="button" class="journal-icon-btn" title="Duplicate line" :disabled="disabled" @click="duplicateLine(line)">
                                <Copy class="h-3.5 w-3.5" />
                            </button>
                            <button type="button" class="journal-icon-btn" title="Edit line" :disabled="disabled" @click="editLine(index)">
                                <Pencil class="h-3.5 w-3.5" />
                            </button>
                            <button type="button" class="journal-icon-btn is-danger" title="Remove line" :disabled="disabled" @click="emit('remove', index)">
                                <Trash2 class="h-3.5 w-3.5" />
                            </button>
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="journal-lines__composer-row">
                        <td class="is-index journal-lines__plus">+</td>
                        <td>
                            <select class="form-select" :disabled="disabled" v-model="draft.account_id">
                                <option value="">Select account...</option>
                                <optgroup
                                    v-for="[group, items] in accountGroups"
                                    :key="group"
                                    :label="group"
                                >
                                    <option v-for="account in items" :key="account.id" :value="account.id">
                                        {{ accountLabel(account) }}
                                    </option>
                                </optgroup>
                            </select>
                        </td>
                        <td>
                            <input
                                type="text"
                                class="form-control"
                                :disabled="disabled"
                                v-model="draft.description"
                                placeholder="Line narration or item note..."
                                @keydown.enter.prevent="commitDraft"
                            >
                        </td>
                        <td v-if="costCenters.length">
                            <select class="form-select" :disabled="disabled" v-model="draft.cost_center_id">
                                <option value="">No cost center</option>
                                <option v-for="center in costCenters" :key="center.id" :value="center.id">{{ center.text || `${center.code ?? ''} ${center.name ?? ''}` }}</option>
                            </select>
                        </td>
                        <td v-if="projects.length">
                            <select class="form-select" :disabled="disabled" v-model="draft.project_id" aria-label="Project">
                                <option value="">No project</option>
                                <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.text }}</option>
                            </select>
                        </td>
                        <td v-if="currencies.length">
                            <div class="d-flex gap-1">
                                <select class="form-select" :disabled="disabled" v-model="draft.currency_id" aria-label="Foreign currency" @change="onCurrencyChange">
                                    <option value="">Base currency</option>
                                    <option v-for="currency in currencies" :key="currency.id" :value="currency.id">{{ currency.text }}</option>
                                </select>
                                <template v-if="draft.currency_id">
                                    <input v-model="draft.foreign_amount" type="number" min="0" step="0.01" class="form-control text-end" :disabled="disabled" placeholder="Amount" aria-label="Foreign amount">
                                    <input v-model="draft.exchange_rate" type="number" min="0" step="0.0001" class="form-control text-end" :disabled="disabled" placeholder="Rate" aria-label="Exchange rate">
                                    <select v-model="foreignSide" class="form-select" :disabled="disabled" aria-label="Foreign amount side"><option value="debit">Dr</option><option value="credit">Cr</option></select>
                                </template>
                            </div>
                        </td>
                        <td class="is-amount">
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                class="form-control text-end journal-lines__debit-input"
                                :disabled="disabled"
                                :value="draft.debit"
                                placeholder="0.00"
                                @input="onDebitInput(($event.target as HTMLInputElement).value)"
                                @keydown.enter.prevent="commitDraft"
                            >
                        </td>
                        <td class="is-amount">
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                class="form-control text-end"
                                :disabled="disabled"
                                :value="draft.credit"
                                placeholder="0.00"
                                @input="onCreditInput(($event.target as HTMLInputElement).value)"
                                @keydown.enter.prevent="commitDraft"
                            >
                        </td>
                        <td class="is-action">
                            <button type="button" class="journal-lines__add" :disabled="disabled" @click="commitDraft">
                                <Plus class="h-3.5 w-3.5" />
                                {{ editingIndex === null ? 'Add' : 'Update' }}
                            </button>
                        </td>
                    </tr>
                    <tr v-if="lineInputError" class="journal-lines__error">
                        <td :colspan="columnCount">
                            <div class="journal-lines__error-inner">
                                <AlertCircle class="h-3.5 w-3.5" />
                                <span>{{ lineInputError }}</span>
                            </div>
                        </td>
                    </tr>
                    <tr class="journal-lines__totals">
                        <td :colspan="columnCount - 3" class="journal-lines__total-label">Totals:</td>
                        <td class="is-amount is-debit">{{ currencySymbol ? `${currencySymbol} ${money(totalDebit)}` : money(totalDebit) }}</td>
                        <td class="is-amount is-credit">{{ currencySymbol ? `${currencySymbol} ${money(totalCredit)}` : money(totalCredit) }}</td>
                        <td class="is-action">
                            <span class="journal-lines__balance" :class="isBalanced ? 'is-ok' : 'is-off'">
                                <CheckCircle2 v-if="isBalanced" class="h-3.5 w-3.5" />
                                <AlertCircle v-else class="h-3 w-3" />
                                {{ isBalanced ? 'Balanced' : `Out by ${money(Math.abs(difference))}` }}
                            </span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</template>
