<script setup lang="ts">
    import { Shield } from '@boxicons/vue';
    import { usePage } from '@inertiajs/vue3';
    import { computed } from 'vue';

    defineProps<{
        title?: string;
        description?: string;
    }>();

    const { props } = usePage();
    const currentYear = new Date().getFullYear();
    const appName = computed(() => String(props.name ?? ''));
    /** Only use a custom uploaded logo if one is actually configured; otherwise show the brand mark below. */
    const customLoginLogoUrl = computed(() => (props.setting as { login_logo_url?: string } | undefined)?.login_logo_url || '');
</script>

<template>
    <div class="auth-shell">
        <div class="auth-shell__glow" aria-hidden="true"></div>

        <div class="auth-shell__top-spacer"></div>

        <main class="auth-shell__main">
            <div class="auth-card">
                <div class="auth-card__brand">
                    <div class="auth-brand-mark">
                        <img v-if="customLoginLogoUrl" :src="customLoginLogoUrl" class="auth-brand-mark__icon auth-brand-mark__icon--img" :alt="appName">
                        <svg v-else class="auth-brand-mark__icon" viewBox="0 0 200 160" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <defs>
                                <linearGradient id="authBrandTeal" x1="0%" y1="0%" x2="100%" y2="100%">
                                    <stop offset="0%" stop-color="#0891b2" />
                                    <stop offset="50%" stop-color="#0d9488" />
                                    <stop offset="100%" stop-color="#14b8a6" />
                                </linearGradient>
                            </defs>
                            <path d="M 58 138 L 40 138 L 84 34 L 102 34 L 118 72 L 102 72 L 93 49 L 64 122 L 78 122 L 72 138 Z" fill="#0f2b48" />
                            <path d="M 40 138 L 68 138 L 82 104 L 66 104 L 56 126 L 46 126 Z" fill="#0f2b48" />
                            <rect x="52" y="72" width="13" height="26" rx="1.5" fill="#0f2b48" />
                            <rect x="71" y="52" width="15" height="46" rx="2" fill="url(#authBrandTeal)" />
                            <rect x="91" y="62" width="15" height="36" rx="2" fill="url(#authBrandTeal)" />
                            <path d="M 52 102 L 114 102 L 152 46 L 164 54 L 122 114 L 52 114 Z" fill="#0f2b48" />
                            <polygon points="140,42 174,26 166,62 154,53 147,63 139,57 146,47" fill="#0f2b48" />
                            <path d="M 120 120 L 138 90 L 158 126 L 150 142 L 138 142 L 132 130 L 126 138 L 116 138 Z" fill="url(#authBrandTeal)" />
                        </svg>
                        <div class="auth-brand-mark__text">
                            <span class="auth-brand-mark__title">{{ appName }}</span>
                            <span class="auth-brand-mark__subtitle">Finance &amp; Analytics ERP</span>
                        </div>
                    </div>
                </div>

                <div class="auth-card__heading">
                    <h1>{{ title }}</h1>
                    <p v-if="description" v-html="description"></p>
                </div>

                <slot />

                <div class="auth-card__security">
                    <Shield size="sm" aria-hidden="true" />
                    <span>256-bit encrypted secure connection</span>
                </div>
            </div>
        </main>

        <footer class="auth-shell__footer">
            <span>© {{ currentYear }} {{ props.name }}. All rights reserved.</span>
        </footer>
    </div>
</template>
