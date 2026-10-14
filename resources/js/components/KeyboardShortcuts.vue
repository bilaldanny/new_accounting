<script setup lang="ts">
    import { router, usePage } from '@inertiajs/vue3';
    import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

    /**
     * Keyboard use of the whole application, with no page having to opt in:
     *   /  or  Ctrl+K   go to the search box (arrow keys pick a result, Enter opens it)
     *   g then a letter go to a page the user may open (g s = Sales ...)
     *   n                open the page's "Add" form
     *   Ctrl+S           press the page's Save button
     *   ?                list these keys, Esc closes
     * Letters only act when no field is being typed in and no dialog is open, and not at all on the POS, where a scanner types letters.
     */
    type Goto = { key: string; label: string; path: string; permission: string | null };

    const GOTO: Goto[] = [
        { key: 'd', label: 'Dashboard', path: '/dashboard', permission: null },
        { key: 's', label: 'Sales', path: '/sell', permission: '/sell' },
        { key: 'o', label: 'Point of sale', path: '/sell/pos/add', permission: '/sell/pos' },
        { key: 'p', label: 'Purchases', path: '/purchase', permission: '/purchase' },
        { key: 'c', label: 'Customers', path: '/customer', permission: '/customer' },
        { key: 'u', label: 'Suppliers', path: '/supplier', permission: '/supplier' },
        { key: 'i', label: 'Products', path: '/product', permission: '/product' },
        { key: 'j', label: 'Journal entries', path: '/journalentry', permission: '/journalentry' },
        { key: 'e', label: 'Expenses', path: '/expense', permission: '/expense' },
        { key: 'a', label: 'Chart of accounts', path: '/chart-of-account', permission: '/chart-of-account' },
        { key: 'r', label: 'Trial balance', path: '/report/trial-balance', permission: '/report/trial-balance' },
        { key: 'h', label: 'HR and payroll', path: '/hrm', permission: '/hrm' },
    ];

    const page = usePage();
    const helpOpen = ref(false);
    let chord = false;
    let chordTimer: ReturnType<typeof setTimeout> | null = null;

    const paths = computed(() => new Set<string>((page.props.auth as { user?: { permission_paths?: string[] } } | undefined)?.user?.permission_paths ?? []));
    const isSuperadmin = computed(() => String((page.props.auth as { user?: { rolename?: string } } | undefined)?.user?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin');
    const allowed = computed(() => GOTO.filter((item) => item.permission === null || isSuperadmin.value || paths.value.has(item.permission)));

    function isTyping(target: EventTarget | null): boolean {
        const element = target as HTMLElement | null;

        return !! element && !! element.tagName && (['INPUT', 'TEXTAREA', 'SELECT'].includes(element.tagName) || element.isContentEditable);
    }

    function visible(element: Element | null): element is HTMLElement {
        return element instanceof HTMLElement && element.offsetParent !== null && ! (element as HTMLButtonElement).disabled;
    }

    function focusSearch() {
        const input = document.querySelector<HTMLInputElement>('.topbar-search__input');

        if (input) {
            input.focus();
            input.select();
        }
    }

    function saveButton(): HTMLElement | null {
        const scope = document.querySelector('.page-content') ?? document;
        const buttons = Array.from(scope.querySelectorAll('button')).filter(visible);

        return buttons.find((button) => button.getAttribute('type') === 'submit')
            ?? buttons.find((button) => /^save/i.test((button.textContent ?? '').trim()))
            ?? null;
    }

    function addLink(): HTMLElement | null {
        const scope = document.querySelector('.page-content') ?? document;

        const byHref = Array.from(scope.querySelectorAll('a[href]')).find((link) => visible(link) && /\/add(\?|$)/.test(link.getAttribute('href') ?? ''));

        if (byHref) {
            return byHref as HTMLElement;
        }

        // the lists that add in a dialog: the button that opens it, or any button / link whose text is "Add ..."
        return (Array.from(scope.querySelectorAll('[data-bs-target="#AddModal"], a, button')).find((element) => visible(element)
            && (element.getAttribute('data-bs-target') === '#AddModal' || /^\s*add/i.test(element.textContent ?? ''))) as HTMLElement | undefined) ?? null;
    }

    function onKeydown(event: KeyboardEvent) {
        if (event.defaultPrevented) {
            return;
        }

        const key = event.key.toLowerCase();

        // Up / Down walk the links of the sidebar while one of them has the focus
        if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && event.target instanceof HTMLElement && event.target.closest('.sidebar-wrapper, .sidebar')) {
            const links = Array.from(document.querySelectorAll<HTMLElement>('.sidebar-wrapper a[href], .sidebar a[href], .sidebar-wrapper [role="button"], .sidebar [role="button"]')).filter(visible);
            const here = links.indexOf(event.target.closest('a, [role="button"]') as HTMLElement);

            if (here >= 0) {
                event.preventDefault();
                links[Math.max(0, Math.min(links.length - 1, here + (event.key === 'ArrowDown' ? 1 : -1)))]?.focus();
            }

            return;
        }

        if ((event.ctrlKey || event.metaKey) && key === 'k') {
            event.preventDefault();
            focusSearch();

            return;
        }

        if ((event.ctrlKey || event.metaKey) && key === 's') {
            const button = saveButton();

            if (button) {
                event.preventDefault();
                button.click();
            }

            return;
        }

        if (event.key === 'Escape') {
            helpOpen.value = false;
            chord = false;

            return;
        }

        if (event.ctrlKey || event.altKey || event.metaKey || isTyping(event.target)) {
            return;
        }

        // the POS has its own keys and a scanner types letters there; any dialog owns the keyboard while it is open
        if (window.location.pathname.startsWith('/sell/pos') || document.querySelector('.modal.show')) {
            return;
        }

        if (event.key === '/') {
            event.preventDefault();
            focusSearch();

            return;
        }

        if (event.key === '?') {
            event.preventDefault();
            helpOpen.value = ! helpOpen.value;

            return;
        }

        if (chord) {
            chord = false;
            const hit = allowed.value.find((item) => item.key === key);

            if (hit) {
                event.preventDefault();
                router.visit(hit.path);
            }

            return;
        }

        if (key === 'g') {
            chord = true;

            if (chordTimer) {
                clearTimeout(chordTimer);
            }

            chordTimer = setTimeout(() => {
                chord = false;
            }, 1500);

            return;
        }

        if (key === 'n') {
            const link = addLink();

            if (link) {
                event.preventDefault();
                link.click();
            }
        }
    }

    onMounted(() => window.addEventListener('keydown', onKeydown));
    onBeforeUnmount(() => {
        window.removeEventListener('keydown', onKeydown);

        if (chordTimer) {
            clearTimeout(chordTimer);
        }
    });
</script>

<template>
    <div class="kbd-root">
    <a href="#main-content" class="skip-link">Skip to the page</a>

    <div v-if="helpOpen" class="kbd-help" role="dialog" aria-label="Keyboard shortcuts" data-test="keyboard-help" @click.self="helpOpen = false">
        <div class="kbd-help__panel">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <strong>Keyboard shortcuts</strong>
                <button type="button" class="btn btn-sm btn-outline-secondary" @click="helpOpen = false">Close (Esc)</button>
            </div>
            <table class="table table-sm mb-2">
                <tbody>
                    <tr><td><kbd>/</kbd> or <kbd>Ctrl</kbd>+<kbd>K</kbd></td><td>Search pages, customers, products, invoices (arrows to pick, Enter to open)</td></tr>
                    <tr><td><kbd>n</kbd></td><td>Add a new record on this page</td></tr>
                    <tr><td><kbd>Ctrl</kbd>+<kbd>S</kbd></td><td>Save the form</td></tr>
                    <tr><td><kbd>Tab</kbd> / <kbd>Shift</kbd>+<kbd>Tab</kbd></td><td>Next / previous field or button</td></tr>
                    <tr><td><kbd>?</kbd></td><td>This list</td></tr>
                    <tr v-for="item in allowed" :key="item.key"><td><kbd>g</kbd> then <kbd>{{ item.key }}</kbd></td><td>{{ item.label }}</td></tr>
                    <tr><td><kbd>F1</kbd> <kbd>F2</kbd> <kbd>F4</kbd> <kbd>F8</kbd> <kbd>F9</kbd> <kbd>F10</kbd></td><td>On the point of sale: help, search / scan, customer, cash sale, payment, park cart</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    </div>
</template>

<style>
    .skip-link {
        position: absolute;
        left: -9999px;
        top: 0;
        z-index: 3000;
        background: #111827;
        color: #fff;
        padding: 0.5rem 0.9rem;
        border-radius: 0 0 0.4rem 0;
    }

    .skip-link:focus {
        left: 0;
    }

    .kbd-help {
        position: fixed;
        inset: 0;
        z-index: 2900;
        background: rgba(15, 23, 42, 0.55);
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .kbd-help__panel {
        background: #fff;
        color: #111827;
        border-radius: 0.6rem;
        padding: 1rem 1.25rem;
        width: min(42rem, 92vw);
        max-height: 85vh;
        overflow: auto;
    }

    a:focus-visible,
    button:focus-visible,
    input:focus-visible,
    select:focus-visible,
    textarea:focus-visible,
    [tabindex]:focus-visible {
        outline: 2px solid #2563eb;
        outline-offset: 2px;
    }
</style>
