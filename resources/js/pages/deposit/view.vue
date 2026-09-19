<script setup lang="ts">
    import { Head, router, setLayoutProps } from '@inertiajs/vue3';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import VoucherApprovalActions from '@/components/VoucherApprovalActions.vue';
    import useCommons from '@/composables/common';
    import useDeposits from '@/composables/deposit';

    const pageProps = defineProps({
        id: {
            required: true,
            type: [String, Number],
        },
        returnTo: {
            type: String,
            default: '/deposit',
        },
        listTitle: {
            type: String,
            default: 'Deposit',
        },
    });

    setLayoutProps({
        title: 'View Deposit',
        subtitle: 'Review deposit details, accounts, and attachments',
        breadcrumbs: [
            {
                title: pageProps.listTitle,
                href: pageProps.returnTo,
            },
            {
                title: 'View Deposit',
                href: 'NULL',
            },
        ],
    });

    const { formatedText } = useCommons();
    const { formData, getEditData } = useDeposits();

    const pageReady = ref(false);
    const recordId = computed(() => Number(pageProps.id));
    const lines = computed(() => Array.isArray(formData.value?.taccountdetails) ? formData.value.taccountdetails : []);
    const attachments = computed(() => Array.isArray(formData.value?.attachments) ? formData.value.attachments : []);
    const totalDebit = computed(() => lines.value.reduce((sum, line) => sum + Number(line.debit || 0), 0));
    const totalCredit = computed(() => lines.value.reduce((sum, line) => sum + Number(line.credit || 0), 0));

    function money(value: number): string {
        return value.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    function statusClass(status?: string): string {
        const value = String(status ?? '').toLowerCase();

        if (value === 'approved') {
            return 'is-success';
        }

        if (value === 'pending') {
            return 'is-warning';
        }

        if (value === 'cancelled' || value === 'rejected') {
            return 'is-danger';
        }

        return 'is-muted';
    }

    function printDocument() {
        const cleanup = () => {
            document.body.classList.remove('is-printing-purchase');
            window.removeEventListener('afterprint', cleanup);
        };

        document.body.classList.add('is-printing-purchase');
        window.addEventListener('afterprint', cleanup);
        window.print();
    }

    async function reload() {
        pageReady.value = await getEditData(recordId.value);
    }

    onMounted(reload);
</script>

<template>
    <Head :title="`View ${formatedText('deposit')}`" />

    <div class="product-form-page purchase-approval-page journal-view-page">
        <div class="product-form">
            <Loader v-if="!pageReady" message="Loading deposit…" />

            <article v-else class="purchase-approval-doc" id="deposit-print">
                <header class="purchase-approval-doc__header">
                    <div>
                        <p class="purchase-approval-doc__eyebrow">Deposit voucher</p>
                        <h2 class="purchase-approval-doc__title">{{ formData.voucher_no || 'Deposit' }}</h2>
                        <p class="purchase-approval-doc__meta">
                            {{ formData.voucher_date || '—' }}
                            <span v-if="formData.cheque_no"> · Ref: {{ formData.cheque_no }}</span>
                            <span v-if="formData.branch_name"> · {{ formData.branch_name }}</span>
                            <span v-if="formData.company_name"> · {{ formData.company_name }}</span>
                        </p>
                        <p v-if="formData.approved_by_name" class="purchase-approval-doc__meta">
                            Approved by {{ formData.approved_by_name }}<span v-if="formData.approved_at"> on {{ formData.approved_at }}</span>
                        </p>
                        <p v-if="formData.rejected_by_name" class="purchase-approval-doc__meta">
                            Rejected by {{ formData.rejected_by_name }}<span v-if="formData.rejected_at"> on {{ formData.rejected_at }}</span>
                        </p>
                    </div>
                    <div class="purchase-approval-doc__badges">
                        <span class="purchase-approval-doc__badge" :class="statusClass(formData.status)">
                            {{ formData.status ? String(formData.status).replace(/^\w/, (c) => c.toUpperCase()) : '—' }}
                        </span>
                    </div>
                </header>

                <section class="receiving-note-facts">
                    <div class="receiving-note-facts__card">
                        <span>Voucher type</span>
                        <strong>{{ formData.voucher_type || '—' }}</strong>
                    </div>
                    <div class="receiving-note-facts__card">
                        <span>Total</span>
                        <strong>{{ money(Number(formData.total_amount || totalDebit)) }}</strong>
                    </div>
                    <div class="receiving-note-facts__card">
                        <span>Lines</span>
                        <strong>{{ lines.length }}</strong>
                    </div>
                </section>

                <div class="table-responsive mt-4">
                    <table class="table journal-view-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Account</th>
                                <th>Description</th>
                                <th class="text-end">Debit</th>
                                <th class="text-end">Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(line, index) in lines" :key="`${line.account_id}-${index}`">
                                <td>{{ index + 1 }}</td>
                                <td>{{ line.code }} — {{ line.account_name }}</td>
                                <td>{{ line.description || '—' }}</td>
                                <td class="text-end">{{ money(Number(line.debit || 0)) }}</td>
                                <td class="text-end">{{ money(Number(line.credit || 0)) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="3" class="text-end">Total</th>
                                <th class="text-end">{{ money(totalDebit) }}</th>
                                <th class="text-end">{{ money(totalCredit) }}</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <p v-if="formData.comments" class="mt-3 mb-0 text-muted">{{ formData.comments }}</p>

                <div v-if="attachments.length" class="mt-4">
                    <h6>Attachments</h6>
                    <ul class="journal-upload__list">
                        <li v-for="file in attachments" :key="file.id || file.file_name">
                            <a v-if="file.data_url" :href="file.data_url" target="_blank" rel="noreferrer">{{ file.file_name }}</a>
                            <span v-else>{{ file.file_name }}</span>
                        </li>
                    </ul>
                </div>
            </article>

            <div class="product-form-page__footer purchase-approval-page__footer">
                <div class="product-form-page__actions">
                    <button type="button" class="btn btn-light" @click="router.visit(pageProps.returnTo)">Close</button>
                    <button type="button" class="btn btn-outline-secondary" :disabled="!pageReady" @click="printDocument">Print</button>
                    <button
                        v-if="formData.status === 'pending'"
                        type="button"
                        class="btn btn-outline-primary"
                        :disabled="!pageReady"
                        @click="router.visit(`/deposit/${recordId}/edit`)"
                    >
                        Edit
                    </button>
                    <VoucherApprovalActions
                        family="deposit"
                        :record-id="recordId"
                        :can-decide="Boolean(formData.can_approve)"
                        :disabled="!pageReady"
                        @decided="reload"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
