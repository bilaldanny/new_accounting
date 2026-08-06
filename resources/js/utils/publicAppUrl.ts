/**
 * Base URL for browser requests (axios, Laravel Echo auth) so they hit the same host as the current page.
 * When `VITE_APP_URL` is missing, the Vite dev default `http://localhost` breaks Laragon/virtual-host apps
 * (e.g. `https://student_dahbaord.test`), causing 404s on `/api/*` and `/broadcasting/auth`.
 */
export function resolvePublicAppBaseUrl(): string {
    const vite = import.meta.env.VITE_APP_URL as string | undefined;

    if (vite != null && String(vite).trim() !== '') {
        return String(vite).replace(/\/$/, '');
    }

    if (typeof window !== 'undefined' && window.location?.origin) {
        return window.location.origin;
    }

    return 'http://localhost';
}
