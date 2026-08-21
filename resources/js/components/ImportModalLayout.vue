<script setup lang="ts">
    import ModalComponent from '@/components/ModalComponent.vue';
    import type { ImportResult } from '@/composables/importHelpers';
    import { computed, ref } from 'vue';

    const props = defineProps({
        title: {
            type: String,
            required: true,
        },
        onOpen: {
            type: Function,
            default: undefined,
        },
        onClose: {
            type: Function,
            default: undefined,
        },
        fileInputId: {
            type: String,
            required: true,
        },
        selectedFile: {
            type: Object as () => File | null,
            default: null,
        },
        fileError: {
            type: String,
            default: '',
        },
        isDownloadingSample: {
            type: Boolean,
            default: false,
        },
        isImporting: {
            type: Boolean,
            default: false,
        },
        leadText: {
            type: String,
            default: 'Required columns must be filled on every row.',
        },
        secondaryText: {
            type: String,
            default: 'Column order does not matter. Leave optional columns blank to use sensible defaults.',
        },
        submitLabel: {
            type: String,
            default: 'Import data',
        },
        importResult: {
            type: Object as () => ImportResult | null,
            default: null,
        },
    });

    const emit = defineEmits<{
        'download-sample': [];
        'file-change': [event: Event];
        submit: [];
        done: [];
    }>();

    const fileInput = ref<HTMLInputElement | null>(null);

    const hasFile = computed(() => props.selectedFile !== null);

    const currentStep = computed(() => {
        if (props.importResult || props.isImporting) {
            return 3;
        }

        if (hasFile.value) {
            return 2;
        }

        return 1;
    });

    const showImportForm = computed(() => props.importResult === null);

    function openFilePicker() {
        fileInput.value?.click();
    }

    function resetFileInput() {
        if (fileInput.value) {
            fileInput.value.value = '';
        }
    }

    function resetAll() {
        resetFileInput();
    }

    function handleModalClose() {
        if (props.importResult?.status === 'success') {
            emit('done');
        }

        props.onClose?.();
    }

    defineExpose({
        resetFileInput,
        resetAll,
    });
</script>

<template>
    <ModalComponent
        id="ImportModal"
        :title="title"
        :onOpen="onOpen"
        :onClose="handleModalClose"
        size="xl"
    >
        <div class="import-modal">
            <div class="import-modal__step-grid">
                <div
                    class="import-modal__step-card"
                    :class="{ 'import-modal__step-card--active': currentStep === 1 }"
                >
                    <span class="import-modal__step-card-title">1. Get the template</span>
                    <span class="import-modal__step-card-text">Download it and fill it in.</span>
                </div>
                <div
                    class="import-modal__step-card"
                    :class="{ 'import-modal__step-card--active': currentStep === 2 }"
                >
                    <span class="import-modal__step-card-title">2. Check</span>
                    <span class="import-modal__step-card-text">Every row is validated before anything is saved.</span>
                </div>
                <div
                    class="import-modal__step-card"
                    :class="{ 'import-modal__step-card--active': currentStep === 3 }"
                >
                    <span class="import-modal__step-card-title">3. Import</span>
                    <span class="import-modal__step-card-text">Only clean rows are written.</span>
                </div>
            </div>

            <div
                v-if="importResult"
                class="import-modal__results"
                :class="importResult.status === 'success'
                    ? 'import-modal__results--success'
                    : 'import-modal__results--error'"
            >
                <h6 class="import-modal__results-title">
                    {{ importResult.status === 'success' ? 'Import complete' : 'Import failed' }}
                </h6>
                <p class="import-modal__results-message">{{ importResult.message }}</p>

                <div class="import-modal__stats-grid">
                    <div class="import-modal__stat">
                        <span class="import-modal__stat-label">Total rows</span>
                        <span class="import-modal__stat-value">{{ importResult.summary.total }}</span>
                    </div>
                    <div class="import-modal__stat import-modal__stat--added">
                        <span class="import-modal__stat-label">Added</span>
                        <span class="import-modal__stat-value">{{ importResult.summary.created }}</span>
                    </div>
                    <div class="import-modal__stat import-modal__stat--updated">
                        <span class="import-modal__stat-label">Updated</span>
                        <span class="import-modal__stat-value">{{ importResult.summary.updated }}</span>
                    </div>
                    <div class="import-modal__stat import-modal__stat--failed">
                        <span class="import-modal__stat-label">Failed</span>
                        <span class="import-modal__stat-value">{{ importResult.summary.failed }}</span>
                    </div>
                </div>

                <ul v-if="importResult.errors.length > 0" class="import-modal__error-list">
                    <li v-for="(error, index) in importResult.errors" :key="`${index}-${error}`">
                        {{ error }}
                    </li>
                </ul>
            </div>

            <template v-if="showImportForm">
                <div class="import-modal__intro">
                    <p class="import-modal__lead">{{ leadText }}</p>
                    <p class="import-modal__secondary">{{ secondaryText }}</p>
                    <div v-if="$slots.help" class="import-modal__help">
                        <slot name="help" />
                    </div>
                </div>

                <div class="import-modal__actions">
                    <button
                        type="button"
                        class="btn btn-dark import-modal__action-btn"
                        :disabled="isDownloadingSample"
                        :aria-busy="isDownloadingSample"
                        @click="emit('download-sample')"
                    >
                        <span
                            v-if="isDownloadingSample"
                            class="spinner-border spinner-border-sm me-1"
                            role="status"
                            aria-hidden="true"
                        ></span>
                        Download Excel template
                    </button>

                    <button
                        type="button"
                        class="btn btn-outline-secondary import-modal__action-btn"
                        @click="openFilePicker"
                    >
                        Choose a file
                    </button>

                    <input
                        :id="fileInputId"
                        ref="fileInput"
                        type="file"
                        class="import-modal__file-input-hidden"
                        accept=".xlsx,.xls,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,text/csv"
                        @change="emit('file-change', $event)"
                    />
                </div>

                <div v-if="hasFile" class="import-modal__selected-file">
                    Selected file: <strong>{{ selectedFile?.name }}</strong>
                </div>

                <p v-if="fileError" class="import-modal__error">
                    {{ fileError }}
                </p>

                <slot name="extra" />
            </template>
        </div>

        <template #footer>
            <button
                v-if="importResult"
                type="button"
                class="btn btn-primary waves-effect"
                data-bs-dismiss="modal"
            >
                Done
            </button>

            <template v-else>
                <button type="button" class="btn btn-light waves-effect" data-bs-dismiss="modal">Cancel</button>

                <button
                    type="button"
                    class="btn btn-primary import-modal__submit-btn d-inline-flex align-items-center"
                    :disabled="!hasFile || isImporting"
                    :aria-busy="isImporting"
                    @click="emit('submit')"
                >
                    <span
                        v-if="isImporting"
                        class="spinner-border spinner-border-sm me-1"
                        role="status"
                        aria-hidden="true"
                    ></span>
                    {{ isImporting ? 'Importing…' : submitLabel }}
                </button>
            </template>
        </template>
    </ModalComponent>
</template>
