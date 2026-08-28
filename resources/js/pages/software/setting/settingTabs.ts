import type { Component } from 'vue';
import { Cog, Envelope } from '@boxicons/vue';

export type SoftwareSettingTab = {
    id: string;
    label: string;
    icon: Component;
};

export const softwareSettingTabs: SoftwareSettingTab[] = [
    { id: 'general', label: 'General', icon: Cog },
    { id: 'smtp', label: 'Email / SMTP', icon: Envelope },
];
