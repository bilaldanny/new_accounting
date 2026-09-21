<script setup lang="ts">
    import { Head, Link, usePage } from '@inertiajs/vue3';
    import { ArrowLeft, ClipboardList } from '@lucide/vue';
    import { computed, onMounted, ref } from 'vue';
    import Loader from '@/components/Loader.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import useCommons from '@/composables/common';

    defineOptions({
        layout: {
            title: 'View Stock Take',
            subtitle: 'Count the products and complete the sheet',
            breadcrumbs: [
                {
                    title: 'Stock Take',
                    href: '/stocktake',
                },
                {
                    title: 'View Stock Take',
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

    type Line = {
        id: number;
        name?: string | null;
        sku?: string | null;
        unit_name?: string | null;
        system_qty: number;
        counted_qty: number | null;
        difference: number | null;
    };

    type Detail = {
        id?: number;
        reference?: string;
        status?: 'draft' | 'completed';
        count_date?: string;
        note?: string | null;
        company_name?: string | null;
        branch_name?: string | null;
        adjustment_invoice_no?: string | null;
        lines?: Line[];
        summary?: { total_lines: number; counted_lines: number; differing_lines: number };
    };

    const loading = ref(true);
    const saving = ref(false);
    const take = ref<Detail>({});
    const search = ref('');
    const onlyDifferences = ref(false);
    // what the user has typed, by line id; null clears a count
    const draft = ref<Record<number, number | null | ''>>({});

    const permissionPaths = computed(() => (page.props.auth?.user as { permission_paths?: string[] } | null)?.permission_paths ?? []);
    const isDraft = computed(() => take.value.status === 'draft');
    const canCount = computed(() => isDraft.value && permissionPaths.value.includes('/stocktake/count'));
    const canComplete = computed(() => isDraft.value && permissionPaths.value.includes('/stocktake/complete'));
    const dirtyCount = computed(() => Object.keys(draft.value).length);

    function currentCount(line: Line): number | null | '' {
        return line.id in draft.value ? draft.value[line.id] : line.counted_qty;
    }

    function currentDifference(line: Line): number | null {
        const value = currentCount(line);

        return value === null || value === '' ? null : Math.round((Number(value) - line.system_qty) * 100) / 100;
    }

    const visibleLines = computed(() => (take.value.lines ?? []).filter((line) => {
        const term = search.value.trim().toLowerCase();
        const matches = ! term || `${line.name ?? ''} ${line.sku ?? ''}`.toLowerCase().includes(term);
        const difference = currentDifference(line);

        return matches && (! onlyDifferences.value || (difference !== null && Math.abs(difference) >= 0.01));
    }));

    function setCount(line: Line, event: Event) {
        const raw = (event.target as HTMLInputElement).value;

        draft.value = { ...draft.value, [line.id]: raw === '' ? null : Number(raw) };
    }

    function errorMessage(error: unknown): string {
        if (! window.axios.isAxiosError(error)) {
            return 'Unexpected error occurred';
        }

        const errors = Object.values(error.response?.data?.errors ?? {}).flat() as string[];

        return errors[0] || error.response?.data?.message || error.response?.data?.errormessage || 'The request failed.';
    }

    async function loadTake() {
        loading.value = true;

        try {
            const response = await window.axios.get(`${API_ENDPOINTS.stockTakes}/${routeProps.id}`);
            take.value = response.data;
            draft.value = {};
        } catch {
            Notify('Unable to load the stock take.', 'alert');
        } finally {
            loading.value = false;
        }
    }

    async function saveCounts(): Promise<boolean> {
        if (! dirtyCount.value) {
            return true;
        }

        saving.value = true;

        try {
            await window.axios.put(`${API_ENDPOINTS.stockTakes}/${routeProps.id}/counts`, { counts: draft.value });
            await loadTake();

            return true;
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');

            return false;
        } finally {
            saving.value = false;
        }
    }

    async function save() {
        if (await saveCounts()) {
            Notify('Counts saved', 'success');
        }
    }

    async function complete() {
        if (! window.confirm('Complete this stock take? A stock adjustment will be created for the differences. This cannot be undone.')) {
            return;
        }

        if (! await saveCounts()) {
            return;
        }

        saving.value = true;

        try {
            const response = await window.axios.post(`${API_ENDPOINTS.stockTakes}/${routeProps.id}/complete`);
            Notify(response.data?.message || 'Successfully Saved', 'success');
            await loadTake();
        } catch (error: unknown) {
            Notify(errorMessage(error), 'alert');
        } finally {
            saving.value = false;
        }
    }

    onMounted(loadTake);
</script>

<template>
    <Head title="View Stock Take" />

    <div class="admin-list-page">
        <div class="admin-list-card">
            <div class="admin-list-card__toolbar d-flex flex-wrap align-items-center justify-content-between gap-2 p-3">
                <Link href="/stocktake" class="btn btn-light btn-sm d-inline-flex align-items-center gap-1">
                    <ArrowLeft class="h-4 w-4" />
                    Back to stock takes
                </Link>

                <div v-if="canCount || canComplete" class="d-flex gap-2">
                    <button v-if="canCount" type="button" class="btn btn-outline-primary btn-sm" :disabled="saving || ! dirtyCount" @click="save">
                        Save counts
                    </button>
                    <button v-if="canComplete" type="button" class="btn btn-primary btn-sm" :disabled="saving" @click="complete">
                        Complete stock take
                    </button>
                </div>
            </div>

            <div class="admin-list-card__body p-3 p-md-4">
                <Loader v-if="loading" message="Loading stock take…" />

                <div v-else class="row g-4">
                    <div class="col-12">
                        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                            <div>
                                <p class="text-muted mb-1">{{ take.company_name || '—' }} · {{ take.branch_name || '—' }}</p>
                                <h4 class="mb-0 d-flex align-items-center gap-2">
                                    <ClipboardList class="h-5 w-5" />
                                    {{ take.reference }}
                                </h4>
                                <p v-if="take.note" class="text-muted mb-0">{{ take.note }}</p>
                            </div>
                            <span
                                class="badge"
                                :class="take.status === 'completed' ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning'"
                            >
                                {{ take.status === 'completed' ? 'Completed' : 'Draft' }}
                            </span>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="row g-3">
                            <div class="col-6 col-md-3">
                                <div class="border rounded p-3">
                                    <div class="text-muted small">Count date</div>
                                    <div class="fw-semibold">{{ take.count_date }}</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="border rounded p-3">
                                    <div class="text-muted small">Products</div>
                                    <div class="fw-semibold">{{ take.summary?.total_lines ?? 0 }}</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="border rounded p-3">
                                    <div class="text-muted small">Counted</div>
                                    <div class="fw-semibold">{{ take.summary?.counted_lines ?? 0 }}</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="border rounded p-3">
                                    <div class="text-muted small">With a difference</div>
                                    <div class="fw-semibold">{{ take.summary?.differing_lines ?? 0 }}</div>
                                </div>
                            </div>
                        </div>
                        <p v-if="take.adjustment_invoice_no" class="text-muted small mt-2 mb-0">
                            Stock adjustment created: {{ take.adjustment_invoice_no }}
                        </p>
                        <p v-else-if="take.status === 'completed'" class="text-muted small mt-2 mb-0">
                            Every counted product matched the system, so no stock adjustment was needed.
                        </p>
                    </div>

                    <div class="col-12">
                        <div class="row g-2 align-items-end mb-3">
                            <div class="col-md-4">
                                <label class="form-label" for="stocktake-search">Search</label>
                                <input id="stocktake-search" v-model="search" type="search" class="form-control form-control-sm" placeholder="Product or SKU">
                            </div>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input id="stocktake-differences" v-model="onlyDifferences" type="checkbox" class="form-check-input">
                                    <label class="form-check-label" for="stocktake-differences">Only lines with a difference</label>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>SKU</th>
                                        <th>Unit</th>
                                        <th class="text-end">System</th>
                                        <th class="text-end" style="width: 9rem">Counted</th>
                                        <th class="text-end">Difference</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="line in visibleLines" :key="line.id">
                                        <td>{{ line.name || '—' }}</td>
                                        <td>{{ line.sku || '—' }}</td>
                                        <td>{{ line.unit_name || '—' }}</td>
                                        <td class="text-end">{{ line.system_qty }}</td>
                                        <td class="text-end">
                                            <input
                                                v-if="canCount"
                                                :value="currentCount(line) ?? ''"
                                                type="number"
                                                min="0"
                                                step="any"
                                                class="form-control form-control-sm text-end"
                                                :aria-label="`Counted quantity of ${line.name ?? 'product'}`"
                                                @input="setCount(line, $event)"
                                            >
                                            <span v-else>{{ line.counted_qty ?? '—' }}</span>
                                        </td>
                                        <td
                                            class="text-end"
                                            :class="{
                                                'text-danger': (currentDifference(line) ?? 0) < 0,
                                                'text-success': (currentDifference(line) ?? 0) > 0,
                                            }"
                                        >
                                            {{ currentDifference(line) === null ? '—' : ((currentDifference(line) as number) > 0 ? '+' : '') + currentDifference(line) }}
                                        </td>
                                    </tr>
                                    <tr v-if="! visibleLines.length">
                                        <td colspan="6" class="text-center text-muted">No products to show.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
