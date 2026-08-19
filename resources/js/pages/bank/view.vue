<script setup lang="ts">
    import Loader from '@/components/Loader.vue';
    import useCommons from '@/composables/common';
    import useBanks from '@/composables/bank';
    import { Head, Link } from '@inertiajs/vue3';
    import {
        ArrowLeft,
        Building,
        Envelope,
        LinkAlt,
        LocationPlus,
        Phone,
        User,
    } from '@boxicons/vue';
    import { computed, onMounted, ref } from 'vue';

    defineOptions({
        layout: {
            title: 'View Bank',
            subtitle: 'Bank profile and account details',
            breadcrumbs: [
                {
                    title: 'Bank Management',
                    href: '/bank',
                },
                {
                    title: 'View Bank',
                    href: 'NULL',
                },
            ],
        },
    });

    const routeProps = defineProps({
        id: {
            required: true,
            type: String,
        },
    });

    const { formatedText, Notify } = useCommons();
    const { linkBankCoa } = useBanks();

    type BankDetail = {
        id?: number | string;
        code?: string;
        bank_name?: string;
        prefix?: string;
        first_name?: string;
        middle_name?: string;
        last_name?: string;
        type?: string;
        mobile?: string;
        alternate_no?: string;
        landline?: string;
        email?: string;
        address?: string;
        landmark?: string;
        gl_id?: string | null;
        link_account?: boolean | number;
        active?: boolean | number;
        country?: { name?: string };
        state?: { name?: string };
        city?: { name?: string };
        company?: { name?: string };
        branch?: { name?: string };
    };

    const loading = ref(true);
    const linking = ref(false);
    const bank = ref<BankDetail>({});

    const displayName = computed(() =>
        [bank.value.prefix, bank.value.first_name, bank.value.middle_name, bank.value.last_name]
            .filter(Boolean)
            .join(' '),
    );

    const locationLine = computed(() =>
        [bank.value.city?.name, bank.value.state?.name, bank.value.country?.name]
            .filter(Boolean)
            .join(', '),
    );

    const isLinked = computed(() => Boolean(bank.value.gl_id));

    async function loadBank() {
        loading.value = true;

        try {
            const response = await window.axios.get(`/api/banks/${routeProps.id}`);
            bank.value = response.data;
        } catch {
            Notify('Unable to load bank details.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    async function handleLinkCoa() {
        if (! bank.value.id || linking.value || isLinked.value) {
            return;
        }

        linking.value = true;

        try {
            const response = await linkBankCoa(bank.value.id);
            bank.value.gl_id = response.gl_id;
            bank.value.link_account = response.link_account;
            Notify(response.message ?? 'Successfully Linked', 'success');
        } catch {
            Notify('Unable to link bank to chart of account.', 'alert');
        } finally {
            linking.value = false;
        }
    }

    onMounted(loadBank);
</script>

<template>
    <Head title="View Bank" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__toolbar d-flex flex-wrap align-items-center justify-content-between gap-2 p-3">
                <Link href="/bank" class="btn btn-light btn-sm d-inline-flex align-items-center gap-1">
                    <ArrowLeft size="xs" />
                    Back to banks
                </Link>

                <button
                    v-if="!loading && !isLinked"
                    type="button"
                    class="btn btn-primary btn-sm d-inline-flex align-items-center gap-1"
                    :disabled="linking"
                    @click="handleLinkCoa"
                >
                    <LinkAlt size="xs" />
                    {{ linking ? 'Linking…' : 'Link to COA' }}
                </button>
            </div>

            <div class="admin-list-card__body p-3 p-md-4">
                <Loader v-if="loading" message="Loading bank details…" />

                <div v-else class="row g-4">
                    <div class="col-12">
                        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                            <div>
                                <p class="text-muted mb-1">{{ bank.code || '—' }}</p>
                                <h4 class="mb-1">{{ bank.bank_name || 'Bank' }}</h4>
                                <p class="text-muted mb-0">{{ displayName || '—' }}</p>
                            </div>
                            <span
                                class="badge"
                                :class="bank.active ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'"
                            >
                                {{ bank.active ? 'Active' : 'Inactive' }}
                            </span>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-3 d-flex align-items-center gap-2">
                                <Building size="sm" />
                                Bank Info
                            </h6>
                            <dl class="mb-0 row">
                                <dt class="col-5 text-muted">Bank Type</dt>
                                <dd class="col-7">{{ formatedText(bank.type || 'local') }}</dd>
                                <dt class="col-5 text-muted">Company</dt>
                                <dd class="col-7">{{ bank.company?.name || '—' }}</dd>
                                <dt class="col-5 text-muted">Branch</dt>
                                <dd class="col-7">{{ bank.branch?.name || '—' }}</dd>
                                <dt class="col-5 text-muted">COA Linked</dt>
                                <dd class="col-7">{{ isLinked ? bank.gl_id : 'No' }}</dd>
                            </dl>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-3 d-flex align-items-center gap-2">
                                <User size="sm" />
                                Contact
                            </h6>
                            <dl class="mb-0 row">
                                <dt class="col-5 text-muted">Mobile</dt>
                                <dd class="col-7 d-flex align-items-center gap-1">
                                    <Phone size="xs" />
                                    {{ bank.mobile || '—' }}
                                </dd>
                                <dt class="col-5 text-muted">Alternate</dt>
                                <dd class="col-7">{{ bank.alternate_no || '—' }}</dd>
                                <dt class="col-5 text-muted">Landline</dt>
                                <dd class="col-7">{{ bank.landline || '—' }}</dd>
                                <dt class="col-5 text-muted">Email</dt>
                                <dd class="col-7 d-flex align-items-center gap-1">
                                    <Envelope size="xs" />
                                    {{ bank.email || '—' }}
                                </dd>
                            </dl>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-3 d-flex align-items-center gap-2">
                                <LocationPlus size="sm" />
                                Location
                            </h6>
                            <dl class="mb-0 row">
                                <dt class="col-5 text-muted">City</dt>
                                <dd class="col-7">{{ locationLine || '—' }}</dd>
                                <dt class="col-5 text-muted">Landmark</dt>
                                <dd class="col-7">{{ bank.landmark || '—' }}</dd>
                                <dt class="col-5 text-muted">Address</dt>
                                <dd class="col-7">{{ bank.address || '—' }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
