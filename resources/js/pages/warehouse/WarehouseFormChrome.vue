<script setup lang="ts">
    import { Link } from '@inertiajs/vue3';
    import { Check, ChevronLeft, Home } from '@lucide/vue';
    import { computed } from 'vue';
    import { dashboard } from '@/routes';

    const props = withDefaults(defineProps<{
        mode: 'add' | 'edit';
        isBusy: boolean;
        isWorking: boolean;
        saveAction: 'close' | 'add-new';
    }>(), {});

    const emit = defineEmits<{
        cancel: [];
        save: [action: 'close' | 'add-new'];
    }>();

    const listTitle = 'Warehouse';
    const crumbCurrent = computed(() => (props.mode === 'edit' ? 'Edit Warehouse' : 'Add Warehouse'));
    const pageTitle = computed(() => (props.mode === 'edit' ? 'Edit Warehouse' : 'New Warehouse'));
</script>

<template>
    <div class="product-form-view purchase-form-view min-h-screen bg-slate-50/70 pb-8 font-sans text-slate-800">
        <div class="product-form-view__inner mx-auto max-w-[1600px] px-4 sm:px-6 lg:px-8">
            <div class="product-form-view__top sticky top-0 z-20 border-b border-slate-200/90 bg-white/95 py-3.5 backdrop-blur-md">
                <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                    <div>
                        <div class="mb-0.5 flex items-center gap-1.5 text-xs font-medium text-slate-500">
                            <Link
                                :href="dashboard()"
                                class="flex cursor-pointer items-center gap-1 transition-colors hover:text-teal-700"
                            >
                                <Home class="h-3.5 w-3.5 text-slate-400" />
                                <span>Home</span>
                            </Link>
                            <ChevronLeft class="h-3 w-3 rotate-180 text-slate-300" />
                            <button
                                type="button"
                                class="cursor-pointer text-slate-400 transition-colors hover:text-teal-700"
                                :disabled="isBusy"
                                @click="emit('cancel')"
                            >
                                {{ listTitle }}
                            </button>
                            <ChevronLeft class="h-3 w-3 rotate-180 text-slate-300" />
                            <span class="font-semibold text-teal-800">{{ crumbCurrent }}</span>
                        </div>
                        <h1 class="flex items-center gap-2 text-lg font-bold tracking-tight text-slate-900 sm:text-xl">
                            <span>{{ pageTitle }}</span>
                        </h1>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="cursor-pointer rounded-lg border border-slate-200 bg-white px-3.5 py-1.5 text-xs font-semibold text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition-colors hover:bg-slate-50"
                            :disabled="isBusy"
                            @click="emit('cancel')"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-teal-200 bg-teal-50 px-3.5 py-1.5 text-xs font-semibold whitespace-nowrap text-teal-800 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition-colors hover:bg-teal-100/80 disabled:opacity-50"
                            :disabled="isBusy"
                            :aria-busy="isWorking && saveAction === 'add-new'"
                            @click="emit('save', 'add-new')"
                        >
                            <span
                                v-if="isWorking && saveAction === 'add-new'"
                                class="spinner-border spinner-border-sm"
                                role="status"
                                aria-hidden="true"
                            ></span>
                            <span>{{ isWorking && saveAction === 'add-new' ? 'Saving…' : 'Save & Add New' }}</span>
                        </button>
                        <button
                            type="button"
                            class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-teal-700 px-4 py-1.5 text-xs font-semibold whitespace-nowrap text-white shadow-xs transition-all hover:bg-teal-800 hover:shadow active:bg-teal-900 disabled:opacity-50"
                            :disabled="isBusy"
                            :aria-busy="isWorking && saveAction === 'close'"
                            @click="emit('save', 'close')"
                        >
                            <span
                                v-if="isWorking && saveAction === 'close'"
                                class="spinner-border spinner-border-sm"
                                role="status"
                                aria-hidden="true"
                            ></span>
                            <Check v-else class="h-3.5 w-3.5" />
                            <span>{{ isWorking && saveAction === 'close' ? 'Saving…' : 'Save & Close' }}</span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="product-form-view__body space-y-5 py-5">
                <slot />
            </div>
        </div>
    </div>
</template>
