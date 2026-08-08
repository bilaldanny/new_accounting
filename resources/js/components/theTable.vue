<script setup lang="ts">

import { Link, usePage } from '@inertiajs/vue3';
import { DotsVerticalRounded } from '@boxicons/vue';
import debounce from '@/utils/debounce';
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick, reactive } from 'vue'
import { useFloating, offset, flip, shift, autoPlacement, autoUpdate, arrow, computePosition } from '@floating-ui/vue'
import SkeletonTableRows from '@/components/skeleton/SkeletonTableRows.vue';

    interface Column {
        key: string;
        label: string;
        type?: 'checkbox' | 'count' | 'badge' | 'action' | 'external_link' | 'certificate_download' | string;
        sorting?: 'enabled' | 'disabled';
        actions?: string[];
        data_column?: string;
        /** When `type` is `external_link`, show this label instead of repeating the URL */
        external_link_label?: string;
        /** When `external_link`, render as a small primary button instead of a plain link */
        external_link_variant?: 'link' | 'button';
        /** Replace null / undefined / empty string with this (e.g. "0"); default cell fallback stays "-" otherwise */
        emptyDisplay?: string;
    }
    // 🔹 Refs
    const referenceRefs = reactive({})
    const floatingRefs = reactive({})
    const arrowRefs = reactive({})

    // 🔹 State
    const openRow = ref(null)
    const cleanupAutoUpdates = reactive({})
    const floatingPositions = reactive({})
    const arrowPositions = reactive({})

    const tableData = defineProps<{
        actionType?: string;
        columns: Column[];
        sortBy?: string;
        sortType?: string;
        ajax?: string;
        state?: any;
        selectData?: any[];
        checkAll?: (id?: number) => void;
        getData?: () => void;
        changeOrder?: (event: Event) => void;
        changeStatus?: (ids: number[], status?: any) => void;
        edit?: (id: number) => void;
        view?: (id: number) => void;
        duplicate?: (id: number) => void;
        delete?: (ids: number[]) => void;
        restore?: (ids: number[]) => void;
        approve?: (id: number) => void;
        reject?: (id: number) => void;
        requeue?: (id: number) => void;
        apiUrl?: string;
        viewRoute?: (id: number) => string;
        /** Show export buttons (Excel, CSV, PDF, clipboard) */
        showExport?: boolean;
        /** Base name for downloaded files (without extension) */
        exportFileName?: string;
        /** Title shown at the top of PDF exports */
        exportTitle?: string;
        /**
         * When set, exports use all rows returned here (e.g. full list with current filters).
         * When omitted, only the current page (`state.records.data`) is exported.
         */
        exportAllRows?: () => Promise<Record<string, unknown>[]>;
        downloadInvoice?: (id: number) => void | Promise<void>;
        /** When set to a row id, invoice action shows loading for that row (PDF download in progress). */
        invoiceDownloadingId?: number | null;
        /** Same certificate download pattern as enrolled courses (blob + programmatic &lt;a download&gt;). */
        certificateDownload?: (row: Record<string, unknown>) => void | Promise<void>;
        certificateDownloadRowBusy?: (row: Record<string, unknown>) => boolean;
    }>();

    const exportBaseName = computed(
        () => tableData.exportFileName?.replace(/\.[^/.]+$/, '') || 'export',
    );

    const exportPdfTitle = computed(() => tableData.exportTitle || exportBaseName.value);

    function defaultCellDisplay(row: Record<string, unknown>, col: Column): unknown {
        const key = col.data_column ?? col.key;
        const raw = row[key];
        if (col.emptyDisplay !== undefined && (raw === null || raw === undefined || raw === '')) {
            return col.emptyDisplay;
        }
        return raw ?? '-';
    }

    function cellExportValue(row: Record<string, unknown>, col: Column): string {
        const key = col.data_column ?? col.key;
        const v = row[key];
        if (v === null || v === undefined || v === '') {
            if (col.emptyDisplay !== undefined) {
                return String(col.emptyDisplay);
            }
            return '';
        }
        if (typeof v === 'boolean') return v ? 'Yes' : 'No';
        if (typeof v === 'object') {
            try {
                return JSON.stringify(v);
            } catch {
                return '';
            }
        }
        return String(v);
    }

    function buildExportMatrixFromRecords(recordRows: Record<string, unknown>[]) {
        const cols = exportableColumns.value;
        const headers = cols.map((c) => c.label || c.key);
        const rows = recordRows.map((row) => cols.map((c) => cellExportValue(row, c)));
        return { headers, rows };
    }

    async function loadRecordsForExport(): Promise<Record<string, unknown>[]> {
        if (tableData.exportAllRows) {
            return await tableData.exportAllRows();
        }
        return (tableData.state?.records?.data ?? []) as Record<string, unknown>[];
    }

    /** Row IDs from checkbox column (`state.edit_ids`); export uses only these when non-empty. */
    const exportSelectedIds = computed(() => {
        const raw = tableData.state?.edit_ids ?? [];
        const ids = raw
            .map((x: unknown) => Number(x))
            .filter((n: number) => Number.isFinite(n) && n > 0);
        return [...new Set(ids)];
    });

    const hasExportRowSelection = computed(() => exportSelectedIds.value.length > 0);

    function filterRecordsByExportSelection(recordRows: Record<string, unknown>[]): Record<string, unknown>[] {
        if (!hasExportRowSelection.value) return recordRows;
        const idSet = new Set(exportSelectedIds.value);
        return recordRows.filter((row) => idSet.has(Number(row.id)));
    }

    async function buildExportMatrix(): Promise<{ headers: string[]; rows: string[][] }> {
        const raw = await loadRecordsForExport();
        const filtered = filterRecordsByExportSelection(raw);
        return buildExportMatrixFromRecords(filtered);
    }

    const exportBusy = ref(false);
    const exportError = ref<string | null>(null);

    const exportActionsDisabled = computed(() => {
        if (exportBusy.value) return true;
        if (hasExportRowSelection.value) return false;
        if (tableData.exportAllRows) {
            const total = Number(tableData.state?.records?.total) || 0;
            if (total > 0) return false;
            return !(tableData.state?.records?.data?.length);
        }
        return !tableData.state?.records?.data?.length;
    });

    const exportHint = computed(() => {
        if (hasExportRowSelection.value) {
            const n = exportSelectedIds.value.length;
            return n === 1 ? 'Exporting 1 selected row' : `Exporting ${n} selected rows`;
        }
        if (tableData.exportAllRows) {
            return 'All rows matching search, sort, and filters';
        }
        return 'Current page rows only';
    });

    async function exportExcel() {
        exportBusy.value = true;
        exportError.value = null;
        try {
            const XLSX = await import('xlsx');
            const { headers, rows } = await buildExportMatrix();
            if (!rows.length) {
                exportError.value = 'No rows to export.';
                return;
            }
            const aoa = [headers, ...rows];
            const ws = XLSX.utils.aoa_to_sheet(aoa);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Export');
            XLSX.writeFile(wb, `${exportBaseName.value}.xlsx`);
        } catch (e) {
            console.error(e);
            exportError.value = 'Excel export failed.';
        } finally {
            exportBusy.value = false;
        }
    }

    async function exportCopyClipboard() {
        exportBusy.value = true;
        exportError.value = null;
        try {
            const { headers, rows } = await buildExportMatrix();
            if (!rows.length) {
                exportError.value = 'No rows to export.';
                return;
            }
            const lines = [
                headers.join('\t'),
                ...rows.map((r) => r.map((c) => c.replace(/\t/g, ' ').replace(/\n/g, ' ')).join('\t')),
            ];
            const text = lines.join('\n');
            await navigator.clipboard.writeText(text);
        } catch (e) {
            if (e instanceof Error && e.name === 'NotAllowedError') {
                exportError.value = 'Could not copy (clipboard permission denied).';
            } else {
                console.error(e);
                exportError.value = 'Copy failed.';
            }
        } finally {
            exportBusy.value = false;
        }
    }

    async function exportCsv() {
        exportBusy.value = true;
        exportError.value = null;
        try {
            const { headers, rows } = await buildExportMatrix();
            if (!rows.length) {
                exportError.value = 'No rows to export.';
                return;
            }
            const esc = (v: string) => {
                const s = v.replace(/"/g, '""');
                return `"${s}"`;
            };
            const lines = [
                headers.map(esc).join(','),
                ...rows.map((r) => r.map(esc).join(',')),
            ];
            const bom = '\uFEFF';
            const blob = new Blob([bom + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `${exportBaseName.value}.csv`;
            a.click();
            URL.revokeObjectURL(url);
        } catch (e) {
            console.error(e);
            exportError.value = 'CSV export failed.';
        } finally {
            exportBusy.value = false;
        }
    }

    async function exportPdf() {
        exportBusy.value = true;
        exportError.value = null;
        try {
            const [{ default: jsPDF }, { default: autoTable }] = await Promise.all([
                import('jspdf'),
                import('jspdf-autotable'),
            ]);
            const { headers, rows } = await buildExportMatrix();
            if (!rows.length) {
                exportError.value = 'No rows to export.';
                return;
            }
            const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
            doc.setFontSize(12);
            doc.text(exportPdfTitle.value, 14, 12);
            autoTable(doc, {
                startY: 16,
                head: [headers],
                body: rows,
                styles: { fontSize: 7, cellPadding: 1.5 },
                headStyles: { fillColor: [52, 73, 94] },
                margin: { left: 10, right: 10 },
            });
            doc.save(`${exportBaseName.value}.pdf`);
        } catch (e) {
            console.error(e);
            exportError.value = 'PDF export failed.';
        } finally {
            exportBusy.value = false;
        }
    }

    // Agar parent me update chahiye, to emit karo
    const emit = defineEmits(['update:state']);

    const { props } = usePage();

    function canUseInvoiceAction(apiUrl: string | undefined): boolean {
        if (!apiUrl) {
            return false;
        }
        const authUser = (props as { auth?: { user?: { permission_paths?: string[]; rolename?: string } } }).auth?.user;
        const paths = authUser?.permission_paths ?? [];
        if (authUser?.rolename === 'Super Admin') {
            return true;
        }
        return (
            paths.includes(`/${apiUrl}/:id/invoice`) ||
            paths.includes(`/${apiUrl}/:id/view`)
        );
    }

    /**
     * Drops the action column when none of its entries would render (same rules as row action v-if).
     */
    const displayColumns = computed((): Column[] => {
        const cols = tableData.columns;
        const apiUrl = tableData.apiUrl;
        const paths = (props as { auth?: { user?: { permission_paths?: string[] } } }).auth?.user?.permission_paths ?? [];

        return cols.filter((col) => {
            if (col.type !== 'action') {
                return true;
            }
            if (!col.actions?.length) {
                return false;
            }
            if (!apiUrl) {
                return true;
            }

            return col.actions.some((action) => {
                switch (action) {
                    case 'edit':
                        return paths.includes(`/${apiUrl}/:id/edit`);
                    case 'view':
                        return (
                            paths.includes(`/${apiUrl}/:id/view`) &&
                            (!!tableData.viewRoute || tableData.actionType === 'modal')
                        );
                    case 'duplicate':
                        return paths.includes(`/${apiUrl}/add`);
                    case 'restore':
                        return paths.includes(`/${apiUrl}/restore`);
                    case 'delete':
                        return paths.includes(`/${apiUrl}/delete`);
                    case 'permission':
                        return paths.includes(`/${apiUrl}/:id/permission`);
                    case 'approve':
                    case 'reject':
                        return true;
                    case 'invoice':
                        return canUseInvoiceAction(apiUrl);
                    default:
                        return true;
                }
            });
        });
    });

    const exportableColumns = computed(() =>
        displayColumns.value.filter(
            (c) => !['checkbox', 'action', 'count', 'certificate_download'].includes(c.type ?? ''),
        ),
    );

    /** Export UI only when parent enables it and user has export permission for this table route. */
    const showExportToolbar = computed(() => {
        if (!tableData.showExport) {
            return false;
        }
        const apiUrl = tableData.apiUrl;
        if (!apiUrl) {
            return false;
        }
        const paths = (props as { auth?: { user?: { permission_paths?: string[] } } }).auth?.user?.permission_paths ?? [];
        return paths.includes(`/${apiUrl}/export`);
    });

    const skeletonRowCount = computed(() => {
        const n = Number(tableData.state?.search?.show_record);
        const v = Number.isFinite(n) && n > 0 ? n : 10;
        return Math.min(v, 15);
    });

    /**
     * z-vue-pagination does Math.ceil(total / itemsPerPage). If itemsPerPage is 0 / NaN, totalPages
     * becomes Infinity and a button renders the text "Infinity". Always pass a sane positive integer.
     */
    const paginationPageSize = computed(() => {
        const raw = tableData.state?.search?.show_record;
        const n = Number(raw);
        if (Number.isFinite(n) && n > 0) {
            return Math.min(500, Math.floor(n));
        }
        return 10;
    });

    const paginationTotalItems = computed(() => {
        const n = Number(tableData.state?.records?.total);
        if (Number.isFinite(n) && n >= 0) {
            return n;
        }
        return 0;
    });

    /** More reliable than Laravel last_page when client state briefly desyncs. */
    const showPaginationBar = computed(
        () => paginationTotalItems.value > paginationPageSize.value,
    );

    /** href for `external_link` columns: keeps mailto/tel/http, adds https for bare domains, preserves paths. */
    function formatExternalHref(raw: unknown): string {
        const s = raw == null ? '' : String(raw).trim();
        if (!s || s === '-') {
            return '#';
        }
        if (/^[a-z][a-z0-9+.-]*:/i.test(s)) {
            return s;
        }
        if (s.startsWith('//')) {
            return `https:${s}`;
        }
        if (s.startsWith('/')) {
            return s;
        }
        return `https://${s}`;
    }

    // Debounce function (wait 500ms after user stops typing)
    const debouncedSearch = debounce(() => {
        tableData.getData?.();
    }, 500)

    watch(
        [
            () => tableData.state?.search?.search,
            () => tableData.state?.search?.show_record,
            () => tableData.state?.search?.status,
        ],
        () => {
            debouncedSearch();
        },
    )

    const getStatus = (event: Event) => {
        const value = (event.target as HTMLElement).getAttribute("data-value");
        emit('update:state', {
            ...tableData.state,
            search: { ...tableData.state?.search, status: value }
        });
    };

    const localSearch = ref(tableData.state?.search?.search ?? '')
    const localShowRecord = ref(tableData.state?.search?.show_record ?? '')

    watch(localSearch, (val) => {
        emit('update:state', {
            ...tableData.state,
            search: { ...tableData.state.search, search: val }
        })
    })

    watch(localShowRecord, (val) => {
        emit('update:state', {
            ...tableData.state,
            search: { ...tableData.state.search, show_record: val }
        })
    })

    watch(
        () => tableData.state.search?.search,
            (newVal) => {
                if (newVal !== localSearch.value) localSearch.value = newVal
            }
    )

    watch(
        () => tableData.state.search?.show_record,
            (newVal) => {
                if (newVal !== localShowRecord.value) localShowRecord.value = newVal
            }
    )

    // 🔹 Computed tooltip styles
    const floatingStyles = computed(() => {
        const styles = {}
        for (const id in floatingPositions) {
            const pos = floatingPositions[id]
            styles[id] = {
            position: pos?.strategy ?? 'absolute',
            top: pos?.y != null ? `${pos.y}px` : '',
            left: pos?.x != null ? `${pos.x}px` : '',
            zIndex: 1055,
            }
        }
        return styles
    })

    // 🔹 Computed arrow styles
    const arrowStyles = computed(() => {
        const styles = {}
        for (const id in arrowPositions) {
            const { x, y, placement } = arrowPositions[id] || {}
            const side = placement?.split('-')[0]
            const staticSide = {
            top: 'bottom',
            right: 'left',
            bottom: 'top',
            left: 'right',
            }[side]

            styles[id] = {
            left: x != null ? `${x}px` : '',
            top: y != null ? `${y}px` : '',
            [staticSide]: '-7px',
            }

            styles[id]['background-color'] = 'inherit';

            if(placement?.split('-')[0] === 'bottom'){
                styles[id]['border-bottom'] = 0;
                styles[id]['border-right'] = 0;
                styles[id]['background-color'] = 'rgb(248 248 249)';
            }

            if(placement?.split('-')[0] === 'top'){
                styles[id]['border-top'] = 0;
                styles[id]['border-left'] = 0;
            }
        }
        return styles
    })

    // 🔹 Toggle tooltip
    const toggle = async (id) => {
        if (openRow.value === id) {
            closePopup(id)
            return
        }

        if (openRow.value) closePopup(openRow.value)

        openRow.value = id
        await nextTick()

        const refEl = referenceRefs[id]
        const floatEl = floatingRefs[id]
        const arrowEl = arrowRefs[id]

        if (!(refEl instanceof HTMLElement && floatEl instanceof HTMLElement)) return

        const updatePosition = async () => {
            const { x, y, strategy, placement, middlewareData } = await computePosition(refEl, floatEl, {
            placement: 'bottom-start',
            middleware: [
                offset(0),
                flip(),
                shift(),
                arrow({ element: arrowEl }),
            ],
            })

            floatingPositions[id] = { x, y, strategy }
            arrowPositions[id] = {
            x: middlewareData.arrow?.x,
            y: middlewareData.arrow?.y,
            placement,
            }
        }

        await updatePosition()

        cleanupAutoUpdates[id] = autoUpdate(refEl, floatEl, updatePosition)
    }

    // 🔹 Close popup
    const closePopup = (id) => {
        if (cleanupAutoUpdates[id]) {
            cleanupAutoUpdates[id]()
            delete cleanupAutoUpdates[id]
        }
        if (openRow.value === id) openRow.value = null
    }

    // 🔹 Close when clicking outside
    const handleClickOutside = (e) => {
        const openId = openRow.value
        if (!openId) return

        const refEl = referenceRefs[openId]
        const floatEl = floatingRefs[openId]

        if (
            refEl instanceof HTMLElement &&
            floatEl instanceof HTMLElement &&
            !refEl.contains(e.target) &&
            !floatEl.contains(e.target)
        ) {
            closePopup(openId)
        }
    }

    /* OnMounted */
    onMounted(() => {
        document.addEventListener('click', handleClickOutside)
    });

    onBeforeUnmount(() => {
        document.removeEventListener('click', handleClickOutside)
    })

</script>

<template>
    <div class="table-responsive modern-table">
        <div class="dataTables_wrapper dt-bootstrap5">
            <div class="row">
                <div class="col-sm-12 col-md-6">
                    <!-- Show Record -->
                    <div class="dataTables_length" id="example_length">
                        <label>
                            Show 
                            <select 
                                name="example_length" 
                                aria-controls="example" 
                                class="form-select form-select-sm" 
                                v-model="localShowRecord"
                            >
                                <option v-for="item in tableData.selectData" :value="item" :key="String(item)">{{ item }}</option>
                            </select>
                            entries
                        </label>
                    </div>
                    <!-- Show Record -->
                </div>
                <div class="col-sm-12 col-md-6">
                    <div v-if="showExportToolbar" class="table-export-wrap">
                        <div class="table-export-bar">
                            <div class="btn-group table-export-group" role="group" aria-label="Export table data">
                                <button
                                    type="button"
                                    class="btn btn-sm table-export-btn table-export-btn--excel"
                                    title="Download Excel (.xlsx)"
                                    :disabled="exportActionsDisabled"
                                    @click="exportExcel"
                                >
                                    <i class="mdi mdi-microsoft-excel table-export-btn__icon" aria-hidden="true"></i>
                                    <span class="table-export-btn__label">Excel</span>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm table-export-btn table-export-btn--csv"
                                    title="Download CSV"
                                    :disabled="exportActionsDisabled"
                                    @click="exportCsv"
                                >
                                    <i class="mdi mdi-file-delimited table-export-btn__icon" aria-hidden="true"></i>
                                    <span class="table-export-btn__label">CSV</span>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm table-export-btn table-export-btn--pdf"
                                    title="Download PDF"
                                    :disabled="exportActionsDisabled"
                                    @click="exportPdf"
                                >
                                    <i class="mdi mdi-file-pdf-box table-export-btn__icon" aria-hidden="true"></i>
                                    <span class="table-export-btn__label">PDF</span>
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm table-export-btn table-export-btn--copy"
                                    title="Copy table to clipboard"
                                    :disabled="exportActionsDisabled"
                                    @click="exportCopyClipboard"
                                >
                                    <i class="mdi mdi-content-copy table-export-btn__icon" aria-hidden="true"></i>
                                    <span class="table-export-btn__label">Copy</span>
                                </button>
                            </div>
                        </div>
                        <div class="table-export-meta">
                            <span class="table-export-hint">{{ exportHint }}</span>
                            <span v-if="exportError" class="table-export-error">{{ exportError }}</span>
                        </div>
                    </div>
                    <div id="example_filter" class="dataTables_filter">
                        <label>
                            Search:
                            <input
                                type="search"
                                class="form-control form-control-sm"
                                placeholder="Search..."
                                aria-controls="datatable-basic"
                                v-model="localSearch"
                            >
                        </label>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-12">
                    <table class="table table-striped table-bordered dataTable" style="width:100%">
                        <thead>
                            <tr role="row">
                                <th
                                    v-for="(item, index) in displayColumns"
                                    :key="item.key ?? `th-${index}`"
                                    :class="[
                                        'sorting',
                                        (tableData.sortBy === item.key)
                                            ? (tableData.sortType === 'asc')
                                                ? 'sorting_asc'
                                                : 'sorting_desc'
                                            : '',
                                        (item?.sorting === 'disabled') ? 'sorting_disabled' : '',
                                        'text-uppercase'
                                    ]"
                                    :data-colname="item?.key"
                                    data-ordertype="asc"
                                    tabindex="0"
                                    rowspan="1"
                                    colspan="1"
                                    @click="(item?.sorting !== 'disabled') ? tableData.changeOrder?.($event) : ''"
                                >
                                    <div class="form-check form-check-table" v-if="item.type === 'checkbox'">
                                        <input class="form-check-input" type="checkbox" id="MainCheckbox" @change="checkAll()" v-model="state.selectAll">
                                    </div>

                                    <div class="d-flex justify-content-between" v-else-if="item?.type === 'badge'">
                                        {{ item?.label }}
                                        <div class="dropdown">
											<button 
                                                class="btn btn-sm btn-secondary dropdown-toggle" 
                                                type="button" 
                                                data-bs-toggle="dropdown" 
                                                aria-expanded="false"
                                            >
                                            </button>
											<ul class="dropdown-menu" v-if="item?.show === 'retake_status'">
												<li><a class="dropdown-item" href="javascript:void(0);" v-if="state?.search?.status !== 'all'" data-value="all" @click="getStatus">All</a></li>
												<li><a class="dropdown-item" href="javascript:void(0);" v-if="state?.search?.status !== 'pending'" data-value="pending" @click="getStatus">Pending</a></li>
												<li><a class="dropdown-item" href="javascript:void(0);" v-if="state?.search?.status !== 'approved'" data-value="approved" @click="getStatus">Approved</a></li>
												<li><a class="dropdown-item" href="javascript:void(0);" v-if="state?.search?.status !== 'rejected'" data-value="rejected" @click="getStatus">Rejected</a></li>
											</ul>

                                            <ul class="dropdown-menu" v-else-if="item?.show === 'paid'">
												<li><a class="dropdown-item" href="javascript:void(0);" v-if="state?.search?.status !== 'all'" data-value="all" @click="getStatus">All</a></li>
												<li><a class="dropdown-item" href="javascript:void(0);" v-if="state?.search?.status !== 'paid'" data-value="paid" @click="getStatus">Paid</a></li>
												<li><a class="dropdown-item" href="javascript:void(0);" v-if="state?.search?.status !== 'unpaid'" data-value="unpaid" @click="getStatus">Unpaid</a></li>
											</ul>

                                            <ul class="dropdown-menu" v-else>
												<li><a class="dropdown-item" href="javascript:void(0);" v-if="state?.search?.status !== 'all'" data-value="all" @click="getStatus">All</a></li>
												<li><a class="dropdown-item" href="javascript:void(0);" v-if="state?.search?.status !== '1'" data-value="1" @click="getStatus">Active</a></li>
												<li><a class="dropdown-item" href="javascript:void(0);" v-if="state?.search?.status !== '0'" data-value="0" @click="getStatus">In Active</a></li>
											</ul>
										</div>
                                    </div>

                                    <span v-else>{{ item?.label }}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <SkeletonTableRows
                                v-if="tableData.state?.loading === true"
                                :columns="displayColumns.length"
                                :rows="skeletonRowCount"
                                :cell-height="14"
                                :duration-sec="1.55"
                            />
                            <tr class="odd" v-if="tableData.state.records.data.length === 0 && tableData.state?.loading === false">
                                <td colspan="100%" class="text-center">No Record Found</td>
                            </tr>
                            <template
                                v-else-if="tableData.state.records.data.length !== 0 && tableData.state?.loading === false"
                            >
                                <tr
                                    v-for="(row, rowIndex) in tableData.state.records.data"
                                    :key="row.id != null ? row.id : `row-${rowIndex}`"
                                >
                                    <td
                                        v-for="(col, colIndex) in displayColumns"
                                        :key="col.key ?? `cell-${colIndex}`"
                                        :data-colname="col.key"
                                    >
                                        <!-- Checkbox column -->
                                        <div v-if="col.type === 'checkbox'" class="form-check form-check-table">
                                            <input class="form-check-input" type="checkbox" :value="row.id" :checked="tableData.state?.edit_ids.includes(row.id)" @click="tableData.checkAll?.(row.id)"/>
                                        </div>

                                        <!-- Count column -->
                                        <span v-else-if="col.type === 'count'">
                                            {{ rowIndex + 1 + ((tableData.state.records.current_page - 1) * tableData.state.search.show_record) }}
                                        </span>

                                        <!-- Certificate download (same as enrolled courses: parent uses useCertificateFileDownload) -->
                                        <span v-else-if="col.type === 'certificate_download'">
                                            <button
                                                v-if="row.certificate_download_enabled !== false"
                                                type="button"
                                                class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1 text-nowrap"
                                                :disabled="!tableData.certificateDownload || (tableData.certificateDownloadRowBusy?.(row) ?? false)"
                                                @click="tableData.certificateDownload?.(row)"
                                            >
                                                <template v-if="tableData.certificateDownloadRowBusy?.(row)">
                                                    <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true" />
                                                    <span>Please wait</span>
                                                </template>
                                                <template v-else>
                                                    <i class="mdi mdi-download" aria-hidden="true" />
                                                    <span>Download</span>
                                                </template>
                                            </button>
                                            <span
                                                v-else
                                                class="text-muted small"
                                                title="Certificate is not available for this course/program."
                                            >
                                                Not available
                                            </span>
                                        </span>

                                        <!-- External / same-site link -->
                                        <a
                                            v-else-if="col.type === 'external_link' && col.external_link_variant === 'button'"
                                            :href="formatExternalHref(row[col.data_column ?? col.key])"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="btn btn-sm btn-primary btn-wave waves-effect waves-light d-inline-flex align-items-center gap-1 text-nowrap"
                                        >
                                            <i class="mdi mdi-download" aria-hidden="true" />
                                            <span>{{ col.external_link_label ?? 'Download' }}</span>
                                        </a>
                                        <a
                                            v-else-if="col.type === 'external_link'"
                                            :href="formatExternalHref(row[col.data_column ?? col.key])"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="text-primary text-break"
                                        >
                                            {{ col.external_link_label ?? row[col.data_column ?? col.key] ?? '-' }}
                                        </a>

                                        <!-- badge -->
                                        <span
                                            :class="[
                                                'badge cursor-pointer align-items-center d-inline-flex',
                                                (row[col.key] === true)?'bg-success':'bg-danger'
                                            ]"
                                            v-else-if="col.type === 'badge' && col.show === 'active'"
                                            @click="!state.loadingIds.has(row.id) ? tableData.changeStatus?.([row.id], null) : ''"
                                        >
                                            {{ (row[col.key] === true)?'Active':'In Active' }}
                                        </span>

                                        <span
                                            :class="[
                                                'badge cursor-pointer align-items-center d-inline-flex',
                                                (row[col.key] === true)?'bg-success':'bg-danger'
                                            ]"
                                            v-else-if="col.type === 'badge' && col.show === 'paid'"
                                        >
                                            {{ (row[col.key] === true)?'Paid':'UnPaid' }}
                                        </span>

                                        <span
                                            :class="[
                                                'badge cursor-pointer align-items-center d-inline-flex',
                                                (row[col.key] === true)?'bg-success':'bg-danger'
                                            ]"
                                            v-else-if="col.type === 'badge' && col.show === 'yes'"
                                        >
                                            {{ (row[col.key] === true)?'Yes':'No' }}
                                        </span>

                                        <span
                                            :class="[
                                                'badge cursor-pointer align-items-center d-inline-flex text-capitalize',
                                                (row[col.key] === 'approved')?'bg-success':'bg-danger'
                                            ]"
                                            v-else-if="col.type === 'badge' && col.show === 'pending'"
                                        >
                                            {{ (row[col.key] === 'approved')?'Approved': row.status }}
                                        </span>

                                        <span
                                            :class="[
                                                'badge align-items-center d-inline-flex text-capitalize',
                                                (row[col.key] === 'pending')?'bg-warning':(row[col.key] === 'approved')?'bg-success':'bg-danger'
                                            ]"
                                            v-else-if="col.type === 'badge' && col.show === 'retake_status'"
                                        >
                                            {{ row[col.key] }}
                                        </span>
                                        
                                        <span
                                            :class="[
                                                'badge align-items-center d-inline-flex text-capitalize',
                                                (row[col.key] === 'active')?'bg-warning':(row[col.key] === 'converted')?'bg-success':'bg-danger'
                                            ]"
                                            v-else-if="col.type === 'badge' && col.show === 'cart_status'"
                                        >
                                            {{ row[col.key] }}
                                        </span>

                                        <span
                                            :class="[
                                                'badge align-items-center d-inline-flex text-capitalize',
                                                String(row[col.key]).toLowerCase() === 'pending'
                                                    ? 'bg-danger'
                                                    : String(row[col.key]).toLowerCase() === 'processing'
                                                    ? 'bg-info'
                                                    : String(row[col.key]).toLowerCase() === 'ready'
                                                        ? 'bg-success'
                                                        : String(row[col.key]).toLowerCase() === 'failed'
                                                        ? 'bg-danger'
                                                        : 'bg-secondary'
                                            ]"
                                            v-else-if="col.type === 'badge' && col.show === 'scorm_status'"
                                        >
                                            {{ row[col.key] ?? '-' }}
                                        </span>

                                        <span
                                            :class="[
                                                'badge align-items-center d-inline-flex text-capitalize',
                                                String(row[col.key]).toLowerCase() === 'completed' ? 'bg-success' : 'bg-warning'
                                            ]"
                                            v-else-if="col.type === 'badge' && col.show === 'progress_status'"
                                        >
                                            {{ row[col.key] }}
                                        </span>

                                        <span
                                            :class="[
                                                'badge align-items-center d-inline-flex text-capitalize',
                                                String(row[col.key]).toLowerCase() === 'passed'
                                                    ? 'bg-success'
                                                    : String(row[col.key]).toLowerCase() === 'failed'
                                                    ? 'bg-danger'
                                                    : String(row[col.key]).toLowerCase() === 'pending'
                                                        ? 'bg-warning'
                                                        : String(row[col.key]).toLowerCase() === 'ongoing'
                                                        ? 'bg-info'
                                                        : 'bg-secondary'
                                            ]"
                                            v-else-if="col.type === 'badge' && col.show === 'exam_status'"
                                        >
                                            {{ row[col.key] }}
                                        </span>

                                        <!-- Action -->
                                        <span v-else-if="col.type === 'action'">

                                            <button id="btnGroupVerticalDrop1" type="button" class="btn btn-sm modern-action-btn dropdown-toggle" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-label="Row actions" title="Actions">
                                                <DotsVerticalRounded size="sm" class="modern-action-btn__icon" aria-hidden="true" />
                                            </button>
                                            <div class="dropdown-menu" aria-labelledby="btnGroupVerticalDrop1" bis_skin_checked="1">

                                                <!-- Edit -->
                                                    <a
                                                        class="dropdown-item"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#EditModal"
                                                        href="javascript:void(0)"
                                                        @click.capture="tableData.edit?.(row.id)"
                                                        v-if="col.actions?.includes('edit') && tableData.actionType === 'modal' && props.auth.user.permission_paths.includes(`/${tableData.apiUrl}/:id/edit`)"
                                                    >
                                                        <i class="mdi mdi-pencil-outline"></i> Edit
                                                    </a>

                                                    <Link
                                                        class="dropdown-item"
                                                        :href="`/${tableData.apiUrl}/${row.id}/edit`"
                                                        v-else-if="col.actions?.includes('edit') && props.auth.user.permission_paths.includes(`/${tableData.apiUrl}/:id/edit`) && tableData.actionType === 'link'"
                                                    >
                                                        <i class="mdi mdi-pencil-outline"></i> Edit
                                                    </Link>
                                                <!-- Edit -->

                                                <!-- View -->
                                                    <Link
                                                        v-if="col.actions?.includes('view') && tableData.viewRoute && props.auth.user.permission_paths.includes(`/${tableData.apiUrl}/:id/view`)"
                                                        :href="tableData.viewRoute(row.id)"
                                                        class="dropdown-item"
                                                    >
                                                        <i class="mdi mdi-eye-outline"></i> View
                                                    </Link>
                                                    <a
                                                        v-else-if="col.actions?.includes('view') && tableData.actionType === 'modal' && props.auth.user.permission_paths.includes(`/${tableData.apiUrl}/:id/view`)"
                                                        class="dropdown-item"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#ViewModal"
                                                        href="javascript:void(0)"
                                                        @click="tableData.view?.(row.id)"
                                                    >
                                                        <i class="mdi mdi-eye-outline"></i> View
                                                    </a>
                                                <!-- View -->

                                                <!-- Duplicate -->
                                                    <a
                                                        class="dropdown-item"
                                                        data-bs-toggle="tooltip"
                                                        data-bs-placement="top"
                                                        data-bs-title="Duplicate"
                                                        v-if="col.actions?.includes('duplicate') && props.auth.user.permission_paths.includes(`/${tableData.apiUrl}/add`)"
                                                        @click="tableData.duplicate?.(row.id)"
                                                    >
                                                        <i class="mdi mdi-content-copy"></i> Duplicate
                                                    </a>
                                                <!-- Duplicate -->

                                                <!-- Restore -->
                                                    <a
                                                        class="dropdown-item"
                                                        data-bs-toggle="tooltip"
                                                        data-bs-placement="top"
                                                        data-bs-title="Restore"
                                                        v-if="col.actions?.includes('restore') && props.auth.user.permission_paths.includes(`/${tableData.apiUrl}/restore`)"
                                                        @click="(!state.loadingIds.has(row.id))?tableData.restore?.([row.id]):''"
                                                    >
                                                        <i class="mdi mdi-restore"></i> Restore
                                                    </a>
                                                <!-- Restore -->

                                                <!-- Delete -->
                                                    <a
                                                        class="dropdown-item"
                                                        data-bs-toggle="tooltip"
                                                        data-bs-placement="top"
                                                        data-bs-title="Delete"
                                                        v-if="col.actions?.includes('delete') && props.auth.user.permission_paths.includes(`/${tableData.apiUrl}/delete`)"
                                                        @click="(!state.loadingIds.has(row.id))?tableData.delete?.([row.id]):''"
                                                    >
                                                        <i class="mdi mdi-delete-outline"></i> Delete
                                                    </a>
                                                <!-- Delete -->

                                                <!-- Permission -->
                                                    <Link
                                                        :href="`${tableData.apiUrl}/${row.id}/permission`"
                                                        class="dropdown-item"
                                                        data-bs-toggle="tooltip"
                                                        data-bs-placement="top"
                                                        data-bs-title="Permission"
                                                        v-if="col.actions?.includes('permission') && props.auth.user.permission_paths.includes(`/${tableData.apiUrl}/:id/permission`)"
                                                    >
                                                        <i class="mdi mdi-lock-outline"></i> Permission
                                                    </Link>
                                                <!-- Permission -->

                                                <!-- Approve -->
                                                    <a
                                                        class="dropdown-item"
                                                        href="javascript:void(0)"
                                                        v-if="col.actions?.includes('approve') && row.status === 'pending' && tableData.approve"
                                                        @click="tableData.approve?.(row.id)"
                                                    >
                                                        <i class="mdi mdi-check-circle-outline"></i> Approve
                                                    </a>
                                                <!-- Approve -->

                                                <!-- Reject -->
                                                    <a
                                                        class="dropdown-item"
                                                        href="javascript:void(0)"
                                                        v-if="col.actions?.includes('reject') && row.status === 'pending' && tableData.reject"
                                                        @click="tableData.reject?.(row.id)"
                                                    >
                                                        <i class="mdi mdi-close-circle-outline"></i> Reject
                                                    </a>
                                                <!-- Reject -->

                                                <!-- Requeue -->
                                                    <a
                                                        class="dropdown-item"
                                                        href="javascript:void(0)"
                                                        v-if="col.actions?.includes('requeue') && String(row.status).toLowerCase() === 'failed' && tableData.requeue"
                                                        @click="tableData.requeue?.(row.id)"
                                                    >
                                                        <i class="mdi mdi-reload"></i> Requeue
                                                    </a>
                                                <!-- Requeue -->

                                                <!-- Invoice -->
                                                    <a
                                                        class="dropdown-item"
                                                        :class="{ disabled: tableData.invoiceDownloadingId === row.id }"
                                                        href="javascript:void(0)"
                                                        @click="tableData.invoiceDownloadingId !== row.id ? tableData.downloadInvoice?.(row.id) : undefined"
                                                        v-if="col.actions?.includes('invoice') && canUseInvoiceAction(tableData.apiUrl)"
                                                    >
                                                        <template v-if="tableData.invoiceDownloadingId === row.id">
                                                            <span class="spinner-border spinner-border-sm me-1 align-middle" role="status" aria-hidden="true"></span>
                                                            Downloading…
                                                        </template>
                                                        <template v-else>
                                                            <i class="mdi mdi-file-pdf-box"></i> Invoice
                                                        </template>
                                                    </a>
                                                <!-- Invoice -->

                                            </div>
                                        </span>

                                        <span v-else-if="col.type === 'multi_relation'" class="position-relative">
                                            <!-- Button (trigger) -->
                                            <button
                                                class="btn btn-sm btn-link p-0"
                                                :ref="el => (referenceRefs[row.id] = el)"
                                                @click="toggle(row.id)"
                                            >
                                                <span class="mdi mdi-list-box" style="font-size: 30px;padding: 0px;line-height: 0.8;"></span>
                                            </button>

                                            <Teleport to="body">
                                                <!-- Tooltip -->
                                                <div
                                                    v-if="openRow === row.id"
                                                    :ref="el => (floatingRefs[row.id] = el)"
                                                    class="card text-start border-1"
                                                    :style="floatingStyles[row.id]"
                                                >
                                                    <div class="card-body p-2">
                                                        <ul class="mb-1 ps-3 pe-5">
                                                            <li
                                                            v-for="(course, idx) in row?.program_courses"
                                                            :key="course?.course?.id ?? course?.id ?? `pc-${idx}`"
                                                            >
                                                                {{ course?.course?.title }}
                                                            </li>
                                                        </ul>
                                                    </div>
                                                    <!-- Arrow -->
                                                    <div
                                                    :ref="el => (arrowRefs[row.id] = el)"
                                                    class="tooltip-arrow"
                                                    :style="arrowStyles[row.id]"
                                                    ></div>
                                                </div>
                                            </Teleport>
                                        </span>

                                        <!-- Default data binding -->
                                        <span v-else>
                                            <a
                                                v-if="col?.linkable && tableData.actionType === 'modal' && props.auth.user.permission_paths.includes(`/${col.url}`)"
                                                data-bs-toggle="modal"
                                                :data-bs-target="col?.modal"
                                                class="text-primary"
                                                href="javascript:void(0)"
                                                @click="tableData[col.type]?.(row.id)"
                                            >
                                                {{ defaultCellDisplay(row, col) }}
                                            </a>
                                            <span
                                                v-else
                                                :class="{
                                                    'modern-cell-code': col.type === 'code',
                                                    'modern-cell-primary': col.type === 'primary',
                                                    'modern-cell-secondary': col.type === 'secondary',
                                                }"
                                            >{{ defaultCellDisplay(row, col) }}</span>
                                        </span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot>
                            <tr role="row">
                                <th
                                    v-for="(item, index) in displayColumns"
                                    :key="item.key ?? `tfoot-${index}`"
                                    :class="[
                                        'sorting',
                                        (tableData.sortBy === item.key)
                                            ? (tableData.sortType === 'asc')
                                                ? 'sorting_asc'
                                                : 'sorting_desc'
                                            : '',
                                        (item?.sorting === 'disabled') ? 'sorting_disabled' : '',
                                        'text-uppercase'
                                    ]"
                                    :data-colname="item?.key"
                                    data-ordertype="asc"
                                    tabindex="0"
                                    rowspan="1"
                                    colspan="1"
                                    @click="(item?.sorting !== 'disabled') ? tableData.changeOrder?.($event) : ''"
                                >

                                    <span>{{ item?.label }}</span>
                                </th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-12 col-md-5">
                    <div class="dataTables_info" id="example_info" role="status" aria-live="polite">
                        Showing {{ tableData.state.records.from ?? 0 }} to {{ tableData.state.records.to ?? 0 }} of {{ tableData.state.records.total ?? 0 }} entries
                    </div>
                </div>
                <div class="col-sm-12 col-md-7" v-if="showPaginationBar">
                    <div class="dataTables_paginate paging_simple_numbers" id="example_paginate">
                        <z-vue-pagination
                            :total-items="paginationTotalItems"
                            :items-per-page="paginationPageSize"
                            :max-pages-shown="5"
                            v-model="tableData.state.search.page"
                            @click="tableData.getData?.();"
                            :showDisabled="true"
                            :disableBreakpointButtons="true"
                            pagination-container-class="pagination"
                        />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style lang="css" scoped>
    /* Defer off-screen row layout/paint on long pages (content-visibility). */
    :deep(#datatable-basic tbody tr) {
        content-visibility: auto;
        contain-intrinsic-size: auto 48px;
    }

    .tooltip-arrow {
        width: 12px;
        height: 12px;
        transform: rotate(45deg);
        border: 1px solid rgba(0, 0, 0, 0.15);
        position: absolute;
    }
</style>
