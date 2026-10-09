<script setup lang="ts">
    import { Head, Link, usePage } from '@inertiajs/vue3';
    import { ArrowLeft, Pencil, Tags } from '@lucide/vue';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'View Lead Source',
            subtitle: 'Lead source details',
            breadcrumbs: [
                {
                    title: 'Lead Sources',
                    href: '/leadsources',
                },
                {
                    title: 'View Lead Source',
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

    type LeadSourceDetail = {
        id?: number;
        name?: string;
        is_active?: boolean | number;
        company?: { name?: string } | null;
    };

    const loading = ref(true);
    const leadSource = ref<LeadSourceDetail>({});

    const permissionPaths = computed(() => (page.props.auth?.user as { permission_paths?: string[] } | null)?.permission_paths ?? []);
    const canEdit = computed(() => permissionPaths.value.includes('/leadsources/:id/edit'));

    async function loadLeadSource() {
        loading.value = true;

        try {
            const response = await window.axios.get(`${API_ENDPOINTS.leadSources}/${routeProps.id}`);
            leadSource.value = response.data;
        } catch {
            Notify('Unable to load lead source details.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    onMounted(loadLeadSource);
</script>

<template>
    <Head title="View Lead Source" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__toolbar d-flex flex-wrap align-items-center justify-content-between gap-2 p-3">
                <Link href="/leadsources" class="btn btn-light btn-sm d-inline-flex align-items-center gap-1">
                    <ArrowLeft class="h-4 w-4" />
                    Back to lead sources
                </Link>

                <Link
                    v-if="canEdit && leadSource.id"
                    :href="`/leadsources/${leadSource.id}/edit`"
                    class="btn btn-primary btn-sm d-inline-flex align-items-center gap-1"
                >
                    <Pencil class="h-4 w-4" />
                    Edit
                </Link>
            </div>

            <div class="admin-list-card__body p-3 p-md-4">
                <Loader v-if="loading" message="Loading lead source details…" />

                <div v-else class="row g-4">
                    <div class="col-12">
                        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                            <div>
                                <p class="text-muted mb-1">{{ leadSource.company?.name || '—' }}</p>
                                <h4 class="mb-0 d-flex align-items-center gap-2">
                                    <Tags class="h-5 w-5" />
                                    {{ leadSource.name || 'Lead Source' }}
                                </h4>
                            </div>
                            <span
                                class="badge"
                                :class="leadSource.is_active ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'"
                            >
                                {{ leadSource.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
