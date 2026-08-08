import '../scss/app.scss';

import { createInertiaApp, usePage, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, createSSRApp, h, type App as VueApp } from 'vue';
import Popper from "vue3-popper";
import { createPinia } from 'pinia'
import VueNotification from '@dafcoe/vue-notification';
import '@dafcoe/vue-notification/dist/vue-notification.css';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { resolvePublicAppBaseUrl } from '@/utils/publicAppUrl';
import axios from 'axios';

const appName =
    typeof window !== 'undefined'
        ? (window as any).Laravel?.appName || import.meta.env.VITE_APP_NAME || 'Laravel'
        : import.meta.env.VITE_APP_NAME || 'Laravel';
const appUrl = resolvePublicAppBaseUrl();

/** Resolve CJS default export interop for Vue plugins. */
function resolveVuePlugin<T>(module: T): T {
    if (typeof module === 'function') {
        return module;
    }

    if (
        module !== null &&
        typeof module === 'object' &&
        'install' in module &&
        typeof (module as { install?: unknown }).install === 'function'
    ) {
        return module;
    }

    if (module !== null && typeof module === 'object' && 'default' in module) {
        return resolveVuePlugin((module as { default: unknown }).default) as T;
    }

    return module;
}

const vueNotificationPlugin = resolveVuePlugin(VueNotification);

let bootstrap: typeof import('bootstrap') | null = null;

async function initializeClientLibraries(): Promise<void> {
    if (typeof window === 'undefined') {
        return;
    }

    /* Laravel FileManager */
    await import('../../public/vendor/laravel-filemanager/js/stand-alone-button.js');

    /* Bootstrap */
        bootstrap = await import('bootstrap');
        (window as any).bootstrap = bootstrap;

    /* Axios */
    const csrfToken =
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    axios.defaults.baseURL = appUrl;
    axios.defaults.withCredentials = true;
    axios.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken;
    window.axios = axios;
    window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

    // Interceptor to refresh CSRF token before each request
    // This fixes CSRF mismatch errors after logout/login
    axios.interceptors.request.use(
        (config) => {
            const token = document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content');
            if (token) {
                config.headers['X-CSRF-TOKEN'] = token;
            }
            return config;
        },
        (error) => {
            return Promise.reject(error);
        },
    );

    // Interceptor to handle CSRF token mismatch (419) errors
    axios.interceptors.response.use(
        (response) => response,
        (error) => {
            if (error.response?.status === 419) {
                // CSRF token mismatch - refresh the token and retry
                const token = document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute('content');
                if (token && error.config) {
                    error.config.headers['X-CSRF-TOKEN'] = token;
                    return axios.request(error.config);
                }
            }
            return Promise.reject(error);
        },
    );

    /* jQuery */
    const { default: $ } = await import('jquery');
    window.$ = window.jQuery = $;

    /* Summernote - Lazy load config */
    const LFMButton = function (context: any) {
        const ui = $.summernote.ui;

        const button = ui.button({
            contents: '<i class="note-icon-picture"></i>',
            tooltip: 'Insert image from File Manager',
            click: function () {
                const route_prefix = appUrl + '/laravel-filemanager';
                window.open(route_prefix + '?type=image', 'FileManager', 'width=900,height=600');
                window.SetUrl = function (items: { url: string }[]) {
                    items.forEach(function (item) {
                        context.invoke(
                            'editor.insertImage',
                            item.url.replace(new RegExp(`^${appUrl}`), ''),
                        );
                    });
                };
            },
        });

        return button.render();
    };

    // Global error handler for Summernote tooltip errors when switching tabs
    // This prevents errors when tooltips try to show on hidden elements
    window.addEventListener(
        'error',
        (event) => {
            if (
                event.message &&
                event.message.includes("Cannot read properties of undefined (reading 'top')")
            ) {
                // Suppress Summernote tooltip errors
                if (event.filename && event.filename.includes('summernote')) {
                    event.preventDefault();
                    return false;
                }
            }
        },
        true,
    );

    window.SUMMERNOTE_DEFAULT_CONFIGS = {
        height: 300,
        placeholder: 'Type here...',
        toolbar: [
            ['style', ['style']],
            ['style', ['bold', 'italic', 'underline', 'clear']],
            ['font', ['strikethrough', 'superscript', 'subscript']],
            ['fontsize', ['fontsize']],
            ['color', ['color']],
            ['para', ['ul', 'ol', 'paragraph']],
            ['table', ['table']],
            ['insert', ['link', 'lfm']],
            ['view', ['undo', 'redo', 'fullscreen', 'codeview', 'help']],
        ],
        buttons: { lfm: LFMButton },
        callbacks: {
            onInit: function () {},
        },
    };

    /** Browser back/forward sometimes skips Inertia visits or races them; stray `.modal-backdrop` remains. */
    window.addEventListener('popstate', () => {
        cleanupBootstrapModalArtifacts();
    });

    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            cleanupBootstrapModalArtifacts();
        }
    });
}

