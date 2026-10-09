import '../css/landing.css';

import { createInertiaApp } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';

/**
 * Entry of the public pages (landing, register). It is deliberately separate from `app.ts`: a visitor who is not signed in
 * must not download the whole application (tables, editors, charts, Bootstrap, jQuery).
 */
const pages = import.meta.glob<DefineComponent>('./pages/landing/*.vue');

createInertiaApp({
    title: (title) => title || 'ACCUNIVO',
    resolve: async (name) => {
        const page = pages[`./pages/${name}.vue`];

        if (!page) {
            throw new Error(`Unknown public page: ${name}`);
        }

        return page();
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: { color: '#0f766e' },
});
