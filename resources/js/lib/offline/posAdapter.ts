import type { QueuedSale } from '@/lib/posOfflineStore';
import { posOfflineStore } from '@/lib/posOfflineStore';

/**
 * The offline POS terminal's queue seen by the shared offline engine: its sales are counted in the header chip and sent by the same
 * runner as every other entry, with the same code the terminal page used (one implementation, not two). A sale made at the terminal
 * belongs to the user who rang it up; one made before that was recorded (no owner) is anybody's, as it always was.
 */
export function belongsTo(sale: QueuedSale, userId: number): boolean {
    return sale.user_id === undefined || sale.user_id === null || sale.user_id === userId;
}

export async function posSales(userId: number): Promise<QueuedSale[]> {
    return (await posOfflineStore.allSales()).filter((sale) => belongsTo(sale, userId));
}

export async function posCounts(userId: number): Promise<{ pending: number; conflicts: number }> {
    const sales = await posSales(userId);

    return {
        pending: sales.filter((sale) => sale.status === 'pending').length,
        conflicts: sales.filter((sale) => sale.status === 'conflict' || sale.status === 'failed').length,
    };
}

/**
 * Sends the waiting terminal sales in order. `login` = the session ended (the sales stay), `network` = no network or a server error
 * (they stay, in order), `done` = every waiting sale got an answer.
 */
export async function syncPosQueue(userId: number): Promise<'done' | 'login' | 'network'> {
    for (const sale of (await posSales(userId)).filter((row) => row.status === 'pending')) {
        try {
            const response = await window.axios.post('/api/pos/offline/sync', {
                client_uuid: sale.client_uuid, device_id: sale.device_id, offline_receipt_no: sale.offline_receipt_no, sold_at: sale.sold_at,
                catalog_version: sale.catalog_version, contact_id: sale.contact_id, total: sale.total,
                lines: sale.lines.map(({ name, ...line }) => line), payments: sale.payments,
            });
            const body = response.data as { status: QueuedSale['status']; invoice_no?: string; conflict_code?: string; oversold?: unknown[] };
            await posOfflineStore.putSale({ ...sale, status: body.status, invoice_no: body.invoice_no ?? null, conflict_code: body.conflict_code ?? null, oversold: body.oversold ?? [], message: null });
        } catch (error: unknown) {
            const failure = error as { response?: { status?: number; data?: { message?: string } } };
            const status = failure.response?.status;

            if (status === 422) {
                await posOfflineStore.putSale({ ...sale, status: 'failed', message: failure.response?.data?.message ?? 'The server refused this sale.' });

                continue;
            }

            return status === 401 || status === 419 ? 'login' : 'network';
        }
    }

    return 'done';
}
