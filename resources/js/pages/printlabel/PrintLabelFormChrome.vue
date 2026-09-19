<script setup lang="ts">
    import { Link } from '@inertiajs/vue3';
    import { ChevronLeft, Home, Printer } from '@lucide/vue';
    import { dashboard } from '@/routes';

    withDefaults(defineProps<{
        isBusy: boolean;
        isWorking: boolean;
        printDisabled?: boolean;
    }>(), {
        printDisabled: false,
    });

    const emit = defineEmits<{
        print: [];
    }>();
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
                            <span class="font-semibold text-teal-800">Print Label</span>
                        </div>
                        <h1 class="flex items-center gap-2 text-lg font-bold tracking-tight text-slate-900 sm:text-xl">
                            <span>Print Product Labels</span>
                        </h1>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-teal-700 px-4 py-1.5 text-xs font-semibold whitespace-nowrap text-white shadow-xs transition-all hover:bg-teal-800 hover:shadow active:bg-teal-900 disabled:opacity-50"
                            :disabled="isBusy || printDisabled"
                            :aria-busy="isWorking"
                            @click="emit('print')"
                        >
                            <span
                                v-if="isWorking"
                                class="spinner-border spinner-border-sm"
                                role="status"
                                aria-hidden="true"
                            ></span>
                            <Printer v-else class="h-3.5 w-3.5" />
                            <span>{{ isWorking ? 'Preparing…' : 'Print Labels' }}</span>
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
