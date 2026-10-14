<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import CompanyPicker from '@/components/CompanyPicker.vue';
    import { computed, onMounted, reactive, ref } from 'vue';
    import LookupSelect from '@/components/LookupSelect.vue';
    import useCloseout from '@/composables/closeout';

    defineOptions({
        layout: {
            title: 'Rental',
            subtitle: 'Rental items, booking calendar, deposits, returns with inspection, late and damage charges',
            breadcrumbs: [{ title: 'Rental', href: 'NULL' }],
        },
    });

    type Item = { id: number; product_id: number; name: string; units: number; daily_rate: number; deposit_amount: number; late_fee_per_day: number; is_active: boolean };
    type Booking = { id: number; booking_no: string; item: string; customer: string | null; quantity: number; start_date: string; end_date: string; status: string; rent_amount: number; deposit_amount: number; deposit_received: number; late_charge: number; damage_charge: number; deposit_refund: number; amount_due: number };

    const { can, companyId, isSuperadmin, scope, run, money } = useCloseout();
    const tab = ref<'calendar' | 'bookings' | 'items' | 'settings'>('calendar');
    const items = ref<Item[]>([]);
    const bookings = ref<Booking[]>([]);
    const calendar = ref<any[]>([]);
    const range = reactive({ from: new Date().toISOString().slice(0, 8) + '01', to: new Date(new Date().getFullYear(), new Date().getMonth() + 1, 0).toISOString().slice(0, 10) });
    const settings = reactive<Record<string, any>>({ posting_enabled: false, accounts: {} });
    const slots = ref<string[]>([]);
    const itemForm = reactive({ product_id: '' as number | '', units: 1, daily_rate: 0, deposit_amount: 0, late_fee_per_day: 0 });
    const bookForm = reactive({ rental_item_id: '' as number | '', contact_id: '' as number | '', quantity: 1, start_date: '', end_date: '' });
    const pickup = reactive({ deposit: '' as number | '', payment_account_id: '' as number | '' });
    const giveBack = reactive({ condition: 'good', damage_charge: 0, notes: '', refund_account_id: '' as number | '' });
    const active = ref<number | null>(null);
    const result = ref<Record<string, number> | null>(null);
    const canManage = computed(() => can('/rental/manage'));
    const canBook = computed(() => can('/rental/book'));

    const get = async (url: string, params: Record<string, unknown> = {}) => (await run(() => window.axios.get(url, { params: { ...scope(), ...params } })))?.data;

    async function load() {
        items.value = (await get('/api/rental/items'))?.data ?? [];
        bookings.value = (await get('/api/rental/bookings'))?.data ?? [];
        calendar.value = (await get('/api/rental/calendar', range))?.data ?? [];
        const s = await get('/api/rental/settings');
        Object.assign(settings, s?.data ?? {});
        slots.value = s?.account_slots ?? [];
    }

    async function send(url: string, body: Record<string, unknown>, ok: string, method: 'post' | 'put' = 'post') {
        const response = await run(() => window.axios[method](url, { ...scope(), ...body }), ok);

        if (response) {
            await load();
        }

        return response;
    }

    async function doReturn(id: number) {
        const response = await send(`/api/rental/bookings/${id}/return`, { ...giveBack, refund_account_id: giveBack.refund_account_id || undefined }, 'Returned');
        result.value = response?.data?.data ?? null;
    }

    onMounted(load);
</script>

