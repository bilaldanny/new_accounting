<script setup lang="ts">
    import { Head, Link, usePage } from '@inertiajs/vue3';
    import { ArrowLeft, ListOrdered, Pencil } from '@lucide/vue';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'View Pipeline Stage',
            subtitle: 'Pipeline stage details',
            breadcrumbs: [
                {
                    title: 'Pipeline Stages',
                    href: '/pipelinestages',
                },
                {
                    title: 'View Pipeline Stage',
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

    type PipelineStageDetail = {
        id?: number;
        name?: string;
        sort_order?: number;
        color?: string | null;
        is_won?: boolean | number;
        is_lost?: boolean | number;
        is_active?: boolean | number;
        company?: { name?: string } | null;
    };

    const loading = ref(true);
    const stage = ref<PipelineStageDetail>({});

    const permissionPaths = computed(() => (page.props.auth?.user as { permission_paths?: string[] } | null)?.permission_paths ?? []);
    const canEdit = computed(() => permissionPaths.value.includes('/pipelinestages/:id/edit'));

    async function loadStage() {
        loading.value = true;

        try {
            const response = await window.axios.get(`${API_ENDPOINTS.pipelineStages}/${routeProps.id}`);
            stage.value = response.data;
        } catch {
            Notify('Unable to load pipeline stage details.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    onMounted(loadStage);
</script>

<template>
    <Head title="View Pipeline Stage" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__toolbar d-flex flex-wrap align-items-center justify-content-between gap-2 p-3">
                <Link href="/pipelinestages" class="btn btn-light btn-sm d-inline-flex align-items-center gap-1">
                    <ArrowLeft class="h-4 w-4" />
                    Back to pipeline stages
                </Link>

                <Link
                    v-if="canEdit && stage.id"
                    :href="`/pipelinestages/${stage.id}/edit`"
                    class="btn btn-primary btn-sm d-inline-flex align-items-center gap-1"
                >
                    <Pencil class="h-4 w-4" />
                    Edit
                </Link>
            </div>

            <div class="admin-list-card__body p-3 p-md-4">
                <Loader v-if="loading" message="Loading pipeline stage details…" />

                <div v-else class="row g-4">
                    <div class="col-12">
                        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                            <div>
                                <p class="text-muted mb-1">{{ stage.company?.name || '—' }}</p>
                                <h4 class="mb-0 d-flex align-items-center gap-2">
                                    <ListOrdered class="h-5 w-5" />
                                    {{ stage.name || 'Pipeline Stage' }}
                                </h4>
                            </div>
                            <span
                                class="badge"
                                :class="stage.is_active ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'"
                            >
                                {{ stage.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-3">Board Behavior</h6>
                            <dl class="mb-0 row">
                                <dt class="col-6 text-muted">Position</dt>
                                <dd class="col-6">{{ stage.sort_order ?? 0 }}</dd>
                                <dt class="col-6 text-muted">Marks Won</dt>
                                <dd class="col-6">{{ stage.is_won ? 'Yes' : 'No' }}</dd>
                                <dt class="col-6 text-muted">Marks Lost</dt>
                                <dd class="col-6">{{ stage.is_lost ? 'Yes' : 'No' }}</dd>
                                <dt class="col-6 text-muted">Color</dt>
                                <dd class="col-6">{{ stage.color || '—' }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
