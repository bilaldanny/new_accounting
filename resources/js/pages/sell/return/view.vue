<script setup lang="ts">
    import Loader from '@/components/Loader.vue';
    import useCommons from '@/composables/common';
    import useSellReturns from '@/composables/sellReturn';
    import { Head, router, setLayoutProps } from '@inertiajs/vue3';
    import { computed, onMounted, ref } from 'vue';
    import ViewFields from './ViewFields.vue';

    const pageProps = defineProps({
        id: {
            required: true,
            type: [String, Number],
        },
    });

    setLayoutProps({
        title: 'View Sell Return',
        subtitle: 'Review customer, sell invoice, and returned quantities',
        breadcrumbs: [
            {
                title: 'Sell Return',
                href: '/sell/return',
            },
            {
                title: 'View Sell Return',
                href: 'NULL',
            },
        ],
    });

    const { formatedText } = useCommons();
    const { viewData, getEditData } = useSellReturns();

    const pageReady = ref(false);
    const recordId = computed(() => Number(pageProps.id));

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
    <Head :title="`View ${formatedText('sell.return')}`" />

    <div class="product-form-page purchase-approval-page receiving-note-page">
        <div class="product-form">
            <Loader v-if="!pageReady" message="Loading sell return…" />

            <ViewFields v-else :note="viewData" />

            <div class="product-form-page__footer purchase-approval-page__footer">
                <div class="product-form-page__actions">
                    <button
                        type="button"
                        class="btn btn-light"
                        @click="router.visit('/sell/return')"
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
                        @click="router.visit(`/sell/return/${recordId}/edit`)"
                    >
                        Edit
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
