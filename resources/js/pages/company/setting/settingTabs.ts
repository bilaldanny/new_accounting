import type { Component } from 'vue';
import {
    Calendar,
    Cart,
    Cog,
    Desktop,
    LinkAlt,
    Receipt,
    ShieldQuarter,
    Store,
    Tag,
} from '@boxicons/vue';

export type SettingTab = {
    id: string;
    label: string;
    icon: Component;
};

export const settingTabs: SettingTab[] = [
    { id: 'business', label: 'Business Setting', icon: Cog },
    { id: 'linkAccounts', label: 'Link Accounts', icon: LinkAlt },
    { id: 'prefix', label: 'Prefix', icon: Tag },
    { id: 'approval', label: 'Approval', icon: ShieldQuarter },
    { id: 'tax', label: 'Tax', icon: Receipt },
    { id: 'financialYear', label: 'Financial Year', icon: Calendar },
    { id: 'purchaseSetting', label: 'Purchase Setting', icon: Cart },
    { id: 'sellSetting', label: 'Sell Setting', icon: Store },
    { id: 'posSetting', label: 'POS Setting', icon: Desktop },
];

/** Tabs rendered outside Vueform (plain HTML / separate APIs). */
export const standaloneSettingTabs = new Set([
    'tax',
    'financialYear',
    'linkAccounts',
    'purchaseSetting',
    'sellSetting',
]);

export function isStandaloneSettingTab(tabId: string): boolean {
    return standaloneSettingTabs.has(tabId);
}
