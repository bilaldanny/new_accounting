<script setup lang="ts">
import { Cog, X } from '@boxicons/vue';

export type TableDensity = 'compact' | 'comfortable' | 'spacious';

const open = defineModel<boolean>('open', { default: false });

const density = defineModel<TableDensity>('density', { default: 'compact' });
const zebraStripes = defineModel<boolean>('zebraStripes', { default: false });
const stickyHeader = defineModel<boolean>('stickyHeader', { default: true });
const pageSize = defineModel<number>('pageSize', { default: 10 });

const densities: TableDensity[] = ['compact', 'comfortable', 'spacious'];
const pageSizes = [10, 25, 50, 100];

function close(): void {
    open.value = false;
}

function resetDefaults(): void {
    density.value = 'compact';
    zebraStripes.value = false;
    stickyHeader.value = true;
    pageSize.value = 10;
}
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="table-pref-drawer">
            <button type="button" class="table-pref-drawer__backdrop" aria-label="Close table preferences" @click="close" />
            <aside class="table-pref-drawer__panel" role="dialog" aria-labelledby="table-pref-title">
                <div class="table-pref-drawer__header">
                    <div class="table-pref-drawer__heading">
                        <span class="table-pref-drawer__icon" aria-hidden="true">
                            <Cog size="sm" />
                        </span>
                        <div>
                            <h3 id="table-pref-title">Table Preferences</h3>
                            <p>Customize display &amp; density</p>
                        </div>
                    </div>
                    <button type="button" class="table-pref-drawer__close" title="Close" @click="close">
                        <X size="sm" />
                    </button>
                </div>

                <div class="table-pref-drawer__body">
                    <section>
                        <h4>Row density</h4>
                        <div class="table-pref-drawer__pills">
                            <button
                                v-for="option in densities"
                                :key="option"
                                type="button"
                                class="table-pref-drawer__pill"
                                :class="{ 'is-active': density === option }"
                                @click="density = option"
                            >
                                {{ option }}
                            </button>
                        </div>
                    </section>

                    <section>
                        <h4>Rows per page</h4>
                        <div class="table-pref-drawer__pills">
                            <button
                                v-for="size in pageSizes"
                                :key="size"
                                type="button"
                                class="table-pref-drawer__pill"
                                :class="{ 'is-active': Number(pageSize) === size }"
                                @click="pageSize = size"
                            >
                                {{ size }}
                            </button>
                        </div>
                    </section>

                    <section class="table-pref-drawer__toggles">
                        <label>
                            <input v-model="zebraStripes" type="checkbox">
                            Zebra stripes
                        </label>
                        <label>
                            <input v-model="stickyHeader" type="checkbox">
                            Sticky header
                        </label>
                    </section>
                </div>

                <div class="table-pref-drawer__footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="resetDefaults">
                        Reset defaults
                    </button>
                    <button type="button" class="btn btn-sm btn-primary" @click="close">
                        Done
                    </button>
                </div>
            </aside>
        </div>
    </Teleport>
</template>
