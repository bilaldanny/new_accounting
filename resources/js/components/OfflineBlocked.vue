<script setup lang="ts">
    import { router } from '@inertiajs/vue3';
    import { onBeforeUnmount, onMounted, ref } from 'vue';
    import { offline, ping } from '@/lib/offline/engine';

    /**
     * The friendly screen for a page that needs the internet: when a visit fails because there is no network, this says so in plain
     * words, lists what still works offline and how many entries are waiting, instead of leaving a broken page or a raw error.
     */
    const shown = ref(false);
    const target = ref('');
    let off: (() => void) | null = null;

    onMounted(() => {
        off = router.on('networkError', async (event) => {
            if (! (await ping())) {
                const url = (event as CustomEvent).detail?.url ?? (event as CustomEvent).detail?.request?.url;
                target.value = String(url ?? '');
                shown.value = true;
                offline.online = false;
                (event as Event).preventDefault?.();
            }
        });
    });

    onBeforeUnmount(() => off?.());

    function close() {
        shown.value = false;
    }
</script>

<template>
    <div v-if="shown" class="offline-blocked" role="alertdialog" aria-labelledby="offline-blocked-title" data-test="offline-blocked">
        <div class="offline-blocked__card">
            <h2 id="offline-blocked-title" class="h5">This page needs the internet</h2>
            <p class="mb-2">You are offline right now, so this page cannot open. Nothing you did has been lost.</p>
            <p class="mb-1 fw-semibold">These still work without internet:</p>
            <ul class="small">
                <li><a href="/sell/pos/offline">Offline POS terminal</a></li>
                <li><a href="/offline">Offline entries</a>: sales invoice, purchase, payment or receipt, expense, new customer or supplier, stock adjustment (sent for approval)</li>
            </ul>
            <p class="small mb-3" data-test="offline-blocked-pending">
                {{ offline.pending + offline.posPending }} {{ offline.pending + offline.posPending === 1 ? 'entry is' : 'entries are' }} waiting to be sent{{ offline.conflicts + offline.posConflicts > 0 ? `, ${offline.conflicts + offline.posConflicts} need a decision` : '' }}. They are sent on their own when the internet is back.
            </p>
            <div class="d-flex gap-2">
                <a href="/offline" class="btn btn-dark btn-sm">Open offline entries</a>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-test="offline-blocked-close" @click="close">Close</button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.offline-blocked {
    position: fixed;
    inset: 0;
    z-index: 2000;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(15, 23, 42, 0.55);
}

.offline-blocked__card {
    max-width: 30rem;
    width: calc(100% - 2rem);
    padding: 1.25rem 1.5rem;
    border-radius: 0.75rem;
    background: #fff;
    color: #1e293b;
}
</style>
