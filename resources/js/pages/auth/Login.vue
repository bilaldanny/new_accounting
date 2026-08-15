<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { EyeAlt, EyeClosed, LockKeyholeOpen, LoaderLinesAlt } from '@boxicons/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import { register } from '@/routes';

defineOptions({
    layout: {
        title: 'Log in to your account',
        // description: 'Don\'t have an account yet? <a href="' + register.url() + '">Sign up here</a>',
        description: 'Enter your username or email and password below to log in',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();

const showPassword = ref(false);
</script>

<template>
    <Head title="Log in" />

    <!-- <div class="d-grid">
        <a class="btn my-4 shadow-sm btn-white" href="javascript:;"> 
            <span class="d-flex justify-content-center align-items-center">
                <img class="me-2" src="assets/images/icons/search.svg" width="16" alt="Image Description">
                <span>Sign in with Google</span>
            </span>
        </a>
        <a href="javascript:;" class="btn btn-facebook"><i class="bx bxl-facebook"></i>Sign in with Facebook</a>
    </div>
    <div class="login-separater text-center mb-4"> <span>OR SIGN IN WITH EMAIL</span>
        <hr>
    </div>-->
    <div class="form-body p-4">
        <Form
            v-bind="store.form()"
            class="row g-3"
            :reset-on-success="['password']"
            v-slot="{ errors, processing }"
        >
            <div class="col-12">
                <label for="email" class="form-label">Username or Email</label>
                <input
                    id="email"
                    name="email"
                    type="text"
                    class="form-control"
                    placeholder="Enter Your Username Or Email"
                    required
                    autofocus
                    autocomplete="username"
                />
                <InputError :message="errors.email" />
                <InputError :message="errors.login" />
            </div>
            <div class="col-12">
                <label for="password" class="form-label mb-0">Password</label>
                <div class="input-group" id="show_hide_password">
                    <input
                        id="password"
                        name="password"
                        :type="showPassword ? 'text' : 'password'"
                        class="form-control border-end-0"
                        placeholder="Enter Your Password"
                        required
                        autocomplete="current-password"
                    />
                    <a
                        href="javascript:;"
                        class="input-group-text bg-transparent"
                        @click.prevent="showPassword = !showPassword"
                    >
                        <EyeAlt v-if="showPassword" size="sm" />
                        <EyeClosed v-else size="sm" />
                    </a>
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-check form-switch">
                    <input
                        id="remember"
                        name="remember"
                        type="checkbox"
                        class="form-check-input"
                        value="1"
                    />
                    <label class="form-check-label" for="remember">Remember Me</label>
                </div>
            </div>
            <div v-if="canResetPassword" class="col-md-6 text-end">
                <a :href="request.url()">Forgot Password ?</a>
            </div>
            <div class="col-12">
                <div class="d-grid">
                    <button
                        type="submit"
                        class="btn btn-primary"
                        :disabled="processing"
                    >
                        <LockKeyholeOpen 
                            pack="filled" 
                            size="sm" 
                            class="d-inline-block BtnIcon"
                            v-if="!processing"
                        />
                        <LoaderLinesAlt 
                            pack="filled" 
                            size="sm" 
                            class="d-inline-block BtnIcon BtnIconLoading"
                            v-if="processing"
                        />
                        <span v-if="processing">Logging in...</span>
                        <span v-else>Log in</span>
                    </button>
                </div>
            </div>
        </Form>
    </div>
</template>

<style lang="css" scoped>
.BtnIcon {
    margin-right: 5px;
    margin-top: -1%;
}

.BtnIconLoading {
    animation: btn-icon-spin 0.75s linear infinite;
    transform-origin: center;
}

@keyframes btn-icon-spin {
    from {
        transform: rotate(0deg);
    }

    to {
        transform: rotate(360deg);
    }
}
</style>

