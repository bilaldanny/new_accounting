<script setup lang="ts">
    import ModalComponent from '@/components/ModalComponent.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import { TABLE_EXPORT_PAGE_SIZE } from '@/composables/tableExportList';
    import useCommons from '@/composables/common';
    import { File, FilePlus } from '@boxicons/vue';
    import { usePage } from '@inertiajs/vue3';
    import { computed, ref } from 'vue';

    const { props } = usePage();
    const { Notify } = useCommons();

    const modalProps = defineProps({
        onOpen: { type: Function, default: undefined },
        onClose: { type: Function, default: undefined },
        onImport: { type: Function, default: undefined },
        onSuccess: { type: Function, default: undefined },
        sampleFileName: { type: String, default: 'company-import-sample.xlsx' },
    });

    const selectedFile = ref<File | null>(null);
    const fileInput = ref<HTMLInputElement | null>(null);
    const isImporting = ref(false);
    const isDownloadingSample = ref(false);
    const fileError = ref('');

    const sampleColumns = [
        'id',
        'code',
        'name',
        'email',
        'phone',
        'ntn_no',
        'address',
        'zipcode',
        'country_id',
        'state_id',
        'city_id',
        'max_users',
        'max_branches',
        'is_active',
        'admin_name',
        'admin_username',
        'admin_email',
        'admin_phone',
        'password',
    ] as const;

    const fallbackSampleRows = [
        ['', '', 'Sample Company', '', '', '', '', '', '', '', '', 10, 2, 1, 'Admin User', 'adminuser', 'admin@example.com', '', 'Password1!'],
    ];

    const selectedFileLabel = computed(() => selectedFile.value?.name ?? 'No Excel file selected');

    function normalizeExportValue(value: unknown): string | number {
        if (value === null || value === undefined || value === '') {
            return '';
        }

        return value as string | number;
    }

    function normalizeBool(value: unknown): number {
        return value === true || value === 1 || value === '1' ? 1 : 0;
    }

    function mapCompanyToSampleRow(company: Record<string, unknown>): (string | number)[] {
        return [
            normalizeExportValue(company.id),
            normalizeExportValue(company.code),
            normalizeExportValue(company.name),
            normalizeExportValue(company.email),
            normalizeExportValue(company.phone),
            normalizeExportValue(company.ntn_no),
            normalizeExportValue(company.address),
            normalizeExportValue(company.zipcode),
            normalizeExportValue(company.country_id),
            normalizeExportValue(company.state_id),
            normalizeExportValue(company.city_id),
            normalizeExportValue(company.max_users),
            normalizeExportValue(company.max_branches),
            normalizeBool(company.is_active),
            normalizeExportValue(company.admin_name),
            normalizeExportValue(company.admin_username),
            normalizeExportValue(company.admin_email),
            normalizeExportValue(company.admin_phone),
            '',
        ];
    }

    function isRowEmpty(row: Record<string, unknown>): boolean {
        return Object.values(row).every((value) => String(value ?? '').trim() === '');
    }

    async function parseExcelFile(file: File): Promise<Record<string, unknown>[]> {
        const XLSX = await import('xlsx');
        const buffer = await file.arrayBuffer();
        const workbook = XLSX.read(buffer, { type: 'array' });
        const sheetName = workbook.SheetNames[0];

        if (! sheetName) {
            throw new Error('The Excel file does not contain any worksheets.');
        }

        const sheet = workbook.Sheets[sheetName];
        const rows = XLSX.utils.sheet_to_json<Record<string, unknown>>(sheet, { defval: '' });

        return rows.filter((row) => ! isRowEmpty(row));
    }

    function handleImportResponse(response: { data?: { message?: string; errormessage?: unknown } }) {
        if (response.data?.errormessage) {
            const errorMessage = response.data.errormessage;

            if (typeof errorMessage === 'object' && errorMessage !== null && 'errorInfo' in errorMessage) {
                const errorInfo = (errorMessage as { errorInfo?: string[] }).errorInfo;
                Notify(errorInfo?.[errorInfo.length - 1] ?? 'Import failed', 'alert');
            } else {
                Notify(String(errorMessage), 'alert');
            }

            return false;
        }

        Notify(response.data?.message ?? 'Import completed successfully', 'success');
        document.querySelectorAll('.btn-close').forEach((element) => element.click());
        resetFileInput();
        modalProps.onSuccess?.();

        return true;
    }

    async function fetchAllCompanyRows(): Promise<Record<string, unknown>[]> {
        const params = {
            status: 'all',
            search: '',
            sort_by: 'created_at',
            sort_type: 'desc',
            show_record: TABLE_EXPORT_PAGE_SIZE,
            cur_page: 1,
        };

        const { data: body } = await window.axios.get(API_ENDPOINTS.companies, { params });
        const paginator = body?.data;
        let rows = (paginator?.data ?? []) as Record<string, unknown>[];
        const lastPage = Number(paginator?.last_page) || 1;

        for (let page = 2; page <= lastPage; page++) {
            const { data: nextBody } = await window.axios.get(API_ENDPOINTS.companies, {
                params: { ...params, cur_page: page },
            });

            rows = rows.concat((nextBody?.data?.data ?? []) as Record<string, unknown>[]);
        }

        return rows;
    }

    function resetFileInput() {
        selectedFile.value = null;
        fileError.value = '';

        if (fileInput.value) {
            fileInput.value.value = '';
        }
    }

    function isExcelFile(file: File): boolean {
        const extension = file.name.split('.').pop()?.toLowerCase() ?? '';

        return ['xlsx', 'xls'].includes(extension);
    }

    function handleFileChange(event: Event) {
        const input = event.target as HTMLInputElement;
        const file = input.files?.[0] ?? null;

        if (file !== null && ! isExcelFile(file)) {
            selectedFile.value = null;
            fileError.value = 'Please select a valid Excel file (.xlsx or .xls).';
            input.value = '';

            return;
        }

        fileError.value = '';
        selectedFile.value = file;
    }

    function handleOpen() {
        resetFileInput();
        modalProps.onOpen?.();
    }

    function handleClose() {
        resetFileInput();
        isImporting.value = false;
        isDownloadingSample.value = false;
        modalProps.onClose?.();
    }

    async function downloadSampleFile() {
        if (isDownloadingSample.value) {
            return;
        }

        isDownloadingSample.value = true;

        try {
            const companies = await fetchAllCompanyRows();
            const rows = companies.length > 0
                ? companies.map(mapCompanyToSampleRow)
                : fallbackSampleRows;

            const XLSX = await import('xlsx');
            const worksheet = XLSX.utils.aoa_to_sheet([sampleColumns, ...rows]);
            const workbook = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(workbook, worksheet, 'Companies');
            XLSX.writeFile(workbook, modalProps.sampleFileName);
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error)) {
                Notify(error.response?.data?.message || 'Unable to prepare sample file', 'alert');
            } else {
                Notify('Unable to prepare sample file', 'alert');
            }
        } finally {
            isDownloadingSample.value = false;
        }
    }

    async function submitImport() {
        if (! selectedFile.value || isImporting.value) {
            return;
        }

        isImporting.value = true;

        try {
            if (modalProps.onImport) {
                await modalProps.onImport(selectedFile.value);

                return;
            }

            const rows = await parseExcelFile(selectedFile.value);

            if (rows.length === 0) {
                Notify('The Excel file does not contain any company rows.', 'alert');

                return;
            }

            const response = await window.axios.post(API_ENDPOINTS.companyImport, { rows });
            handleImportResponse(response);
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error)) {
                const validationMessage = error.response?.data?.errors?.rows?.[0]
                    ?? error.response?.data?.message
                    ?? 'Import failed';

                Notify(validationMessage, 'alert');
            } else if (error instanceof Error) {
                Notify(error.message, 'alert');
            } else {
                Notify('Import failed', 'alert');
            }
        } finally {
            isImporting.value = false;
        }
    }
