<script setup lang="ts">
    import { AlertCircle, BookOpen, CheckCircle2, Copy, Pencil, Plus, Trash2 } from '@lucide/vue';
    import { computed, ref, watch } from 'vue';
    import type { DepositLineRow } from '@/composables/deposit';

    type AccountOption = {
        id: number | string;
        text?: string;
        name?: string;
        code?: string;
        acc_nature?: string;
    };

    const props = defineProps({
        lines: {
            type: Array as () => DepositLineRow[],
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
    });

    const emit = defineEmits<{
        add: [line: DepositLineRow];
        update: [index: number, line: DepositLineRow];
        remove: [index: number];
    }>();

    const draft = ref<DepositLineRow>({
        account_id: '',
        description: '',
        debit: '',
        credit: '',
        code: '',
        account_name: '',
        account_nature: '',
    });
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

    function applyAccount(line: DepositLineRow, accountId: number | string | ''): DepositLineRow {
        const account = selectedAccount(accountId);

        return {
            ...line,
            account_id: accountId,
            code: account?.code ?? '',
            account_name: account?.name ?? '',
            account_nature: account?.acc_nature ?? '',
        };
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
        };
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

        const debit = Number(draft.value.debit || 0);
        const credit = Number(draft.value.credit || 0);

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

    function duplicateLine(line: DepositLineRow) {
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
                        <th class="is-amount is-debit-head">Debit{{ moneySuffix }}</th>
                        <th class="is-amount is-credit-head">Credit{{ moneySuffix }}</th>
                        <th class="is-action">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="lines.length === 0 && editingIndex === null">
                        <td colspan="6" class="journal-lines__empty">
                            <BookOpen class="journal-lines__empty-icon" />
                            <p>No account lines recorded</p>
                            <small>Select the receiving account below, enter a debit or credit amount, then add the line to balance this deposit.</small>
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
                        <td colspan="6">
                            <div class="journal-lines__error-inner">
                                <AlertCircle class="h-3.5 w-3.5" />
                                <span>{{ lineInputError }}</span>
                            </div>
                        </td>
                    </tr>
                    <tr class="journal-lines__totals">
                        <td colspan="3" class="journal-lines__total-label">Totals:</td>
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
