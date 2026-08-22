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
        sampleFileName: { type: String, default: 'product-import-sample.xlsx' },
    });

    const selectedFile = ref<File | null>(null);
    const importLayout = ref<InstanceType<typeof ImportModalLayout> | null>(null);
    const isImporting = ref(false);
    const isDownloadingSample = ref(false);
    const fileError = ref('');
    const importResult = ref<ImportResult | null>(null);

    const sampleColumns = [
        'id',
        'name',
        'type',
        'unit',
        'brand',
        'category',
        'subcategory',
        'item_type',
        'warranty',
        'alert_qty',
        'sku',
        'purchase_price',
        'margin',
        'sell_price',
        'variation_values',
        'active',
    ] as const;

    const fallbackSampleRows = [[
        '',
        'Premium Basmati Rice',
        'single',
        'Kilogram',
        'Samsung',
        'Grocery',
        '',
        'Finished Goods',
        '',
        10,
        '',
        100,
        20,
        120,
        '',
        1,
    ]];

    function mapProductToSampleRow(product: Record<string, unknown>): (string | number)[] {
        return [
            normalizeImportExportValue(product.id),
            normalizeImportExportValue(product.name),
            normalizeImportExportValue(product.type ?? 'single'),
            normalizeImportExportValue(product.unit_name ?? product.unit_id),
            normalizeImportExportValue(product.brand_name ?? product.brand_id),
            normalizeImportExportValue(product.category_name ?? product.category_id),
            normalizeImportExportValue(product.subcategory_name ?? product.subcategory_id),
            normalizeImportExportValue(product.itemtype_name ?? product.itemtype_id),
            normalizeImportExportValue(product.warranty_id),
            normalizeImportExportValue(product.alert_qty),
            normalizeImportExportValue(product.sku),
            '',
            '',
            '',
            '',
            normalizeImportBool(product.active),
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
                sheetName: 'Products',
                fileName: modalProps.sampleFileName,
                listApiUrl: API_ENDPOINTS.products,
                listQuery: { sort_type: 'desc' },
                mapRow: mapProductToSampleRow,
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
                fileError.value = 'The selected file does not contain any product rows.';

                return;
            }

            const response = await window.axios.post(API_ENDPOINTS.productImport, { rows });
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
        file-input-id="product-import-file"
        :selected-file="selectedFile"
        :file-error="fileError"
        :is-downloading-sample="isDownloadingSample"
        :is-importing="isImporting"
        :import-result="importResult"
        lead-text="Name, unit, brand, category, and item type are required on every row."
        secondary-text="The template includes existing records for updates. Leave id blank to add new rows. Related columns accept names or ids. Variable products can use pipe-separated variation_values."
        submit-label="Check and import"
        @download-sample="downloadSampleFile"
        @file-change="handleFileChange"
        @submit="submitImport"
        @done="handleImportDone"
    />
</template>
