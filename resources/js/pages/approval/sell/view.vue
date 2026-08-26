<script setup lang="ts">
    import Loader from '@/components/Loader.vue';
    import useCommons from '@/composables/common';
    import useSellApprovals from '@/composables/sellApproval';
    import { Head, router, setLayoutProps } from '@inertiajs/vue3';
    import { computed, onMounted, ref } from 'vue';
    import Fields from './Fields.vue';

    const pageProps = defineProps({
        id: {
            required: true,
            type: [String, Number],
        },
        returnTo: {
            type: String,
            default: '/sell/approval',
        },
        listTitle: {
            type: String,
            default: 'Sell Approval',
        },
    });

    setLayoutProps({
        title: 'View Sell',
        subtitle: 'Review customer, items, shipping, and totals before approval',
        breadcrumbs: [
            {
                title: pageProps.listTitle,
                href: pageProps.returnTo,
            },
            {
                title: 'View Sell',
                href: 'NULL',
            },
        ],
    });

    const { formatedText } = useCommons();
    const { viewData, getViewData, approveSell } = useSellApprovals();

    const pageReady = ref(false);
    const isApproving = ref(false);
    const recordId = computed(() => Number(pageProps.id));
    const canApprove = computed(() => Boolean(viewData.value.can_approve));

    function printDocument() {
        const cleanup = () => {
            document.body.classList.remove('is-printing-purchase');
            window.removeEventListener('afterprint', cleanup);
        };

        document.body.classList.add('is-printing-purchase');
        window.addEventListener('afterprint', cleanup);
        window.print();
    }

    async function handleApprove() {
        isApproving.value = true;
        const approved = await approveSell(recordId.value);
        isApproving.value = false;

        if (approved) {
            await getViewData(recordId.value);
        }
    }

    onMounted(async () => {
        pageReady.value = await getViewData(recordId.value);
    });
</script>

<template>
    <Head :title="`View ${formatedText('sell')}`" />

    <div class="product-form-page purchase-approval-page">
        <div class="product-form">
            <Loader v-if="!pageReady" message="Loading sell…" />

            <Fields v-else :sell="viewData" />

            <div class="product-form-page__footer purchase-approval-page__footer">
                <div class="product-form-page__actions">
                    <button
                        type="button"
                        class="btn btn-light"
                        @click="router.visit(pageProps.returnTo)"
                    >
                        Close
                    </button>
                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        :disabled="!pageReady"
                        @click="printDocument"
                    >
                        Print
                    </button>
                    <button
                        type="button"
                        class="btn btn-outline-primary"
                        :disabled="!pageReady"
                        @click="router.visit(`/sell/${recordId}/edit`)"
                    >
                        Edit
                    </button>
                    <button
                        v-if="canApprove"
                        type="button"
                        class="btn btn-primary d-inline-flex align-items-center"
                        :disabled="!pageReady || isApproving"
                        :aria-busy="isApproving"
                        @click="handleApprove"
                    >
                        <span
                            v-if="isApproving"
                            class="spinner-border spinner-border-sm me-1"
                            role="status"
                            aria-hidden="true"
                        ></span>
                        {{ isApproving ? 'Approving…' : 'Approve' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
