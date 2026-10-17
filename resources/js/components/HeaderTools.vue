<script setup lang="ts">
    import { usePage } from '@inertiajs/vue3';
    import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
    import { notificationSoundOn, setNotificationSound, syncNotificationSound } from '@/composables/notificationSound';

    /**
     * The tools in the top bar, left to right: today's date (company time zone), Today Profit, the POS shortcut, the calculator and a
     * menu with the calendar, the to-do list and the application tour. Each one is shown only when the user may use it; on a phone the
     * buttons collapse into the menu.
     */
    const page = usePage();
    const user = computed(() => (page.props as any)?.auth?.user as { id?: number; rolename?: string; permission_paths?: string[] } | null);
    const isSuperadmin = computed(() => String(user.value?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin');
    const can = (path: string): boolean => isSuperadmin.value || (user.value?.permission_paths ?? []).includes(path);

    /** The speaker switch: sounds of notifications (alerts, approvals, reminders, to-dos). The scanner beep at the till is separate. */
    const soundLabel = computed(() => (notificationSoundOn.value
        ? 'Notification sound is on. Press to turn it off (the POS scan beep has its own setting)'
        : 'Notification sound is off. Press to turn it on (the POS scan beep has its own setting)'));

    function toggleSound() {
        void setNotificationSound(! notificationSoundOn.value);
    }

    const dateLabel = ref('');
    const open = ref<'' | 'profit' | 'calc' | 'calendar' | 'todo'>('');
    const menuOpen = ref(false);

    const money = (value: number | string | null | undefined): string => Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    /** The date and the pending to-do count come in one request, a moment after the page's own, so the top bar never delays a screen. */
    async function loadSummary() {
        try {
            const response = await window.axios.get('/api/header/summary');
            dateLabel.value = response.data?.data?.label ?? '';
            pending.value = Number(response.data?.data?.pending_todos ?? 0);
        } catch {
            dateLabel.value = new Date().toLocaleDateString(undefined, { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' });
        }
    }

    // ---- Today Profit
    type Figures = { sales: number; purchases: number; expenses: number; gross_profit: number; net_profit: number };
    const profit = ref<{ date: string; yesterday: string; today: Figures; previous: Figures; change: Record<string, number> } | null>(null);
    const profitError = ref('');

    async function openProfit() {
        open.value = 'profit';
        profit.value = null;
        profitError.value = '';

        try {
            const response = await window.axios.get('/api/header/today-profit');
            profit.value = response.data?.data ?? null;
        } catch (error: any) {
            profitError.value = error?.response?.data?.message ?? 'The figures could not be loaded.';
        }
    }

    const profitRows = computed(() => profit.value === null ? [] : [
        { key: 'sales', label: 'Sales' }, { key: 'purchases', label: 'Purchases' }, { key: 'expenses', label: 'Expenses' },
        { key: 'gross_profit', label: 'Gross profit' }, { key: 'net_profit', label: 'Net profit' },
    ].map((row) => ({ ...row, today: (profit.value!.today as any)[row.key] as number, previous: (profit.value!.previous as any)[row.key] as number })));

    // ---- Calculator
    const calcDisplay = ref('0');
    const calcMemory = ref<number | null>(null);
    const calcOperator = ref<string | null>(null);
    const calcFresh = ref(true);
    const calcBox = ref<HTMLElement | null>(null);

    function calcDigit(digit: string) {
        if (calcFresh.value) {
            calcDisplay.value = digit === '.' ? '0.' : digit;
            calcFresh.value = false;

            return;
        }

        if (digit === '.' && calcDisplay.value.includes('.')) {
            return;
        }

        calcDisplay.value = calcDisplay.value === '0' && digit !== '.' ? digit : calcDisplay.value + digit;
    }

    function calcApply(left: number, operator: string, right: number): number {
        switch (operator) {
            case '+': return left + right;
            case '-': return left - right;
            case '*': return left * right;
            case '/': return right === 0 ? NaN : left / right;
            default: return right;
        }
    }

    function calcShow(value: number) {
        calcDisplay.value = Number.isFinite(value) ? String(Math.round(value * 1e10) / 1e10) : 'Error';
    }

    function calcOp(operator: string) {
        const current = Number(calcDisplay.value);

        if (calcOperator.value !== null && ! calcFresh.value && calcMemory.value !== null) {
            const result = calcApply(calcMemory.value, calcOperator.value, current);
            calcShow(result);
            calcMemory.value = Number.isFinite(result) ? result : null;
        } else {
            calcMemory.value = Number.isFinite(current) ? current : 0;
        }

        calcOperator.value = operator;
        calcFresh.value = true;
    }

    function calcEquals() {
        if (calcOperator.value === null || calcMemory.value === null) {
            return;
        }

        calcShow(calcApply(calcMemory.value, calcOperator.value, Number(calcDisplay.value)));
        calcOperator.value = null;
        calcMemory.value = null;
        calcFresh.value = true;
    }

    /** % on its own is a hundredth; after an operator it is that share of the first number (200 + 10% = 220). */
    function calcPercent() {
        const current = Number(calcDisplay.value);

        if (calcOperator.value !== null && calcMemory.value !== null && ['+', '-'].includes(calcOperator.value)) {
            calcShow(calcMemory.value * current / 100);
        } else {
            calcShow(current / 100);
        }

        calcFresh.value = false;
    }

    function calcClear() {
        calcDisplay.value = '0';
        calcMemory.value = null;
        calcOperator.value = null;
        calcFresh.value = true;
    }

    function calcBack() {
        calcDisplay.value = calcDisplay.value.length > 1 && ! calcFresh.value ? calcDisplay.value.slice(0, -1) : '0';
    }

    function calcKey(event: KeyboardEvent) {
        const key = event.key;

        if (/^[0-9.]$/.test(key)) {
            calcDigit(key);
        } else if (['+', '-', '*', '/'].includes(key)) {
            calcOp(key);
        } else if (key === 'Enter' || key === '=') {
            calcEquals();
        } else if (key === '%') {
            calcPercent();
        } else if (key === 'Backspace') {
            calcBack();
        } else if (key === 'Delete' || key.toLowerCase() === 'c') {
            calcClear();
        } else {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
    }

    // ---- Calendar
    const month = ref(new Date().toISOString().slice(0, 7));
    const events = ref<{ date: string; kind: string; title: string }[]>([]);
    const selectedDay = ref('');

    async function loadCalendar() {
        try {
            const response = await window.axios.get('/api/header/calendar', { params: { month: month.value } });
            events.value = response.data?.data?.events ?? [];
        } catch {
            events.value = [];
        }
    }

    function shiftMonth(by: number) {
        const [year, mon] = month.value.split('-').map(Number);
        const next = new Date(Date.UTC(year, mon - 1 + by, 1));
        month.value = next.toISOString().slice(0, 7);
    }

    const monthLabel = computed(() => new Date(`${month.value}-01T00:00:00Z`).toLocaleDateString(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' }));
    const calendarCells = computed(() => {
        const [year, mon] = month.value.split('-').map(Number);
        const first = new Date(Date.UTC(year, mon - 1, 1));
        const days = new Date(Date.UTC(year, mon, 0)).getUTCDate();
        const cells: { day: number | null; date: string }[] = [];

        for (let i = 0; i < first.getUTCDay(); i++) {
            cells.push({ day: null, date: '' });
        }

        for (let day = 1; day <= days; day++) {
            cells.push({ day, date: `${month.value}-${String(day).padStart(2, '0')}` });
        }

        return cells;
    });
    const kindsOn = (date: string) => Array.from(new Set(events.value.filter((event) => event.date === date).map((event) => event.kind)));
    const dayEvents = computed(() => events.value.filter((event) => event.date === selectedDay.value));
    const kindColour: Record<string, string> = { followup: '#2563eb', leave: '#d97706', due: '#dc2626', todo: '#0f766e', holiday: '#7c3aed' };
    const kindLabel: Record<string, string> = { followup: 'Follow-up', leave: 'Leave', due: 'Invoice due', todo: 'To-do', holiday: 'Holiday' };

    watch(month, loadCalendar);

    // ---- To-do
    const todos = ref<{ id: number; title: string; due_date: string | null; done_at: string | null }[]>([]);
    const pending = ref(0);
    const todoTitle = ref('');
    const todoDue = ref('');

    async function loadTodos() {
        try {
            const response = await window.axios.get('/api/header/todos');
            todos.value = response.data?.data ?? [];
            pending.value = Number(response.data?.pending ?? 0);
        } catch {
            todos.value = [];
            pending.value = 0;
        }
    }

    async function addTodo() {
        const title = todoTitle.value.trim();

        if (title === '') {
            return;
        }

        try {
            await window.axios.post('/api/header/todos', { title, due_date: todoDue.value || null });
            todoTitle.value = '';
            todoDue.value = '';
            await loadTodos();
        } catch {
            // the list stays as it was
        }
    }

    async function toggleTodo(id: number) {
        await window.axios.post(`/api/header/todos/${id}/toggle`).catch(() => undefined);
        await loadTodos();
    }

    async function removeTodo(id: number) {
        await window.axios.delete(`/api/header/todos/${id}`).catch(() => undefined);
        await loadTodos();
    }

    // ---- Application tour
    type Step = { selector: string; title: string; text: string };
    const TOURS: Record<string, Step[]> = {
        default: [
            { selector: '.topbar-search', title: 'Search', text: 'Find pages, customers, products and invoices. Press / to jump here.' },
            { selector: '.sidebar-wrapper, .sidebar, aside', title: 'Menu', text: 'Every module is in the menu on the left; groups open to show their pages.' },
            { selector: '.header-tools', title: 'Quick tools', text: "Today's profit, the POS, a calculator, the calendar and your to-do list live here." },
            { selector: '.topbar-bell', title: 'Notifications', text: 'Approvals and alerts that are waiting for you.' },
        ],
        '/dashboard': [
            { selector: '.topbar-search', title: 'Search', text: 'Find anything from here.' },
            { selector: '.page-content, main', title: 'Dashboard', text: 'Sales, purchases, profit and what needs attention, for the company or your branch.' },
        ],
        '/sell/pos/add': [
            { selector: '[data-test="pos-search"]', title: 'Search or scan', text: 'Type a name or scan a barcode. F2 comes back here.' },
            { selector: '.pos-cart-panel', title: 'Cart', text: 'Lines you sell. + and - change the quantity, Delete removes the line.' },
            { selector: '[data-test="pos-scan-status"]', title: 'Scanner', text: 'Scanner settings and the camera scan are here; F1 lists every key.' },
        ],
    };

    const tourOpen = ref(false);
    const tourIndex = ref(0);
    const tourRect = ref<{ top: number; left: number; width: number; height: number } | null>(null);
    const tourOffer = ref(false);
    const tourSteps = computed(() => {
        const path = window.location.pathname.replace(/\/+$/, '') || '/';

        return (TOURS[path] ?? TOURS.default).filter((step) => document.querySelector(step.selector) !== null);
    });
    const tourKey = computed(() => `app-tour-seen:${user.value?.id ?? 0}`);

    function placeTour() {
        const step = tourSteps.value[tourIndex.value];
        const element = step ? document.querySelector(step.selector) as HTMLElement | null : null;

        if (! element) {
            tourRect.value = null;

            return;
        }

        element.scrollIntoView({ block: 'center', behavior: 'smooth' });
        window.setTimeout(() => {
            const box = element.getBoundingClientRect();
            tourRect.value = { top: box.top, left: box.left, width: box.width, height: box.height };
        }, 250);
    }

    function startTour() {
        menuOpen.value = false;
        tourOffer.value = false;

        if (tourSteps.value.length === 0) {
            return;
        }

        tourIndex.value = 0;
        tourOpen.value = true;
        void nextTick(placeTour);
    }

    function stepTour(by: number) {
        const next = tourIndex.value + by;

        if (next < 0) {
            return;
        }

        if (next >= tourSteps.value.length) {
            endTour();

            return;
        }

        tourIndex.value = next;
        placeTour();
    }

    function endTour() {
        tourOpen.value = false;
        tourOffer.value = false;

        try {
            window.localStorage.setItem(tourKey.value, '1');
        } catch {
            // the offer may come again
        }
    }

    function dismissOffer() {
        tourOffer.value = false;
        endTour();
    }

    // ---- shared
    function close() {
        open.value = '';
    }

    function onKeydown(event: KeyboardEvent) {
        if (event.key === 'Escape') {
            if (tourOpen.value) {
                endTour();
            } else if (open.value !== '') {
                close();
            } else {
                menuOpen.value = false;
            }
        }
    }

    function onDocumentClick(event: MouseEvent) {
        if (! (event.target as HTMLElement | null)?.closest('.header-tools__menu')) {
            menuOpen.value = false;
        }
    }

    watch(open, async (value) => {
        if (value === 'calendar') {
            await loadCalendar();
        }

        if (value === 'todo') {
            await loadTodos();
        }

        if (value === 'calc') {
            await nextTick();
            calcBox.value?.focus();
        }
    });

    onMounted(() => {
        syncNotificationSound((user.value as { notification_sound?: boolean } | null)?.notification_sound);
        dateLabel.value = new Date().toLocaleDateString(undefined, { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' });
        window.setTimeout(() => void loadSummary(), 1500);
        document.addEventListener('keydown', onKeydown);
        document.addEventListener('click', onDocumentClick);

        try {
            tourOffer.value = window.localStorage.getItem(tourKey.value) === null && window.location.pathname.startsWith('/dashboard');
        } catch {
            tourOffer.value = false;
        }
    });

    onBeforeUnmount(() => {
        document.removeEventListener('keydown', onKeydown);
        document.removeEventListener('click', onDocumentClick);
    });
</script>

<template>
    <div class="header-tools d-flex align-items-center gap-2" data-test="header-tools">
        <span class="header-tools__date d-none d-xl-inline small text-nowrap" data-test="header-date">{{ dateLabel }}</span>

        <button v-if="can('/report/profit-loss')" type="button" class="btn btn-sm header-tools__btn d-none d-md-inline-flex" data-test="today-profit-button" @click="openProfit">Today Profit</button>
        <a v-if="can('/sell/pos')" href="/sell/pos/add" class="btn btn-sm header-tools__btn d-none d-md-inline-flex" data-test="pos-shortcut">POS</a>
        <button type="button" class="btn btn-sm header-tools__btn d-none d-md-inline-flex" data-test="calculator-button" title="Calculator" @click="open = 'calc'">Calculator</button>

        <div class="header-tools__menu position-relative">
            <button type="button" class="btn btn-sm header-tools__btn" data-test="header-more" aria-haspopup="true" :aria-expanded="menuOpen" @click.stop="menuOpen = ! menuOpen">
                Tools<span v-if="pending > 0" class="badge text-bg-danger ms-1" data-test="todo-count">{{ pending }}</span>
            </button>
            <ul v-if="menuOpen" class="dropdown-menu show" style="position: absolute; right: 0; top: 100%; min-width: 12rem" role="menu">
                <li class="d-md-none"><button v-if="can('/report/profit-loss')" type="button" class="dropdown-item" @click="menuOpen = false; openProfit()">Today Profit</button></li>
                <li class="d-md-none"><a v-if="can('/sell/pos')" class="dropdown-item" href="/sell/pos/add">POS</a></li>
                <li class="d-md-none"><button type="button" class="dropdown-item" @click="menuOpen = false; open = 'calc'">Calculator</button></li>
                <li><button type="button" class="dropdown-item" data-test="open-calendar" @click="menuOpen = false; open = 'calendar'">Calendar</button></li>
                <li><button type="button" class="dropdown-item" data-test="open-todo" @click="menuOpen = false; open = 'todo'">Add To-Do <span v-if="pending > 0" class="badge text-bg-secondary ms-1">{{ pending }}</span></button></li>
                <li><button type="button" class="dropdown-item" data-test="start-tour" @click="startTour">Application Tour</button></li>
            </ul>
        </div>

        <button type="button" class="btn btn-sm header-tools__btn header-tools__sound" data-test="sound-toggle" :aria-pressed="notificationSoundOn" :aria-label="soundLabel" :title="soundLabel" @click="toggleSound">
            <svg v-if="notificationSoundOn" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5" /><path d="M15.54 8.46a5 5 0 0 1 0 7.07" /><path d="M19.07 4.93a10 10 0 0 1 0 14.14" /></svg>
            <svg v-else width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5" /><line x1="23" y1="9" x2="17" y2="15" /><line x1="17" y1="9" x2="23" y2="15" /></svg>
        </button>

        <Teleport to="body">
            <!-- first sign-in offer -->
            <div v-if="tourOffer && ! tourOpen" class="header-tools__offer card shadow p-3" role="dialog" aria-label="Application tour" data-test="tour-offer">
                <strong>New here?</strong>
                <p class="small mb-2">Take a one-minute tour of this screen.</p>
                <div class="d-flex gap-2"><button type="button" class="btn btn-dark btn-sm" @click="startTour">Start tour</button><button type="button" class="btn btn-outline-secondary btn-sm" @click="dismissOffer">Skip</button></div>
            </div>

            <!-- tour -->
            <div v-if="tourOpen" class="header-tools__tour" data-test="tour">
                <div v-if="tourRect" class="header-tools__spot" :style="{ top: `${tourRect.top - 6}px`, left: `${tourRect.left - 6}px`, width: `${tourRect.width + 12}px`, height: `${tourRect.height + 12}px` }" />
                <div class="header-tools__tip card shadow p-3" role="dialog" aria-live="polite" :style="{ top: `${Math.min((tourRect?.top ?? 120) + (tourRect?.height ?? 0) + 18, 520)}px`, left: `${Math.max(12, Math.min(tourRect?.left ?? 40, 700))}px` }">
                    <strong>{{ tourSteps[tourIndex]?.title }}</strong>
                    <p class="small mb-2">{{ tourSteps[tourIndex]?.text }}</p>
                    <div class="d-flex gap-2 align-items-center">
                        <span class="small text-muted me-auto">{{ tourIndex + 1 }} / {{ tourSteps.length }}</span>
                        <button type="button" class="btn btn-outline-secondary btn-sm" @click="endTour">Skip</button>
                        <button v-if="tourIndex > 0" type="button" class="btn btn-outline-dark btn-sm" @click="stepTour(-1)">Back</button>
                        <button type="button" class="btn btn-dark btn-sm" data-test="tour-next" @click="stepTour(1)">{{ tourIndex + 1 >= tourSteps.length ? 'Done' : 'Next' }}</button>
                    </div>
                </div>
            </div>

            <div v-if="open !== ''" class="header-tools__overlay" @click.self="close">
                <div class="header-tools__dialog card shadow" role="dialog" aria-modal="true">
                    <!-- Today Profit -->
                    <template v-if="open === 'profit'">
                        <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="mb-0">Today Profit <small v-if="profit" class="text-muted">{{ profit.date }}</small></h6><button type="button" class="btn-close" aria-label="Close" @click="close" /></div>
                        <div v-if="profitError" class="text-danger small">{{ profitError }}</div>
                        <div v-else-if="! profit" class="text-muted small" aria-busy="true">Loading...</div>
                        <table v-else class="table table-sm mb-0" data-test="today-profit">
                            <thead><tr><th /><th class="text-end">Today</th><th class="text-end">Yesterday</th><th class="text-end">Change</th></tr></thead>
                            <tbody>
                                <tr v-for="row in profitRows" :key="row.key" :class="{ 'fw-bold': row.key === 'net_profit' }">
                                    <td>{{ row.label }}</td><td class="text-end">{{ money(row.today) }}</td><td class="text-end text-muted">{{ money(row.previous) }}</td>
                                    <td class="text-end" :class="row.today - row.previous >= 0 ? (row.key === 'expenses' || row.key === 'purchases' ? 'text-muted' : 'text-success') : (row.key === 'expenses' || row.key === 'purchases' ? 'text-muted' : 'text-danger')">{{ money(row.today - row.previous) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </template>

                    <!-- Calculator -->
                    <template v-else-if="open === 'calc'">
                        <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="mb-0">Calculator</h6><button type="button" class="btn-close" aria-label="Close" @click="close" /></div>
                        <div ref="calcBox" tabindex="0" class="header-tools__calc" data-test="calculator" aria-label="Calculator, type numbers and operators" @keydown="calcKey">
                            <output class="header-tools__calc-display" data-test="calc-display" aria-live="polite">{{ calcDisplay }}</output>
                            <div class="header-tools__calc-grid">
                                <button type="button" class="btn btn-outline-secondary" @click="calcClear">C</button>
                                <button type="button" class="btn btn-outline-secondary" @click="calcBack">&larr;</button>
                                <button type="button" class="btn btn-outline-secondary" @click="calcPercent">%</button>
                                <button type="button" class="btn btn-secondary" @click="calcOp('/')">&divide;</button>
                                <template v-for="row in [['7', '8', '9', '*'], ['4', '5', '6', '-'], ['1', '2', '3', '+']]" :key="row.join('')">
                                    <button v-for="key in row" :key="key" type="button" class="btn" :class="['*', '-', '+'].includes(key) ? 'btn-secondary' : 'btn-light'" @click="['*', '-', '+'].includes(key) ? calcOp(key) : calcDigit(key)">{{ key === '*' ? '×' : key }}</button>
                                </template>
                                <button type="button" class="btn btn-light header-tools__calc-zero" @click="calcDigit('0')">0</button>
                                <button type="button" class="btn btn-light" @click="calcDigit('.')">.</button>
                                <button type="button" class="btn btn-dark" data-test="calc-equals" @click="calcEquals">=</button>
                            </div>
                        </div>
                    </template>

                    <!-- Calendar -->
                    <template v-else-if="open === 'calendar'">
                        <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="mb-0">Calendar</h6><button type="button" class="btn-close" aria-label="Close" @click="close" /></div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <button type="button" class="btn btn-outline-secondary btn-sm" aria-label="Previous month" @click="shiftMonth(-1)">&lsaquo;</button>
                            <strong>{{ monthLabel }}</strong>
                            <button type="button" class="btn btn-outline-secondary btn-sm" aria-label="Next month" @click="shiftMonth(1)">&rsaquo;</button>
                        </div>
                        <div class="header-tools__cal" data-test="calendar">
                            <span v-for="d in ['S', 'M', 'T', 'W', 'T', 'F', 'S']" :key="d + Math.random()" class="small text-muted text-center">{{ d }}</span>
                            <button v-for="(cell, index) in calendarCells" :key="index" type="button" class="header-tools__day" :disabled="cell.day === null" :class="{ 'is-selected': cell.date === selectedDay }" @click="selectedDay = cell.date">
                                <span>{{ cell.day }}</span>
                                <span class="header-tools__dots"><i v-for="kind in kindsOn(cell.date)" :key="kind" :style="{ background: kindColour[kind] }" /></span>
                            </button>
                        </div>
                        <div class="small mt-2 d-flex flex-wrap gap-3"><span v-for="(label, kind) in kindLabel" :key="kind"><i class="header-tools__legend" :style="{ background: kindColour[kind] }" /> {{ label }}</span></div>
                        <ul v-if="selectedDay" class="list-unstyled small mt-2 mb-0">
                            <li v-if="! dayEvents.length" class="text-muted">Nothing on {{ selectedDay }}.</li>
                            <li v-for="(event, i) in dayEvents" :key="i"><span class="badge me-1" :style="{ background: kindColour[event.kind] }">{{ kindLabel[event.kind] }}</span>{{ event.title }}</li>
                        </ul>
                    </template>

                    <!-- To-do -->
                    <template v-else-if="open === 'todo'">
                        <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="mb-0">My to-do list <span class="badge text-bg-secondary">{{ pending }}</span></h6><button type="button" class="btn-close" aria-label="Close" @click="close" /></div>
                        <form class="d-flex gap-2 mb-2" @submit.prevent="addTodo">
                            <input v-model="todoTitle" type="text" class="form-control form-control-sm" placeholder="What needs doing?" maxlength="200" aria-label="New to-do" data-test="todo-input">
                            <input v-model="todoDue" type="date" class="form-control form-control-sm" style="max-width: 9.5rem" aria-label="Due date">
                            <button type="submit" class="btn btn-dark btn-sm" data-test="todo-add">Add</button>
                        </form>
                        <ul class="list-unstyled mb-0" style="max-height: 18rem; overflow: auto">
                            <li v-if="! todos.length" class="small text-muted">Nothing here yet.</li>
                            <li v-for="todo in todos" :key="todo.id" class="d-flex align-items-center gap-2 py-1 border-bottom">
                                <input type="checkbox" class="form-check-input mt-0" :checked="!! todo.done_at" :aria-label="`Done: ${todo.title}`" @change="toggleTodo(todo.id)">
                                <span class="flex-grow-1" :class="{ 'text-decoration-line-through text-muted': todo.done_at }">{{ todo.title }} <small v-if="todo.due_date" class="text-muted">{{ todo.due_date }}</small></span>
                                <button type="button" class="btn btn-outline-danger btn-sm py-0" :aria-label="`Delete ${todo.title}`" @click="removeTodo(todo.id)">x</button>
                            </li>
                        </ul>
                    </template>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<style scoped>
.header-tools {
    color: #e5e7eb;
}
.header-tools__btn {
    border: 1px solid rgba(255, 255, 255, 0.28);
    color: #e5e7eb;
    background: transparent;
    white-space: nowrap;
}
.header-tools__btn:hover,
.header-tools__btn:focus-visible {
    background: rgba(255, 255, 255, 0.12);
    color: #fff;
}
.header-tools__date {
    opacity: 0.85;
}
.header-tools__overlay {
    position: fixed;
    inset: 0;
    z-index: 2000;
    background: rgba(15, 23, 42, 0.45);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding-top: 10vh;
}
.header-tools__dialog {
    width: min(26rem, 94vw);
    padding: 1rem;
}
.header-tools__calc:focus {
    outline: 2px solid #0f766e;
    outline-offset: 2px;
}
.header-tools__calc-display {
    display: block;
    text-align: right;
    font-size: 1.6rem;
    font-variant-numeric: tabular-nums;
    padding: 0.5rem 0.75rem;
    background: #f1f5f9;
    border-radius: 6px;
    margin-bottom: 0.5rem;
    overflow: hidden;
}
.header-tools__calc-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0.4rem;
}
.header-tools__calc-zero {
    grid-column: span 2;
}
.header-tools__cal {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 2px;
}
.header-tools__day {
    border: 0;
    background: transparent;
    padding: 0.35rem 0;
    border-radius: 6px;
    display: flex;
    flex-direction: column;
    align-items: center;
    min-height: 2.6rem;
}
.header-tools__day:hover:not(:disabled),
.header-tools__day.is-selected {
    background: #e2f3f0;
}
.header-tools__dots {
    display: flex;
    gap: 2px;
    height: 6px;
}
.header-tools__dots i,
.header-tools__legend {
    display: inline-block;
    width: 6px;
    height: 6px;
    border-radius: 50%;
}
.header-tools__offer {
    position: fixed;
    right: 16px;
    bottom: 16px;
    z-index: 2100;
    width: 17rem;
}
.header-tools__tour {
    position: fixed;
    inset: 0;
    z-index: 2200;
}
.header-tools__spot {
    position: fixed;
    border-radius: 8px;
    box-shadow: 0 0 0 9999px rgba(15, 23, 42, 0.55);
    border: 2px solid #14b8a6;
    pointer-events: none;
    transition: all 0.2s ease;
}
.header-tools__tip {
    position: fixed;
    width: 19rem;
    max-width: 92vw;
}
</style>
