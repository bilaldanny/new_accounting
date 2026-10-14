<script setup lang="ts">
    import { computed, reactive, watch } from 'vue';
    import TheTable from '@/components/theTable.vue';

    /**
     * TheTable for rows a page already holds in memory (no server list endpoint): the same toolbar, search, column menu, export, header
     * sort, pagination and empty state as the Menus screen, with search, sort and paging done here. A page that needs its own cell (a
     * button, a link, a field) gives a slot named `cell-<column key>` with `{ row }`.
     */
    type Column = { key: string; label: string; type?: string; format?: 'number'; decimals?: number; emptyDisplay?: string; sorting?: 'enabled' | 'disabled' };

    const props = withDefaults(defineProps<{
        columns: Column[];
        rows: Array<Record<string, any>>;
        loading?: boolean;
        pageSize?: number;
        exportName?: string;
        exportTitle?: string;
        emptyText?: string;
    }>(), { loading: false, pageSize: 25, exportName: 'export', exportTitle: undefined, emptyText: '' });

    const state = reactive<any>({
        records: { data: [], total: 0, from: 0, to: 0, current_page: 1, per_page: props.pageSize, last_page: 1 },
        search: { search: '', show_record: props.pageSize, page: 1, sort_by: '', sort_type: 'asc' },
        loading: props.loading,
        edit_ids: [] as number[],
        select: false,
        selectAll: false,
    });

    const sizes = [10, 25, 50, 100];

    function recompute() {
        const term = String(state.search.search ?? '').trim().toLowerCase();
        const keys = props.columns.map((column) => column.key);
        let list = props.rows;

        if (term !== '') {
            list = list.filter((row) => keys.some((key) => String(row[key] ?? '').toLowerCase().includes(term)));
        }

        const sortKey = String(state.search.sort_by ?? '');

        if (sortKey !== '') {
            const direction = state.search.sort_type === 'desc' ? -1 : 1;
            list = [...list].sort((a, b) => {
                const left = a[sortKey];
                const right = b[sortKey];
                const numeric = typeof left === 'number' && typeof right === 'number';

                return (numeric ? left - right : String(left ?? '').localeCompare(String(right ?? ''), undefined, { numeric: true, sensitivity: 'base' })) * direction;
            });
        }

        const size = Number(state.search.show_record) || props.pageSize;
        const last = Math.max(1, Math.ceil(list.length / size));
        const page = Math.min(Math.max(1, Number(state.search.page) || 1), last);
        const from = list.length === 0 ? 0 : (page - 1) * size + 1;

        state.search.page = page;
        state.records = { data: list.slice((page - 1) * size, page * size), total: list.length, from, to: Math.min(page * size, list.length), current_page: page, per_page: size, last_page: last };
    }

    function changeOrder(event: Event) {
        const header = (event.currentTarget as HTMLElement | null) ?? (event.target as HTMLElement).closest('th');
        const key = header?.getAttribute('data-colname') ?? '';

        if (key === '') {
            return;
        }

        state.search.sort_type = state.search.sort_by === key && state.search.sort_type === 'asc' ? 'desc' : 'asc';
        state.search.sort_by = key;
        recompute();
    }

    function onState(next: any) {
        const searchChanged = next.search?.search !== state.search.search;
        const sizeChanged = next.search?.show_record !== state.search.show_record;
        state.search.search = next.search?.search ?? '';
        state.search.show_record = next.search?.show_record ?? state.search.show_record;

        if (searchChanged || sizeChanged) {
            state.search.page = 1;
        }

        recompute();
    }

    watch(() => props.rows, recompute, { deep: false, immediate: true });
    watch(() => props.loading, (value) => {
        state.loading = value;
    });

    const selectable = computed(() => sizes);
</script>

<template>
    <div class="client-table">
        <TheTable
            :columns="columns"
            :state="state"
            :select-data="selectable"
            :get-data="recompute"
            :change-order="changeOrder"
            :sort-by="state.search.sort_by"
            :sort-type="state.search.sort_type"
            show-export
            :export-file-name="exportName"
            :export-title="exportTitle ?? exportName"
            @update:state="onState"
        >
            <template v-for="(_, slotName) in $slots" #[slotName]="slotProps" :key="slotName">
                <slot :name="slotName" v-bind="slotProps" />
            </template>
        </TheTable>
        <p v-if="emptyText && !loading && rows.length === 0" class="text-center text-muted small py-2 mb-0" data-test="client-table-empty">{{ emptyText }}</p>
    </div>
</template>
