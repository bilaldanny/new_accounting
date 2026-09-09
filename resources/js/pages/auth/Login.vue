<script setup lang="ts">
import { AlertCircle, ArrowRight, EyeAlt, EyeClosed, Key, LockKeyholeOpen, LoaderLinesAlt, User } from '@boxicons/vue';
import { Form, Head } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Sign in to your account',
        description: 'Enter your credentials to access your workspace',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();

const showPassword = ref(false);
const capsLockActive = ref(false);

function handleKeyEvent(event: KeyboardEvent) {
    if (event.getModifierState) {
        capsLockActive.value = event.getModifierState('CapsLock');
    }
}

onMounted(() => {
    window.addEventListener('keydown', handleKeyEvent);
    window.addEventListener('keyup', handleKeyEvent);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleKeyEvent);
    window.removeEventListener('keyup', handleKeyEvent);
});
</script>

<template>
    <Head title="Log in" />

    <div v-if="status" class="auth-alert auth-alert--success">
        <AlertCircle size="sm" aria-hidden="true" />
        <span>{{ status }}</span>
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
    >
        <div v-if="errors.login" class="auth-alert">
            <AlertCircle size="sm" aria-hidden="true" />
            <span>{{ errors.login }}</span>
        </div>

        <div class="auth-field">
            <label for="email" class="form-label">Email or Username</label>
            <div class="auth-field__control">
                <input
                    id="email"
                    name="email"
                    type="text"
                    class="form-control"
                    placeholder="name@company.com"
                    required
                    autofocus
                    autocomplete="username"
                />
                <span class="auth-field__icon">
                    <User size="sm" aria-hidden="true" />
                </span>
            </div>
            <InputError :message="errors.email" />
        </div>

        <div class="auth-field">
            <div class="auth-field__label-row">
                <label for="password" class="form-label">Password</label>
                <span v-if="capsLockActive" class="auth-field__capslock">
                    <Key size="sm" aria-hidden="true" />
                    Caps Lock On
                </span>
            </div>
            <div class="auth-field__control">
                <input
                    id="password"
                    name="password"
                    :type="showPassword ? 'text' : 'password'"
                    class="form-control"
                    placeholder="Enter your password"
                    required
                    autocomplete="current-password"
                />
                <button
                    type="button"
                    class="auth-field__toggle"
                    :title="showPassword ? 'Hide password' : 'Show password'"
                    @click="showPassword = !showPassword"
                >
                    <EyeAlt v-if="showPassword" size="sm" aria-hidden="true" />
                    <EyeClosed v-else size="sm" aria-hidden="true" />
                </button>
            </div>
            <InputError :message="errors.password" />
        </div>

        <div class="auth-row">
            <label class="auth-row__remember">
                <input
                    id="remember"
                    name="remember"
                    type="checkbox"
                    class="form-check-input"
                    value="1"
                />
                <span>Remember me</span>
            </label>

            <a v-if="canResetPassword" :href="request.url()" class="auth-row__link">Forgot password?</a>
        </div>

        <button type="submit" class="auth-submit-btn" :disabled="processing">
            <LoaderLinesAlt v-if="processing" pack="filled" size="sm" class="spin" aria-hidden="true" />
            <LockKeyholeOpen v-else pack="filled" size="sm" aria-hidden="true" />
            <span>{{ processing ? 'Signing in…' : 'Sign in' }}</span>
            <ArrowRight v-if="!processing" size="sm" aria-hidden="true" />
        </button>
    </Form>
</template>
