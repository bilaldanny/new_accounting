<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import { onMounted, ref } from 'vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'Duplicate Contacts',
            subtitle: 'Find contacts that share an email, mobile, or business name, and merge them',
            breadcrumbs: [
                {
                    title: 'Duplicate Contacts',
                    href: 'NULL',
                },
            ],
        },
    });

    type DuplicateContact = {
        id: number;
        business_name: string | null;
        email: string | null;
        mobile: string | null;
        user_type: string | null;
        active: boolean;
    };

    type DuplicateGroup = {
        match_field: string;
        match_value: string;
        contacts: DuplicateContact[];
    };

    const { Notify } = useCommons();

    const loading = ref(false);
    const merging = ref<number | null>(null);
    const groups = ref<DuplicateGroup[]>([]);
    const selectedKeep = ref<Record<number, number>>({});

    function groupKey(group: DuplicateGroup, index: number): string {
        return `${group.match_field}:${group.match_value}:${index}`;
    }

    function contactLabel(contact: DuplicateContact): string {
        const type = contact.user_type ? ` (${contact.user_type})` : '';

        return `${contact.business_name || 'Unnamed'}${type}`;
    }

    async function loadDuplicates() {
        loading.value = true;

        try {
            const response = await window.axios.get(API_ENDPOINTS.contactDuplicates);
            groups.value = response.data?.data ?? [];

            groups.value.forEach((group, index) => {
                if (group.contacts.length > 0) {
                    selectedKeep.value[index] = group.contacts[0].id;
                }
            });
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error)) {
                Notify(error.response?.data?.message || 'Failed to load duplicate contacts.', 'alert');
            }
        } finally {
            loading.value = false;
        }
    }

    async function mergeGroup(group: DuplicateGroup, index: number) {
        const keepId = selectedKeep.value[index];

        if (! keepId) {
            Notify('Select which contact to keep first.', 'alert');

            return;
        }

        const duplicates = group.contacts.filter((contact) => contact.id !== keepId);

        if (duplicates.length === 0) {
            return;
        }

        merging.value = index;

        try {
            for (const duplicate of duplicates) {
                await window.axios.post(API_ENDPOINTS.contactDuplicatesMerge, {
                    keep_id: keepId,
                    duplicate_id: duplicate.id,
                });
            }

            Notify('Contacts merged successfully.', 'success');
            await loadDuplicates();
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error)) {
                Notify(error.response?.data?.message || error.response?.data?.errormessage || 'Failed to merge contacts.', 'alert');
            }
        } finally {
            merging.value = null;
        }
    }

    onMounted(loadDuplicates);
</script>

<template>
    <Head title="Duplicate Contacts" />

    <div class="card">
        <div class="card-body">
            <div v-if="loading" class="text-center py-4">
                Loading duplicate candidates…
            </div>

            <div v-else-if="groups.length === 0" class="text-center py-4 text-muted">
                No duplicate contacts found.
            </div>

            <div v-for="(group, index) in groups" :key="groupKey(group, index)" class="mb-4 border rounded p-3">
                <div class="mb-2">
                    <strong>Matched by {{ group.match_field.replace('_', ' ') }}:</strong>
                    {{ group.match_value }}
                </div>

                <table class="table table-sm mb-2">
                    <thead>
                        <tr>
                            <th>Keep</th>
                            <th>Contact</th>
                            <th>Email</th>
                            <th>Mobile</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="contact in group.contacts" :key="contact.id">
                            <td>
                                <input
                                    type="radio"
                                    :name="`keep-${index}`"
                                    :value="contact.id"
                                    v-model="selectedKeep[index]"
                                >
                            </td>
                            <td>{{ contactLabel(contact) }}</td>
                            <td>{{ contact.email || '-' }}</td>
                            <td>{{ contact.mobile || '-' }}</td>
                            <td>{{ contact.active ? 'Active' : 'Inactive' }}</td>
                        </tr>
                    </tbody>
                </table>

                <button
                    type="button"
                    class="btn btn-primary btn-sm"
                    :disabled="merging === index"
                    @click="mergeGroup(group, index)"
                >
                    {{ merging === index ? 'Merging…' : 'Merge into selected contact' }}
                </button>
            </div>
        </div>
    </div>
</template>
