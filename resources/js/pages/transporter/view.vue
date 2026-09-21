<script setup lang="ts">
    import { Head, Link, usePage } from '@inertiajs/vue3';
    import { ArrowLeft, MapPin, Pencil, Phone, Truck } from '@lucide/vue';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'View Transporter',
            subtitle: 'Transporter contact and vehicle details',
            breadcrumbs: [
                {
                    title: 'Transporter',
                    href: '/transporter',
                },
                {
                    title: 'View Transporter',
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

    type TransporterDetail = {
        id?: number;
        name?: string;
        phone?: string | null;
        address?: string | null;
        vehicle_no?: string | null;
        is_active?: boolean | number;
        company?: { name?: string } | null;
    };

    const loading = ref(true);
    const transporter = ref<TransporterDetail>({});

    const permissionPaths = computed(() => (page.props.auth?.user as { permission_paths?: string[] } | null)?.permission_paths ?? []);
    const canEdit = computed(() => permissionPaths.value.includes('/transporter/:id/edit'));

    async function loadTransporter() {
        loading.value = true;

        try {
            const response = await window.axios.get(`${API_ENDPOINTS.transporters}/${routeProps.id}`);
            transporter.value = response.data;
        } catch {
            Notify('Unable to load transporter details.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    onMounted(loadTransporter);
</script>

<template>
    <Head title="View Transporter" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__toolbar d-flex flex-wrap align-items-center justify-content-between gap-2 p-3">
                <Link href="/transporter" class="btn btn-light btn-sm d-inline-flex align-items-center gap-1">
                    <ArrowLeft class="h-4 w-4" />
                    Back to transporters
                </Link>

                <Link
                    v-if="canEdit && transporter.id"
                    :href="`/transporter/${transporter.id}/edit`"
                    class="btn btn-primary btn-sm d-inline-flex align-items-center gap-1"
                >
                    <Pencil class="h-4 w-4" />
                    Edit
                </Link>
            </div>

            <div class="admin-list-card__body p-3 p-md-4">
                <Loader v-if="loading" message="Loading transporter details…" />

                <div v-else class="row g-4">
                    <div class="col-12">
                        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                            <div>
                                <p class="text-muted mb-1">{{ transporter.company?.name || '—' }}</p>
                                <h4 class="mb-0">{{ transporter.name || 'Transporter' }}</h4>
                            </div>
                            <span
                                class="badge"
                                :class="transporter.is_active ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'"
                            >
                                {{ transporter.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-3 d-flex align-items-center gap-2">
                                <Truck class="h-4 w-4" />
                                Vehicle
                            </h6>
                            <dl class="mb-0 row">
                                <dt class="col-5 text-muted">Vehicle No</dt>
                                <dd class="col-7">{{ transporter.vehicle_no || '—' }}</dd>
                            </dl>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-3 d-flex align-items-center gap-2">
                                <Phone class="h-4 w-4" />
                                Contact
                            </h6>
                            <dl class="mb-0 row">
                                <dt class="col-5 text-muted">Phone</dt>
                                <dd class="col-7">{{ transporter.phone || '—' }}</dd>
                            </dl>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-3 d-flex align-items-center gap-2">
                                <MapPin class="h-4 w-4" />
                                Location
                            </h6>
                            <dl class="mb-0 row">
                                <dt class="col-5 text-muted">Address</dt>
                                <dd class="col-7">{{ transporter.address || '—' }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
