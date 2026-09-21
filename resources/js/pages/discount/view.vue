<script setup lang="ts">
    import { Head, Link, usePage } from '@inertiajs/vue3';
    import { ArrowLeft, CalendarDays, Pencil, Percent, Ticket } from '@lucide/vue';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'View Discount',
            subtitle: 'Discount rule, coupon code and validity',
            breadcrumbs: [
                {
                    title: 'Discount',
                    href: '/discount',
                },
                {
                    title: 'View Discount',
                    href: 'NULL',
                },
            ],
        },
    });

    const routeProps = defineProps({
        id: {
            required: true,
            type: [String, Number],
        },
    });

    const page = usePage();
    const { Notify } = useCommons();

    type DiscountDetail = {
        id?: number;
        name?: string;
        code?: string | null;
        discount_type?: 'percentage' | 'fixed';
        value?: string | number | null;
        min_purchase_amount?: string | number | null;
        max_discount_amount?: string | number | null;
        starts_at?: string | null;
        expires_at?: string | null;
        is_active?: boolean | number;
        company?: { name?: string } | null;
    };

    const loading = ref(true);
    const discount = ref<DiscountDetail>({});

    const permissionPaths = computed(() => (page.props.auth?.user as { permission_paths?: string[] } | null)?.permission_paths ?? []);
    const canEdit = computed(() => permissionPaths.value.includes('/discount/:id/edit'));

    async function loadDiscount() {
        loading.value = true;

        try {
            const response = await window.axios.get(`${API_ENDPOINTS.discounts}/${routeProps.id}`);
            discount.value = response.data;
        } catch {
            Notify('Unable to load discount details.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    onMounted(loadDiscount);
</script>

<template>
    <Head title="View Discount" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__toolbar d-flex flex-wrap align-items-center justify-content-between gap-2 p-3">
                <Link href="/discount" class="btn btn-light btn-sm d-inline-flex align-items-center gap-1">
                    <ArrowLeft class="h-4 w-4" />
                    Back to discounts
                </Link>

                <Link
                    v-if="canEdit && discount.id"
                    :href="`/discount/${discount.id}/edit`"
                    class="btn btn-primary btn-sm d-inline-flex align-items-center gap-1"
                >
                    <Pencil class="h-4 w-4" />
                    Edit
                </Link>
            </div>

            <div class="admin-list-card__body p-3 p-md-4">
                <Loader v-if="loading" message="Loading discount details…" />

                <div v-else class="row g-4">
                    <div class="col-12">
                        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                            <div>
                                <p class="text-muted mb-1">{{ discount.company?.name || '—' }}</p>
                                <h4 class="mb-0">{{ discount.name || 'Discount' }}</h4>
                            </div>
                            <span
                                class="badge"
                                :class="discount.is_active ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'"
                            >
                                {{ discount.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-3 d-flex align-items-center gap-2">
                                <Percent class="h-4 w-4" />
                                Rule
                            </h6>
                            <dl class="mb-0 row">
                                <dt class="col-5 text-muted">Type</dt>
                                <dd class="col-7">{{ discount.discount_type === 'fixed' ? 'Fixed amount' : 'Percentage' }}</dd>
                                <dt class="col-5 text-muted">Value</dt>
                                <dd class="col-7">{{ discount.value ?? '—' }}{{ discount.discount_type === 'percentage' ? ' %' : '' }}</dd>
                                <dt class="col-5 text-muted">Max discount</dt>
                                <dd class="col-7">{{ discount.max_discount_amount ?? 'No cap' }}</dd>
                            </dl>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-3 d-flex align-items-center gap-2">
                                <Ticket class="h-4 w-4" />
                                Conditions
                            </h6>
                            <dl class="mb-0 row">
                                <dt class="col-5 text-muted">Coupon code</dt>
                                <dd class="col-7">{{ discount.code || 'Automatic' }}</dd>
                                <dt class="col-5 text-muted">Min purchase</dt>
                                <dd class="col-7">{{ discount.min_purchase_amount ?? 0 }}</dd>
                            </dl>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-3 d-flex align-items-center gap-2">
                                <CalendarDays class="h-4 w-4" />
                                Validity
                            </h6>
                            <dl class="mb-0 row">
                                <dt class="col-5 text-muted">Valid from</dt>
                                <dd class="col-7">{{ discount.starts_at || '—' }}</dd>
                                <dt class="col-5 text-muted">Expires on</dt>
                                <dd class="col-7">{{ discount.expires_at || 'No expiry' }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
