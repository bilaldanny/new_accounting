<script setup lang="ts">
    import { ref } from 'vue';
    import ImportModalLayout from '@/components/ImportModalLayout.vue';
    import useCommons from '@/composables/common';
    import {
        parseImportErrorResponse,
        parseSpreadsheetFile,
        writeImportWorkbook,
    } from '@/composables/importHelpers';
    import type { ImportResult } from '@/composables/importHelpers';

    const { Notify } = useCommons();

    const props = defineProps({
        kind: { type: String as () => 'customer' | 'supplier', required: true },
        onSuccess: { type: Function, default: undefined },
    });

    const label = props.kind === 'customer' ? 'Customers' : 'Suppliers';
    const endpoint = props.kind === 'customer' ? '/api/customers/import' : '/api/suppliers/import';

    const selectedFile = ref<File | null>(null);
    const importLayout = ref<InstanceType<typeof ImportModalLayout> | null>(null);
    const isImporting = ref(false);
    const fileError = ref('');
    const importResult = ref<ImportResult | null>(null);

    const sampleColumns = [
        'business_name', 'first_name', 'last_name', 'mobile', 'email', 'address', 'ntn_number', 'strn_number',
        'code', 'opening_balance', 'credit_limit', 'pay_term', 'pay_type', 'type',
    ] as const;

    const sampleRows = [
        [`Sample ${props.kind === 'customer' ? 'Traders' : 'Supplies'}`, 'Ali', 'Khan', '0300-1234567', 'ali@example.com', 'Main Bazaar', '1234567-8', '', '', 0, 0, 30, 'day', 'local'],
    ];

    function resetImportState() {
        selectedFile.value = null;
        fileError.value = '';
        importResult.value = null;
        importLayout.value?.resetAll();
    }

    function handleDone() {
        if (importResult.value?.status === 'success') {
            props.onSuccess?.();
        }
    }

    function handleFileChange(event: Event) {
        const input = event.target as HTMLInputElement;
        const file = input.files?.[0] ?? null;
        const extension = file?.name.split('.').pop()?.toLowerCase() ?? '';

        if (file !== null && ! ['xlsx', 'xls', 'csv'].includes(extension)) {
            selectedFile.value = null;
            fileError.value = 'Please select a valid Excel or CSV file (.xlsx, .xls, or .csv).';
            input.value = '';

            return;
        }

        fileError.value = '';
        selectedFile.value = file;
    }

    async function downloadSampleFile() {
        await writeImportWorkbook(sampleColumns, sampleRows, label, `${props.kind}-import-sample.xlsx`);
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
            const rows = await parseSpreadsheetFile(selectedFile.value);
            rowCount = rows.length;

            if (rows.length === 0) {
                fileError.value = `The selected file does not contain any ${props.kind} rows.`;

                return;
            }

            // Check first: the same run, rolled back. Only a clean file is then written.
            const check = await window.axios.post(endpoint, { rows, dry_run: true });

            if (check.data.summary.failed > 0) {
                importResult.value = {
                    status: 'error',
                    message: 'Nothing was imported. Fix the rows below and upload the file again.',
                    summary: { total: rows.length, created: 0, updated: 0, failed: check.data.summary.failed },
                    errors: check.data.errors,
                };
                Notify('The file has rows with errors', 'alert');

                return;
            }

            const response = await window.axios.post(endpoint, { rows });
            importResult.value = {
                status: 'success',
                message: response.data.message,
                summary: { total: rows.length, created: response.data.summary.created, updated: 0, failed: 0 },
                errors: response.data.errors ?? [],
            };
            Notify(response.data.message, 'success');
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
        :title="`Import ${label}`"
        :on-open="resetImportState"
        :on-close="resetImportState"
        :file-input-id="`${kind}-import-file`"
        :selected-file="selectedFile"
        :file-error="fileError"
        :is-importing="isImporting"
        :import-result="importResult"
        lead-text="Business name and mobile are required on every row."
        secondary-text="Rows that match an existing contact's email, mobile or business name are skipped and listed. An opening balance is posted to the contact's account, so the account mapping must be set. Any row with an error stops the whole file."
        submit-label="Check and import"
        @download-sample="downloadSampleFile"
        @file-change="handleFileChange"
        @submit="submitImport"
        @done="handleDone"
    />
</template>
