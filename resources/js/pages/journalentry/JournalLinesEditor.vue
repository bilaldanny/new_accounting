<script setup lang="ts">
    import { Edit, Plus, Trash } from '@boxicons/vue';
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
    });
    const editingIndex = ref<number | null>(null);

    const totalDebit = computed(() => props.lines.reduce((sum, line) => sum + Number(line.debit || 0), 0));
    const totalCredit = computed(() => props.lines.reduce((sum, line) => sum + Number(line.credit || 0), 0));
    const difference = computed(() => Math.round((totalDebit.value - totalCredit.value) * 100) / 100);
    const isBalanced = computed(() => difference.value === 0 && totalDebit.value > 0);

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
    }

    function onDebitInput(value: string) {
        draft.value.debit = value;
        if (Number(value) > 0) {
            draft.value.credit = '';
        }
    }

    function onCreditInput(value: string) {
        draft.value.credit = value;
        if (Number(value) > 0) {
            draft.value.debit = '';
        }
    }

    function commitDraft() {
        if (props.disabled) {
            return;
        }

        if (! draft.value.account_id) {
            return;
        }

        const debit = Number(draft.value.debit || 0);
        const credit = Number(draft.value.credit || 0);

        if (debit <= 0 && credit <= 0) {
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
    }

    watch(() => draft.value.account_id, (accountId) => {
        draft.value = applyAccount(draft.value, accountId);
    });
</script>

<template>
    <div class="journal-lines" :class="{ 'is-disabled': disabled }">
        <div class="journal-lines__table-wrap">
            <table class="journal-lines__table">
                <thead>
                    <tr>
                        <th>Account</th>
                        <th>Description</th>
                        <th class="is-amount">Debit</th>
                        <th class="is-amount">Credit</th>
                        <th class="is-action">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="lines.length === 0 && editingIndex === null">
                        <td colspan="5" class="journal-lines__empty">
                            Select an account, enter a debit or credit, then add the line.
                        </td>
                    </tr>
                    <tr
                        v-for="(line, index) in lines"
                        :key="`${line.account_id}-${index}`"
                        :class="{ 'is-editing': editingIndex === index }"
                    >
                        <td>
                            <div class="journal-lines__account">
                                <span class="journal-lines__code">{{ line.code }}</span>
                                <span>{{ line.account_name }}</span>
                            </div>
                        </td>
                        <td class="journal-lines__desc">{{ line.description || '—' }}</td>
                        <td class="is-amount is-debit">{{ Number(line.debit || 0) > 0 ? money(Number(line.debit)) : '—' }}</td>
                        <td class="is-amount is-credit">{{ Number(line.credit || 0) > 0 ? money(Number(line.credit)) : '—' }}</td>
                        <td class="is-action">
                            <button type="button" class="journal-icon-btn" title="Edit line" :disabled="disabled" @click="editLine(index)">
                                <Edit size="xs" />
                            </button>
                            <button type="button" class="journal-icon-btn is-danger" title="Remove line" :disabled="disabled" @click="emit('remove', index)">
                                <Trash size="xs" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="journal-lines__composer">
            <select class="form-select" :disabled="disabled" v-model="draft.account_id">
                <option value="">Select account</option>
                <option v-for="account in accounts" :key="account.id" :value="account.id">
                    {{ accountLabel(account) }}
                </option>
            </select>
            <input
                type="text"
                class="form-control"
                :disabled="disabled"
                v-model="draft.description"
                placeholder="Line narration"
            >
            <input
                type="number"
                min="0"
                step="0.01"
                class="form-control text-end"
                :disabled="disabled"
                :value="draft.debit"
                placeholder="Debit"
                @input="onDebitInput(($event.target as HTMLInputElement).value)"
            >
            <input
                type="number"
                min="0"
                step="0.01"
                class="form-control text-end"
                :disabled="disabled"
                :value="draft.credit"
                placeholder="Credit"
                @input="onCreditInput(($event.target as HTMLInputElement).value)"
            >
            <button type="button" class="btn btn-primary journal-lines__add" :disabled="disabled || !draft.account_id" @click="commitDraft">
                <Plus size="xs" />
                {{ editingIndex === null ? 'Add' : 'Update' }}
            </button>
        </div>

        <div class="journal-lines__footer">
            <span class="journal-lines__total-label">Totals {{ currencySymbol ? `(${currencySymbol})` : '' }}</span>
            <strong class="is-debit">{{ money(totalDebit) }}</strong>
            <strong class="is-credit">{{ money(totalCredit) }}</strong>
            <span class="journal-lines__balance" :class="isBalanced ? 'is-ok' : 'is-off'">
                {{ isBalanced ? 'Balanced' : `Out by ${money(Math.abs(difference))}` }}
            </span>
        </div>
    </div>
</template>
