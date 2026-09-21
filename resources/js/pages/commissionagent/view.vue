<script setup lang="ts">
    import { Head, Link, usePage } from '@inertiajs/vue3';
    import { ArrowLeft, Pencil, Percent, Phone } from '@lucide/vue';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'View Commission Agent',
            subtitle: 'Sales commission agent contact and rate',
            breadcrumbs: [
                {
                    title: 'Commission Agent',
                    href: '/commissionagent',
                },
                {
                    title: 'View Commission Agent',
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

    type CommissionAgentDetail = {
        id?: number;
        name?: string;
        phone?: string | null;
        commission_percent?: string | number | null;
        is_active?: boolean | number;
        company?: { name?: string } | null;
    };

    const loading = ref(true);
    const agent = ref<CommissionAgentDetail>({});

    const permissionPaths = computed(() => (page.props.auth?.user as { permission_paths?: string[] } | null)?.permission_paths ?? []);
    const canEdit = computed(() => permissionPaths.value.includes('/commissionagent/:id/edit'));

    async function loadAgent() {
        loading.value = true;

        try {
            const response = await window.axios.get(`${API_ENDPOINTS.commissionAgents}/${routeProps.id}`);
            agent.value = response.data;
        } catch {
            Notify('Unable to load commission agent details.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    onMounted(loadAgent);
</script>

<template>
    <Head title="View Commission Agent" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__toolbar d-flex flex-wrap align-items-center justify-content-between gap-2 p-3">
                <Link href="/commissionagent" class="btn btn-light btn-sm d-inline-flex align-items-center gap-1">
                    <ArrowLeft class="h-4 w-4" />
                    Back to commission agents
                </Link>

                <Link
                    v-if="canEdit && agent.id"
                    :href="`/commissionagent/${agent.id}/edit`"
                    class="btn btn-primary btn-sm d-inline-flex align-items-center gap-1"
                >
                    <Pencil class="h-4 w-4" />
                    Edit
                </Link>
            </div>

            <div class="admin-list-card__body p-3 p-md-4">
                <Loader v-if="loading" message="Loading commission agent details…" />

                <div v-else class="row g-4">
                    <div class="col-12">
                        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                            <div>
                                <p class="text-muted mb-1">{{ agent.company?.name || '—' }}</p>
                                <h4 class="mb-0">{{ agent.name || 'Commission Agent' }}</h4>
                            </div>
                            <span
                                class="badge"
                                :class="agent.is_active ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'"
                            >
                                {{ agent.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-3 d-flex align-items-center gap-2">
                                <Percent class="h-4 w-4" />
                                Commission
                            </h6>
                            <dl class="mb-0 row">
                                <dt class="col-5 text-muted">Rate</dt>
                                <dd class="col-7">{{ agent.commission_percent ?? 0 }}%</dd>
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
                                <dd class="col-7">{{ agent.phone || '—' }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
