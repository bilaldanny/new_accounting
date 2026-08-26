<script setup lang="ts">
    import Loader from '@/components/Loader.vue';
    import useCommons from '@/composables/common';
    import useSells from '@/composables/sell';
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
        title: 'View Sell',
        subtitle: 'Review customer, items, shipping, and invoice totals',
        breadcrumbs: [
            {
                title: 'Sell Management',
                href: '/sell',
            },
            {
                title: 'View Sell',
                href: 'NULL',
            },
        ],
    });

    const { formatedText } = useCommons();
    const { viewData, getEditData } = useSells();

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

        if (! pageReady.value) {
            router.visit('/sell');
        }
    });
</script>

<template>
    <Head :title="`View ${formatedText('sell')}`" />

    <div class="product-form-page purchase-approval-page receiving-note-page">
        <div class="product-form">
            <Loader v-if="!pageReady" message="Loading sell…" />

            <ViewFields v-else :note="viewData" />

            <div class="product-form-page__footer purchase-approval-page__footer">
                <div class="product-form-page__actions">
                    <button
                        type="button"
                        class="btn btn-light"
                        @click="router.visit('/sell')"
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
                </div>
            </div>
        </div>
    </div>
</template>