</script>

<template>
    <ModalComponent
        id="ImportModal"
        :title="`Import ${props.routeName}`"
        :onOpen="handleOpen"
        :onClose="handleClose"
        size="lg"
    >
        <div class="row g-3">
            <div class="col-12">
                <div class="alert alert-light border d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3 mb-0">
                    <div>
                        <div class="fw-semibold mb-1">Need a template?</div>
                        <div class="text-muted small">
                            Download the sample Excel file with existing company records when available. Leave the id column empty to add a new company (password and admin fields are required), or keep the id to update an existing record.
                        </div>
                    </div>

                    <button
                        type="button"
                        class="btn btn-sm btn-outline-primary d-inline-flex align-items-center"
                        :disabled="isDownloadingSample"
                        :aria-busy="isDownloadingSample"
                        @click="downloadSampleFile"
                    >
                        <span
                            v-if="isDownloadingSample"
                            class="spinner-border spinner-border-sm me-1"
                            role="status"
                            aria-hidden="true"
                        ></span>
                        <FilePlus v-else size="sm" class="me-1" />
                        Download sample file
                    </button>
                </div>
            </div>

            <div class="col-12">
                <label class="form-label" for="company-import-file">Excel file</label>
                <input
                    id="company-import-file"
                    ref="fileInput"
                    type="file"
                    class="form-control"
                    accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel"
                    @change="handleFileChange"
                />
                <div class="form-text">Upload an Excel workbook (.xlsx or .xls) using the sample file format.</div>
                <div v-if="fileError" class="text-danger small mt-1">{{ fileError }}</div>
            </div>

            <div class="col-12">
                <div class="border rounded px-3 py-2 d-flex align-items-center gap-2 bg-light">
                    <File size="sm" class="text-primary flex-shrink-0" />
                    <span class="small text-break">{{ selectedFileLabel }}</span>
                </div>
            </div>
        </div>

        <template #footer>
            <button type="button" class="btn btn-light waves-effect" data-bs-dismiss="modal">Close</button>

            <button
                type="button"
                class="btn btn-primary d-inline-flex align-items-center"
                :disabled="!selectedFile || isImporting"
                :aria-busy="isImporting"
                @click="submitImport"
            >
                <span
                    v-if="isImporting"
                    class="spinner-border spinner-border-sm me-1"
                    role="status"
                    aria-hidden="true"
                ></span>
                {{ isImporting ? 'Importing…' : 'Import' }}
            </button>
        </template>
    </ModalComponent>
</template>
