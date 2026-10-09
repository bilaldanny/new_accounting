<script setup lang="ts">
    import { Head, Link, usePage } from '@inertiajs/vue3';
    import { ArrowLeft, Calendar, Pencil, Target, User } from '@lucide/vue';
    import { computed, onMounted, ref } from 'vue';
    import ActivityTimeline from '@/components/ActivityTimeline.vue';
    import Loader from '@/components/Loader.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'View Opportunity',
            subtitle: 'Deal details and stage',
            breadcrumbs: [
                {
                    title: 'Opportunities',
                    href: '/opportunities',
                },
                {
                    title: 'View Opportunity',
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

    type OpportunityDetail = {
        id?: number;
        name?: string;
        deal_value?: string | number | null;
        expected_closing_date?: string | null;
        status?: string;
        lost_reason?: string | null;
        notes?: string | null;
        company?: { name?: string } | null;
        lead?: { name?: string; company_name?: string } | null;
        contact?: { first_name?: string; last_name?: string; business_name?: string } | null;
        pipeline_stage?: { name?: string } | null;
        assignee?: { first_name?: string; last_name?: string } | null;
    };

    const loading = ref(true);
    const opportunity = ref<OpportunityDetail>({});

    const permissionPaths = computed(() => (page.props.auth?.user as { permission_paths?: string[] } | null)?.permission_paths ?? []);
    const canEdit = computed(() => permissionPaths.value.includes('/opportunities/:id/edit'));
    const canAddActivity = computed(() => permissionPaths.value.includes('/activities/add'));

    const assigneeName = computed(() => {
        const assignee = opportunity.value.assignee;

        if (! assignee) {
            return 'Unassigned';
        }

        return [assignee.first_name, assignee.last_name].filter(Boolean).join(' ') || 'Unassigned';
    });

    const contactName = computed(() => {
        const contact = opportunity.value.contact;

        if (! contact) {
            return null;
        }

        return contact.business_name || [contact.first_name, contact.last_name].filter(Boolean).join(' ');
    });

    async function loadOpportunity() {
        loading.value = true;

        try {
            const response = await window.axios.get(`${API_ENDPOINTS.opportunities}/${routeProps.id}`);
            opportunity.value = response.data;
        } catch {
            Notify('Unable to load opportunity details.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    onMounted(loadOpportunity);
</script>

<template>
    <Head title="View Opportunity" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__toolbar d-flex flex-wrap align-items-center justify-content-between gap-2 p-3">
                <Link href="/opportunities" class="btn btn-light btn-sm d-inline-flex align-items-center gap-1">
                    <ArrowLeft class="h-4 w-4" />
                    Back to opportunities
                </Link>

                <Link
                    v-if="canEdit && opportunity.id"
                    :href="`/opportunities/${opportunity.id}/edit`"
                    class="btn btn-primary btn-sm d-inline-flex align-items-center gap-1"
                >
                    <Pencil class="h-4 w-4" />
                    Edit
                </Link>
            </div>

            <div class="admin-list-card__body p-3 p-md-4">
                <Loader v-if="loading" message="Loading opportunity details…" />

                <div v-else class="row g-4">
                    <div class="col-12">
                        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                            <div>
                                <p class="text-muted mb-1">{{ opportunity.company?.name || '—' }}</p>
                                <h4 class="mb-0 d-flex align-items-center gap-2">
                                    <Target class="h-5 w-5" />
                                    {{ opportunity.name || 'Opportunity' }}
                                </h4>
                            </div>
                            <span class="badge text-capitalize bg-primary-subtle text-primary">
                                {{ opportunity.status || 'open' }}
                            </span>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-3 d-flex align-items-center gap-2">
                                <Calendar class="h-4 w-4" />
                                Deal
                            </h6>
                            <dl class="mb-0 row">
                                <dt class="col-6 text-muted">Value</dt>
                                <dd class="col-6">{{ opportunity.deal_value ?? 0 }}</dd>
                                <dt class="col-6 text-muted">Expected Close</dt>
                                <dd class="col-6">{{ opportunity.expected_closing_date || '—' }}</dd>
                                <dt class="col-6 text-muted">Stage</dt>
                                <dd class="col-6">{{ opportunity.pipeline_stage?.name || '—' }}</dd>
                            </dl>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-3 d-flex align-items-center gap-2">
                                <User class="h-4 w-4" />
                                Source &amp; Assignment
                            </h6>
                            <dl class="mb-0 row">
                                <dt class="col-6 text-muted">From Lead</dt>
                                <dd class="col-6">{{ opportunity.lead?.name || '—' }}</dd>
                                <dt class="col-6 text-muted">Customer</dt>
                                <dd class="col-6">{{ contactName || '—' }}</dd>
                                <dt class="col-6 text-muted">Assigned To</dt>
                                <dd class="col-6">{{ assigneeName }}</dd>
                            </dl>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-4" v-if="opportunity.lost_reason">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-3">Lost Reason</h6>
                            <p class="mb-0 text-body">{{ opportunity.lost_reason }}</p>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-4" v-if="opportunity.notes">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-3">Notes</h6>
                            <p class="mb-0 text-body">{{ opportunity.notes }}</p>
                        </div>
                    </div>

                    <div class="col-12">
                        <ActivityTimeline :opportunity-id="opportunity.id" :can-add="canAddActivity" />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
