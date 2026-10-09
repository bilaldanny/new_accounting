<script setup lang="ts">
    import { Head, router, setLayoutProps } from '@inertiajs/vue3';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import useCommons from '@/composables/common';
    import usePurchaseRequisitions from '@/composables/purchaseRequisition';
    import usePurchaseRequisitionApprovals from '@/composables/purchaseRequisitionApproval';

    const pageProps = defineProps({
        id: {
            required: true,
            type: [String, Number],
        },
        returnTo: {
            type: String,
            default: '/purchaserequisition',
        },
        listTitle: {
            type: String,
            default: 'Purchase Requisition',
        },
    });

    setLayoutProps({
        title: 'View Purchase Requisition',
        subtitle: 'Review the requested lines and status',
        breadcrumbs: [
            {
                title: pageProps.listTitle,
                href: pageProps.returnTo,
            },
            {
                title: 'View Purchase Requisition',
                href: 'NULL',
            },
        ],
    });

    const { formatedText } = useCommons();
    const { formData, getEditData } = usePurchaseRequisitions();
    const { approveRequisition, rejectRequisition } = usePurchaseRequisitionApprovals();

    const pageReady = ref(false);
    const isActing = ref(false);
    const recordId = computed(() => Number(pageProps.id));
    const lines = computed(() => Array.isArray(formData.value?.lines) ? formData.value.lines : []);

    function statusClass(status?: string): string {
        const value = String(status ?? '').toLowerCase();

        if (value === 'approved' || value === 'converted') {
            return 'is-success';
        }

        if (value === 'pending' || value === 'draft') {
            return 'is-warning';
        }

        if (value === 'rejected') {
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

    async function handleApprove() {
        isActing.value = true;
        const done = await approveRequisition(recordId.value);
        isActing.value = false;

        if (done) {
            await reload();
        }
    }

    async function handleReject() {
        isActing.value = true;
        const done = await rejectRequisition(recordId.value);
        isActing.value = false;

        if (done) {
            await reload();
        }
    }

    onMounted(reload);
</script>

<template>
    <Head :title="`View ${formatedText('purchaserequisition')}`" />

    <div class="product-form-page purchase-approval-page journal-view-page">
        <div class="product-form">
            <Loader v-if="!pageReady" message="Loading requisition…" />

            <article v-else class="purchase-approval-doc" id="purchaserequisition-print">
                <header class="purchase-approval-doc__header">
                    <div>
                        <p class="purchase-approval-doc__eyebrow">Purchase Requisition</p>
                        <h2 class="purchase-approval-doc__title">{{ formData.requisition_no || 'Requisition' }}</h2>
                        <p class="purchase-approval-doc__meta">
                            {{ formData.requisition_date || '—' }}
                            <span v-if="formData.branch_name"> · {{ formData.branch_name }}</span>
                            <span v-if="formData.company_name"> · {{ formData.company_name }}</span>
                        </p>
                        <p class="purchase-approval-doc__meta">
                            Requested by {{ formData.requested_by_name || '—' }}
                            <span v-if="formData.contact_name"> · Preferred supplier: {{ formData.contact_name }}</span>
                        </p>
                        <p v-if="formData.approved_by_name" class="purchase-approval-doc__meta">
                            Approved by {{ formData.approved_by_name }}<span v-if="formData.approved_at"> on {{ formData.approved_at }}</span>
                        </p>
                        <p v-if="formData.rejected_by_name" class="purchase-approval-doc__meta">
                            Rejected by {{ formData.rejected_by_name }}<span v-if="formData.rejected_at"> on {{ formData.rejected_at }}</span>
                        </p>
                        <p v-if="formData.purchase_order_id" class="purchase-approval-doc__meta">
                            Converted to Purchase Order #{{ formData.purchase_order_id }}
                        </p>
                    </div>
                    <div class="purchase-approval-doc__badges">
                        <span class="purchase-approval-doc__badge" :class="statusClass(formData.status)">
                            {{ formData.status_label || '—' }}
                        </span>
                    </div>
                </header>

                <div class="table-responsive mt-4">
                    <table class="table journal-view-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th>Unit</th>
                                <th class="text-end">Quantity</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(line, index) in lines" :key="`${line.product_id}-${index}`">
                                <td>{{ index + 1 }}</td>
                                <td>{{ line.product_name }} <small v-if="line.sku" class="text-secondary">{{ line.sku }}</small></td>
                                <td>{{ line.unit_name || '—' }}</td>
                                <td class="text-end">{{ line.requested_quantity }}</td>
                                <td>{{ line.note || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-if="formData.note" class="mt-3 mb-0 text-muted">{{ formData.note }}</p>
            </article>

            <div class="product-form-page__footer purchase-approval-page__footer">
                <div class="product-form-page__actions">
                    <button type="button" class="btn btn-light" @click="router.visit(pageProps.returnTo)">Close</button>
                    <button type="button" class="btn btn-outline-secondary" :disabled="!pageReady" @click="printDocument">Print</button>
                    <button
                        v-if="formData.is_editable"
                        type="button"
                        class="btn btn-outline-primary"
                        :disabled="!pageReady"
                        @click="router.visit(`/purchaserequisition/${recordId}/edit`)"
                    >
                        Edit
                    </button>
                    <button
                        v-if="formData.can_approve"
                        type="button"
                        class="btn btn-outline-danger"
                        :disabled="!pageReady || isActing"
                        @click="handleReject"
                    >
                        Reject
                    </button>
                    <button
                        v-if="formData.can_approve"
                        type="button"
                        class="btn btn-primary"
                        :disabled="!pageReady || isActing"
                        @click="handleApprove"
                    >
                        Approve
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
