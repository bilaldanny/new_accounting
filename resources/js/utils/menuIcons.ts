import type { Component } from 'vue';
import {
    Archive,
    Badge,
    Bank,
    Building,
    Buildings,
    Calendar,
    Cart,
    CheckCircle,
    Cog,
    Cube,
    File,
    GitBranch,
    Grid,
    Group,
    Home,
    Layers,
    Like,
    Medal,
    Menu,
    Note,
    Package,
    Percentage,
    PieChart,
    Receipt,
    Ruler,
    Shield,
    ShieldQuarter,
    Store,
    Tag,
    Truck,
    User,
    UserCircle,
    Wallet,
} from '@boxicons/vue';

/** Map of Boxicons Vue component names used in the menus table. */
export const menuIconComponents: Record<string, Component> = {
    Archive,
    Badge,
    Bank,
    Building,
    Buildings,
    Calendar,
    Cart,
    CheckCircle,
    Cog,
    Cube,
    File,
    GitBranch,
    Grid,
    Group,
    Home,
    Layers,
    Like,
    Medal,
    Menu,
    Note,
    Package,
    Percentage,
    PieChart,
    Receipt,
    Ruler,
    Shield,
    ShieldQuarter,
    Store,
    Tag,
    Truck,
    User,
    UserCircle,
    Wallet,
};

const legacyIconMap: Record<string, string> = {
    'fal fa-bars': 'Menu',
    'fal fa-building': 'Building',
    'fal fa-user': 'UserCircle',
    'fal fa-cog': 'Cog',
    'fal fa-file-invoice': 'Receipt',
    'fal fa-id-badge': 'Badge',
    'fab fa-product-hunt': 'Package',
    'fal fa-tags': 'Cart',
    'fal fa-thumbs-up': 'Like',
    'far fa-badge-dollar': 'Store',
    'bx bx-home': 'Home',
    'bx bx-user': 'User',
    'bx bx-buildings': 'Buildings',
    'bx bx-data': 'Archive',
};

/**
 * Resolve a menus.icon value to a Boxicons Vue component name.
 */
export function resolveMenuIcon(icon: string | null | undefined): string {
    if (!icon) {
        return 'Home';
    }

    const trimmed = icon.trim();

    if (menuIconComponents[trimmed]) {
        return trimmed;
    }

    if (legacyIconMap[trimmed]) {
        return legacyIconMap[trimmed];
    }

    if (trimmed.startsWith('bx ')) {
        const bxName = trimmed
            .replace(/^bx\s+bx-?/i, '')
            .split('-')
            .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
            .join('');

        if (menuIconComponents[bxName]) {
            return bxName;
        }
    }

    return 'Home';
}

export function getMenuIconComponent(icon: string | null | undefined): Component {
    const name = resolveMenuIcon(icon);

    return menuIconComponents[name] ?? Home;
}
