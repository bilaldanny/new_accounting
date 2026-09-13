import { ref } from 'vue';

/**
 * Shared (module-level) sidebar collapsed/mobile-open state.
 *
 * The legacy theme used to toggle this via a jQuery handler bound to `.toggle-icon` /
 * `.mobile-toggle-menu` in public/assets/js/app.js. That script runs on the initial
 * DOMContentLoaded, before Vue mounts and renders those elements, so the handler never
 * attached to anything — the buttons were dead. This replaces it with real Vue state.
 */
const isSidebarToggled = ref(false);
const isSidebarHovered = ref(false);

export default function useSidebarToggle() {
    function toggleSidebar(): void {
        isSidebarToggled.value = !isSidebarToggled.value;

        if (!isSidebarToggled.value) {
            isSidebarHovered.value = false;
        }
    }

    function openSidebar(): void {
        isSidebarToggled.value = true;
    }

    /** Collapsed sidebar temporarily expands while hovered — mirrors the old jQuery pin/unpin behavior. */
    function setSidebarHovered(hovered: boolean): void {
        if (!isSidebarToggled.value) {
            return;
        }

        isSidebarHovered.value = hovered;
    }

    return { isSidebarToggled, isSidebarHovered, toggleSidebar, openSidebar, setSidebarHovered };
}