/* ZiggyVue */
import { ZiggyVue } from 'ziggy-js';
import { Ziggy } from './ziggy';

// Use runtime URL from server (@routes) - fixes dev URL showing on production
if (typeof window !== 'undefined' && (window as any).Ziggy?.url) {
    Ziggy.url = (window as any).Ziggy.url;
}

/* Vidle and pagination are client-only — loaded in setup when el is present */

/* Bootstrap modals + Inertia: hide/backdrop can survive SPA navigations and browser back/forward (bfcache). */
function cleanupBootstrapModalArtifacts(): void {
    if (typeof document === 'undefined' || !bootstrap) {
        return;
    }

    document.querySelectorAll<HTMLElement>('.modal.show').forEach((el) => {
        const inst = bootstrap.Modal.getInstance(el);
        inst?.hide();
        inst?.dispose();
        el.classList.remove('show');
        el.setAttribute('aria-hidden', 'true');
        el.style.removeProperty('display');
    });
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
    document.querySelectorAll('.modal-backdrop').forEach((node) => node.remove());
}

router.on('start', () => {
    cleanupBootstrapModalArtifacts();
});

async function bootstrapClientApp(
    app: VueApp,
    el: Element | string,
    props: { initialPage?: { props?: Record<string, unknown> } },
): Promise<void> {
    await initializeClientLibraries();

    const updateCsrfToken = () => {
        if (typeof document === 'undefined') {
            return;
        }

        const csrfTokenMeta = document.querySelector('meta[name="csrf-token"]');
        let newToken = null;
        try {
            const page = usePage();
            newToken = (page.props as any)?.csrfToken;
        } catch {
            // usePage might not be available yet
        }
        if (!newToken) {
            newToken = props.initialPage?.props?.csrfToken;
        }
        if (!newToken) {
            newToken = csrfTokenMeta?.getAttribute('content');
        }
        if (csrfTokenMeta && newToken) {
            csrfTokenMeta.setAttribute('content', newToken);
            axios.defaults.headers.common['X-CSRF-TOKEN'] = newToken;
        }
    };

    updateCsrfToken();

    router.on('success', () => {
        setTimeout(updateCsrfToken, 10);
    });

    const [{ default: Vidle }, { default: ZVuePagination }] = await Promise.all([
        import('v-idle-3'),
        import('z-vue-pagination'),
    ]);

    const { default: SummernoteEditor } = await import('vue3-summernote-editor');
    await import('summernote/dist/summernote-lite.css');
    await import('summernote/dist/summernote-lite.js');

    const { default: VueSweetalert2 } = await import('vue-sweetalert2');
    await import('sweetalert2/dist/sweetalert2.min.css');

    app
        .use(resolveVuePlugin(Vidle), {})
        .use(resolveVuePlugin(VueSweetalert2))
        .component('z-vue-pagination', ZVuePagination)
        .component('SummernoteEditor', SummernoteEditor)
        .mount(el);
}

/* Initialize Inertia App */

const pinia = createPinia();

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        ),
    setup: ({ el, App, props, plugin }) => {
        const app = el
            ? createApp({ render: () => h(App, props) })
            : createSSRApp({ render: () => h(App, props) });

        app
            .use(plugin)
            .use(pinia)
            .use(ZiggyVue, Ziggy)
            .use(vueNotificationPlugin)
            .component('Popper', Popper);

        if (!el) {
            return app;
        }

        void bootstrapClientApp(app, el, props);

        return app;
    },
    progress: {
        color: '#4B5563',
    },
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
});

// This will set light / dark mode on page load...
if (typeof window !== 'undefined') {
    initializeTheme();

    // This will listen for flash toast data from the server...
    initializeFlashToast();
}
