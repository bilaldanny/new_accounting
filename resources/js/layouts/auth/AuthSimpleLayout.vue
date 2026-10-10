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
    /** Only use a custom uploaded logo if one is actually configured; otherwise the name alone is shown, with no drawn mark. */
    const customLoginLogoUrl = computed(() => (props.setting as { login_logo_url?: string } | undefined)?.login_logo_url || '');
</script>

<template>
    <div class="auth-shell">
        <div class="auth-shell__glow" aria-hidden="true"></div>

        <div class="auth-shell__top-spacer"></div>

        <main class="auth-shell__main">
            <div class="auth-card">
                <div class="auth-card__brand">
                    <div class="auth-brand-mark" :class="{ 'auth-brand-mark--text-only': !customLoginLogoUrl }">
                        <img v-if="customLoginLogoUrl" :src="customLoginLogoUrl" class="auth-brand-mark__icon auth-brand-mark__icon--img" :alt="appName">
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
