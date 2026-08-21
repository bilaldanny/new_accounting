<script setup lang="ts">
    import ImportModalLayout from '@/components/ImportModalLayout.vue';
    import { API_ENDPOINTS } from '@/composables/apiEndpoints';
    import {
        downloadImportTemplate,
        normalizeImportBool,
        normalizeImportExportValue,
        parseImportApiResponse,
        parseImportErrorResponse,
        parseSpreadsheetFile,
        type ImportResult,
    } from '@/composables/importHelpers';
    import useCommons from '@/composables/common';
    import { usePage } from '@inertiajs/vue3';
    import { ref } from 'vue';

    const { props } = usePage();
    const { Notify } = useCommons();

    const modalProps = defineProps({
        onOpen: { type: Function, default: undefined },
        onClose: { type: Function, default: undefined },
        onImport: { type: Function, default: undefined },
        onSuccess: { type: Function, default: undefined },
        sampleFileName: { type: String, default: 'branch-import-sample.xlsx' },
    });

    const selectedFile = ref<File | null>(null);
    const importLayout = ref<InstanceType<typeof ImportModalLayout> | null>(null);
    const isImporting = ref(false);
    const isDownloadingSample = ref(false);
    const fileError = ref('');
    const importResult = ref<ImportResult | null>(null);

    const sampleColumns = [
        'id',
        'code',
        'name',
        'email',
        'phone',
        'mobile',
        'address',
        'description',
        'country',
        'state',
        'city',
        'is_active',
        'is_default',
    ] as const;

    const fallbackSampleRows = [
        ['', '', 'Sample Branch', 'branch@example.com', '', '', '', '', 'Pakistan', 'Punjab', 'Lahore', 1, 0],
    ];

    function mapBranchToSampleRow(branch: Record<string, unknown>): (string | number)[] {
        return [
            normalizeImportExportValue(branch.id),
            normalizeImportExportValue(branch.code),
            normalizeImportExportValue(branch.name),
            normalizeImportExportValue(branch.email),
            normalizeImportExportValue(branch.phone),
            normalizeImportExportValue(branch.mobile),
            normalizeImportExportValue(branch.address),
            normalizeImportExportValue(branch.description),
            normalizeImportExportValue(branch.country ?? branch.country_name ?? branch.country_id),
            normalizeImportExportValue(branch.state ?? branch.state_name ?? branch.state_id),
            normalizeImportExportValue(branch.city ?? branch.city_name ?? branch.city_id),
            normalizeImportBool(branch.is_active),
            normalizeImportBool(branch.is_default),
        ];
    }

    function resetImportState() {
        selectedFile.value = null;
        fileError.value = '';
        importResult.value = null;
        importLayout.value?.resetAll();
    }

    function handleImportDone() {
        if (importResult.value?.status === 'success') {
            modalProps.onSuccess?.();
        }
    }

    function isSpreadsheetFile(file: File): boolean {
        const extension = file.name.split('.').pop()?.toLowerCase() ?? '';

        return ['xlsx', 'xls', 'csv'].includes(extension);
    }

    function handleFileChange(event: Event) {
        const input = event.target as HTMLInputElement;
        const file = input.files?.[0] ?? null;

        if (file !== null && ! isSpreadsheetFile(file)) {
            selectedFile.value = null;
            fileError.value = 'Please select a valid Excel or CSV file (.xlsx, .xls, or .csv).';
            input.value = '';

            return;
        }

        fileError.value = '';
        selectedFile.value = file;
    }

    function handleOpen() {
        resetImportState();
        modalProps.onOpen?.();
    }

    function handleClose() {
        resetImportState();
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
            await downloadImportTemplate({
                columns: sampleColumns,
                sheetName: 'Branches',
                fileName: modalProps.sampleFileName,
                listApiUrl: API_ENDPOINTS.branches,
                mapRow: mapBranchToSampleRow,
                fallbackRows: fallbackSampleRows,
            });
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
            if (! selectedFile.value) {
                fileError.value = 'Choose a file before importing.';
            }

            return;
        }

        isImporting.value = true;
        let rowCount = 0;

        try {
            if (modalProps.onImport) {
                await modalProps.onImport(selectedFile.value);

                return;
            }

            const rows = await parseSpreadsheetFile(selectedFile.value);
            rowCount = rows.length;

            if (rows.length === 0) {
                fileError.value = 'The selected file does not contain any branch rows.';

                return;
            }

            const response = await window.axios.post(API_ENDPOINTS.branchImport, { rows });
            importResult.value = parseImportApiResponse(response, rows.length);

            if (importResult.value.status === 'success') {
                Notify(importResult.value.message, 'success');
            } else {
                Notify(importResult.value.message, 'alert');
            }
        } catch (error: unknown) {
            importResult.value = parseImportErrorResponse(error, rowCount);
            Notify(importResult.value.message, 'alert');
        } finally {
            isImporting.value = false;
        }
    }
</script>

<template>
    <ImportModalLayout
        ref="importLayout"
        :title="`Import ${props.routeName}`"
        :on-open="handleOpen"
        :on-close="handleClose"
        file-input-id="branch-import-file"
        :selected-file="selectedFile"
        :file-error="fileError"
        :is-downloading-sample="isDownloadingSample"
        :is-importing="isImporting"
        :import-result="importResult"
        lead-text="Name and email are required on every row."
        secondary-text="The template includes existing records for updates. Leave id blank to add new rows."
        submit-label="Check and import"
        @download-sample="downloadSampleFile"
        @file-change="handleFileChange"
        @submit="submitImport"
        @done="handleImportDone"
    />
</template>