<template>
    <Head title="Rental" />

    <div class="admin-list-page admin-list-page--simple"><div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
        <CompanyPicker v-if="isSuperadmin" v-model="companyId" @change="load" />

        <ul class="nav nav-tabs mb-3">
            <li v-for="t in [['calendar', 'Calendar'], ['bookings', 'Bookings'], ['items', 'Rental items'], ['settings', 'Settings']]" :key="t[0]" class="nav-item">
                <button type="button" class="nav-link" :class="{ active: tab === t[0] }" @click="tab = t[0] as typeof tab">{{ t[1] }}</button>
            </li>
        </ul>

        <section v-if="tab === 'calendar'">
            <div class="d-flex gap-2 mb-3">
                <input v-model="range.from" type="date" class="form-control form-control-sm" style="width: 10rem" aria-label="From" @change="load" />
                <input v-model="range.to" type="date" class="form-control form-control-sm" style="width: 10rem" aria-label="To" @change="load" />
            </div>
            <div v-for="row in calendar" :key="row.id" class="border rounded p-2 mb-2">
                <div class="d-flex justify-content-between"><strong>{{ row.name }}</strong><span class="small">{{ row.units }} unit(s), {{ row.free_in_range }} free for the whole range</span></div>
                <div v-for="b in row.bookings" :key="b.id" class="small" :class="{ 'text-danger': b.overdue }">
                    {{ b.booking_no }}: {{ String(b.start_date).slice(0, 10) }} to {{ String(b.end_date).slice(0, 10) }} x {{ b.quantity }} - {{ b.customer ?? 'customer' }} ({{ b.status }}<span v-if="b.overdue">, overdue</span>)
                </div>
                <div v-if="! row.bookings.length" class="small text-muted">No bookings in this range.</div>
            </div>
            <div v-if="! calendar.length" class="text-muted">No rental items yet.</div>
        </section>

        <section v-if="tab === 'bookings'">
            <div v-if="canBook" class="border rounded p-3 mb-3">
                <h6>New booking</h6>
                <div class="row g-2">
                    <div class="col-md-3"><select v-model="bookForm.rental_item_id" class="form-select form-select-sm" aria-label="Rental item"><option value="">Item</option><option v-for="i in items.filter((x) => x.is_active)" :key="i.id" :value="i.id">{{ i.name }}</option></select></div>
                    <div class="col-md-2"><LookupSelect v-model="bookForm.contact_id" endpoint="/api/fetchcustomers" :params="scope()" placeholder="Customer" aria-label="Customer" /></div>
                    <div class="col-md-1"><input v-model.number="bookForm.quantity" type="number" class="form-control form-control-sm" aria-label="Quantity" /></div>
                    <div class="col-md-2"><input v-model="bookForm.start_date" type="date" class="form-control form-control-sm" aria-label="Start date" /></div>
                    <div class="col-md-2"><input v-model="bookForm.end_date" type="date" class="form-control form-control-sm" aria-label="End date" /></div>
                    <div class="col-md-2"><button type="button" class="btn btn-dark btn-sm" @click="send('/api/rental/bookings', { ...bookForm }, 'Booked')">Book</button></div>
                </div>
            </div>
            <table class="table table-sm">
                <thead><tr><th>Booking</th><th>Item</th><th>Customer</th><th>Dates</th><th class="text-end">Rent</th><th class="text-end">Deposit</th><th class="text-end">Due</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="b in bookings" :key="b.id">
                        <td>{{ b.booking_no }}</td><td>{{ b.item }} x {{ b.quantity }}</td><td>{{ b.customer ?? '-' }}</td><td>{{ String(b.start_date).slice(0, 10) }} - {{ String(b.end_date).slice(0, 10) }}</td>
                        <td class="text-end">{{ money(b.rent_amount) }}</td><td class="text-end">{{ money(b.deposit_received || b.deposit_amount) }}</td><td class="text-end">{{ money(b.amount_due) }}</td><td class="text-capitalize">{{ b.status }}</td>
                        <td class="text-end"><button v-if="canBook && ['reserved', 'out'].includes(b.status)" type="button" class="btn btn-outline-dark btn-sm" @click="active = b.id; result = null">Handle</button></td>
                    </tr>
                    <tr v-if="! bookings.length"><td colspan="9" class="text-center text-muted">No bookings yet.</td></tr>
                </tbody>
            </table>

            <div v-for="b in bookings.filter((x) => x.id === active)" :key="b.id" class="border rounded p-3">
                <h6>{{ b.booking_no }} - {{ b.status }}</h6>
                <div v-if="b.status === 'reserved'" class="d-flex flex-wrap gap-2">
                    <input v-model.number="pickup.deposit" type="number" class="form-control form-control-sm" style="width: 9rem" :placeholder="'Deposit ' + b.deposit_amount" aria-label="Deposit received" />
                    <div style="width: 14rem"><LookupSelect v-model="pickup.payment_account_id" endpoint="/api/fetchchildaccounts" :params="scope()" placeholder="Cash / bank account" aria-label="Deposit account" /></div>
                    <button type="button" class="btn btn-dark btn-sm" @click="send(`/api/rental/bookings/${b.id}/pickup`, { deposit: pickup.deposit === '' ? undefined : pickup.deposit, payment_account_id: pickup.payment_account_id || undefined }, 'Picked up')">Pick up</button>
                    <button type="button" class="btn btn-outline-danger btn-sm" @click="send(`/api/rental/bookings/${b.id}/cancel`, {}, 'Booking cancelled')">Cancel booking</button>
                </div>
                <div v-if="b.status === 'out'" class="d-flex flex-wrap gap-2">
                    <select v-model="giveBack.condition" class="form-select form-select-sm" style="width: 10rem" aria-label="Condition"><option value="good">Good</option><option value="minor_damage">Minor damage</option><option value="major_damage">Major damage</option></select>
                    <input v-model.number="giveBack.damage_charge" type="number" class="form-control form-control-sm" style="width: 9rem" placeholder="Damage charge" aria-label="Damage charge" />
                    <input v-model="giveBack.notes" class="form-control form-control-sm" style="width: 14rem" placeholder="Inspection notes" aria-label="Inspection notes" />
                    <div style="width: 14rem"><LookupSelect v-model="giveBack.refund_account_id" endpoint="/api/fetchchildaccounts" :params="scope()" placeholder="Refund account" aria-label="Refund account" /></div>
                    <button type="button" class="btn btn-dark btn-sm" @click="doReturn(b.id)">Receive back</button>
                </div>
                <div v-if="result" class="small mt-2">
                    Late {{ result.late_days }} day(s): {{ money(result.late_charge) }}; damage {{ money(result.damage_charge) }}; charges {{ money(result.charges) }};
                    deposit applied {{ money(result.deposit_applied) }}, refund {{ money(result.deposit_refund) }}, <strong>due {{ money(result.amount_due) }}</strong>
                </div>
            </div>
        </section>

        <section v-if="tab === 'items'">
            <div v-if="canManage" class="border rounded p-3 mb-3">
                <div class="row g-2">
                    <div class="col-md-2"><LookupSelect v-model="itemForm.product_id" endpoint="/api/fetchproducts" :params="scope()" placeholder="Product" aria-label="Product" /></div>
                    <div class="col-md-1"><input v-model.number="itemForm.units" type="number" class="form-control form-control-sm" aria-label="Units" /></div>
                    <div class="col-md-2"><input v-model.number="itemForm.daily_rate" type="number" class="form-control form-control-sm" placeholder="Daily rate" aria-label="Daily rate" /></div>
                    <div class="col-md-2"><input v-model.number="itemForm.deposit_amount" type="number" class="form-control form-control-sm" placeholder="Deposit per unit" aria-label="Deposit per unit" /></div>
                    <div class="col-md-2"><input v-model.number="itemForm.late_fee_per_day" type="number" class="form-control form-control-sm" placeholder="Late fee per day" aria-label="Late fee per day" /></div>
                    <div class="col-md-2"><button type="button" class="btn btn-dark btn-sm" @click="send('/api/rental/items', { ...itemForm }, 'Rental item saved')">Save item</button></div>
                </div>
            </div>
            <table class="table table-sm">
                <thead><tr><th>Item</th><th class="text-end">Units</th><th class="text-end">Daily rate</th><th class="text-end">Deposit</th><th class="text-end">Late fee / day</th></tr></thead>
                <tbody><tr v-for="i in items" :key="i.id"><td>{{ i.name }}</td><td class="text-end">{{ i.units }}</td><td class="text-end">{{ money(i.daily_rate) }}</td><td class="text-end">{{ money(i.deposit_amount) }}</td><td class="text-end">{{ money(i.late_fee_per_day) }}</td></tr></tbody>
            </table>
        </section>

        <section v-if="tab === 'settings'">
            <div class="alert alert-info small">Bookings, deposits and charges are worked out and kept on every booking. Ledger entries are made only when you switch posting on and map the four accounts below (chart of accounts).</div>
            <div class="row g-2 align-items-end">
                <div class="col-md-3 form-check ms-2"><input id="rn-post" v-model="settings.posting_enabled" type="checkbox" class="form-check-input" :disabled="! canManage" /><label class="form-check-label small" for="rn-post">Post rentals to the ledger</label></div>
                <div v-for="slot in slots" :key="slot" class="col-md-3">
                    <label class="form-label small text-capitalize" :for="`rn-acc-${slot}`">{{ slot.replace(/_/g, ' ') }} account</label>
                    <LookupSelect v-model="settings.accounts[slot]" endpoint="/api/fetchchildaccounts" :params="scope()" placeholder="Choose an account" :aria-label="`${slot.replace(/_/g, ' ')} account`" :disabled="! canManage" />
                </div>
                <div v-if="canManage" class="col-md-2"><button type="button" class="btn btn-dark btn-sm" @click="send('/api/rental/settings', { ...settings }, 'Settings saved', 'put')">Save settings</button></div>
            </div>
        </section>
    </div></div></div>
</template>
