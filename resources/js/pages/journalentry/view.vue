<script setup lang="ts">
    import Loader from '@/components/Loader.vue';
    import useCommons from '@/composables/common';
    import useJournalEntries from '@/composables/journalentry';
    import { Head, router, setLayoutProps } from '@inertiajs/vue3';
    import { computed, onMounted, ref } from 'vue';

    const pageProps = defineProps({
        id: {
            required: true,
            type: [String, Number],
        },
    });

    setLayoutProps({
        title: 'View Journal Entry',
        subtitle: 'Review voucher details, lines, and attachments',
        breadcrumbs: [
            {
                title: 'Journal Entry',
                href: '/journalentry',
            },
            {
                title: 'View Journal Entry',
                href: 'NULL',
            },
        ],
    });

    const { formatedText } = useCommons();
    const { formData, getEditData } = useJournalEntries();

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

        if (value === 'cancelled') {
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

    onMounted(async () => {
        pageReady.value = await getEditData(recordId.value);
    });
</script>

<template>
    <Head :title="`View ${formatedText('journalentry')}`" />

    <div class="product-form-page purchase-approval-page journal-view-page">
        <div class="product-form">
            <Loader v-if="!pageReady" message="Loading journal entry…" />

            <article v-else class="purchase-approval-doc" id="journal-entry-print">
                <header class="purchase-approval-doc__header">
                    <div>
                        <p class="purchase-approval-doc__eyebrow">Journal voucher</p>
                        <h2 class="purchase-approval-doc__title">{{ formData.voucher_no || 'Journal entry' }}</h2>
                        <p class="purchase-approval-doc__meta">
                            {{ formData.voucher_date || '—' }}
                            <span v-if="formData.branch_name"> · {{ formData.branch_name }}</span>
                            <span v-if="formData.company_name"> · {{ formData.company_name }}</span>
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
                    <button type="button" class="btn btn-light" @click="router.visit('/journalentry')">Close</button>
                    <button type="button" class="btn btn-outline-secondary" :disabled="!pageReady" @click="printDocument">Print</button>
                    <button
                        v-if="formData.status === 'pending'"
                        type="button"
                        class="btn btn-outline-primary"
                        :disabled="!pageReady"
                        @click="router.visit(`/journalentry/${recordId}/edit`)"
                    >
                        Edit
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
