import { API_ENDPOINTS } from '@/composables/apiEndpoints';
import { ref } from 'vue';

export type LedgerWatchUntil = {
    row_id: string;
    voucher_date: string;
    voucher_no?: string | null;
} | null;

export type LedgerWatchRow = {
    id: number | string;
    voucher_date?: string;
    voucher_no?: string;
};

export default function useLedgerWatch() {
    const watchedUntil = ref<LedgerWatchUntil>(null);
    const watchSaving = ref(false);

    function applyWatchedUntil(value: LedgerWatchUntil | undefined): void {
        watchedUntil.value = value ?? null;
    }

    function isRowWatched(_rows: LedgerWatchRow[], row: LedgerWatchRow): boolean {
        return watchedUntil.value !== null && String(watchedUntil.value.row_id) === String(row.id);
    }

    async function saveWatchedUntil(
        contactId: string | number | null | undefined,
        row: LedgerWatchRow | null,
    ): Promise<void> {
        if (!contactId) {
            return;
        }

        watchSaving.value = true;

        try {
            const response = await window.axios.post(API_ENDPOINTS.contactLedgerWatches, {
                contact_id: contactId,
                row_id: row ? String(row.id) : null,
                voucher_date: row?.voucher_date ? String(row.voucher_date).slice(0, 10) : null,
                voucher_no: row?.voucher_no ?? null,
            });

            watchedUntil.value = response.data?.watched_until ?? null;
        } finally {
            watchSaving.value = false;
        }
    }

    async function toggleRowWatch(
        contactId: string | number | null | undefined,
        rows: LedgerWatchRow[],
        row: LedgerWatchRow,
    ): Promise<void> {
        if (isRowWatched(rows, row)) {
            await saveWatchedUntil(contactId, null);

            return;
        }

        await saveWatchedUntil(contactId, row);
    }

    return {
        watchedUntil,
        watchSaving,
        applyWatchedUntil,
        isRowWatched,
        toggleRowWatch,
    };
}
