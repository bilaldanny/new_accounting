<script setup lang="ts">
    import { Head, Link, usePage } from '@inertiajs/vue3';
    import { ArrowLeft, ArrowRightCircle, Mail, Pencil, Phone, Tag, User } from '@lucide/vue';
    import { computed, onMounted, ref } from 'vue';
    import ActivityTimeline from '@/components/ActivityTimeline.vue';
    import Loader from '@/components/Loader.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'View Lead',
            subtitle: 'Lead contact details and status',
            breadcrumbs: [
                {
                    title: 'Leads',
                    href: '/leads',
                },
                {
                    title: 'View Lead',
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

    type LeadDetail = {
        id?: number;
        name?: string;
        company_name?: string | null;
        email?: string | null;
        phone?: string | null;
        source?: string | null;
        status?: string;
        notes?: string | null;
        company?: { name?: string } | null;
        assignee?: { first_name?: string; last_name?: string } | null;
        lead_source?: { name?: string } | null;
    };

    const loading = ref(true);
    const lead = ref<LeadDetail>({});

    const permissionPaths = computed(() => (page.props.auth?.user as { permission_paths?: string[] } | null)?.permission_paths ?? []);
    const canEdit = computed(() => permissionPaths.value.includes('/leads/:id/edit'));
    const canAddOpportunity = computed(() => permissionPaths.value.includes('/opportunities/add'));
    const canAddActivity = computed(() => permissionPaths.value.includes('/activities/add'));

    const assigneeName = computed(() => {
        const assignee = lead.value.assignee;

        if (! assignee) {
            return 'Unassigned';
        }

        return [assignee.first_name, assignee.last_name].filter(Boolean).join(' ') || 'Unassigned';
    });

    async function loadLead() {
        loading.value = true;

        try {
            const response = await window.axios.get(`${API_ENDPOINTS.leads}/${routeProps.id}`);
            lead.value = response.data;
        } catch {
            Notify('Unable to load lead details.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    onMounted(loadLead);
</script>

<template>
    <Head title="View Lead" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__toolbar d-flex flex-wrap align-items-center justify-content-between gap-2 p-3">
                <Link href="/leads" class="btn btn-light btn-sm d-inline-flex align-items-center gap-1">
                    <ArrowLeft class="h-4 w-4" />
                    Back to leads
                </Link>

                <div class="d-flex align-items-center gap-2">
                    <Link
                        v-if="canAddOpportunity && lead.id"
                        :href="`/opportunities/add?lead_id=${lead.id}`"
                        class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-1"
                    >
                        <ArrowRightCircle class="h-4 w-4" />
                        Convert to Opportunity
                    </Link>

                    <Link
                        v-if="canEdit && lead.id"
                        :href="`/leads/${lead.id}/edit`"
                        class="btn btn-primary btn-sm d-inline-flex align-items-center gap-1"
                    >
                        <Pencil class="h-4 w-4" />
                        Edit
                    </Link>
                </div>
            </div>

            <div class="admin-list-card__body p-3 p-md-4">
                <Loader v-if="loading" message="Loading lead details…" />

                <div v-else class="row g-4">
                    <div class="col-12">
                        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                            <div>
                                <p class="text-muted mb-1">{{ lead.company_name || lead.company?.name || '—' }}</p>
                                <h4 class="mb-0">{{ lead.name || 'Lead' }}</h4>
                            </div>
                            <span class="badge text-capitalize bg-primary-subtle text-primary">
                                {{ lead.status || 'new' }}
                            </span>
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
                                <dd class="col-7">{{ lead.phone || '—' }}</dd>
                                <dt class="col-5 text-muted d-flex align-items-center gap-1">
                                    <Mail class="h-3.5 w-3.5" /> Email
                                </dt>
                                <dd class="col-7">{{ lead.email || '—' }}</dd>
                            </dl>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-3 d-flex align-items-center gap-2">
                                <Tag class="h-4 w-4" />
                                Source &amp; Assignment
                            </h6>
                            <dl class="mb-0 row">
                                <dt class="col-5 text-muted">Source</dt>
                                <dd class="col-7">{{ lead.lead_source?.name || lead.source || '—' }}</dd>
                                <dt class="col-5 text-muted d-flex align-items-center gap-1">
                                    <User class="h-3.5 w-3.5" /> Assigned To
                                </dt>
                                <dd class="col-7">{{ assigneeName }}</dd>
                            </dl>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-4" v-if="lead.notes">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-3">Notes</h6>
                            <p class="mb-0 text-body">{{ lead.notes }}</p>
                        </div>
                    </div>

                    <div class="col-12">
                        <ActivityTimeline :lead-id="lead.id" :can-add="canAddActivity" />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
