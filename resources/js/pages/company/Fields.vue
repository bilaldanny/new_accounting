<script setup lang="ts">
    import { computed, onMounted, ref, watch } from 'vue';
    import { Buildings, CheckCircle, Circle, EyeAlt, EyeClosed, ImagePlus, Lock, SliderAlt, UserCircle } from '@boxicons/vue';
    import { resolvePublicAppBaseUrl } from '@/utils/publicAppUrl';
    import { openLfmImagePicker } from '@/utils/openLfmImagePicker';

    const params = defineProps({
        type: String,
        logoUrl: String,
        recordId: {
            type: Number,
            default: null,
        },
        formData: {
            type: Object,
            default: () => ({}),
        },
        formRef: {
            type: Object,
            default: null,
        },
    });

    const appUrl = resolvePublicAppBaseUrl();

    const logoInputId = computed(() => (params.type === 'edit' ? 'EditLogo' : 'Logo'));

    const colThird = { container: 4, label: 12, wrapper: 12 };
    const colHalf = { container: 6, label: 12, wrapper: 12 };
    const colFull = { container: 12, label: 12, wrapper: 12 };

    const adminUsernameLocked = ref(false);

    const strongPasswordRule = 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\\d)(?=.*[!@#$%^&*(),.?":{}|<>]).{8,}$/';

    const hasLowercase = ref(false);
    const hasUppercase = ref(false);
    const hasNumber = ref(false);
    const hasSpecial = ref(false);
    const hasLength = ref(false);

    const showPassword = ref(false);
    const showPasswordConfirm = ref(false);

    const passwordRuleClass = (met: boolean) => (met ? 'text-success' : 'text-muted');

    const usernameFromAdminName = (name: unknown): string =>
        String(name ?? '').trim().toLowerCase().replace(/\s+/g, '');

    const codeRules = computed(() => {
        if (params.recordId) {
            return `required|regex:/^CO-\\d{5}$/i|company_code_unique:${params.recordId}`;
        }

        return 'required|regex:/^CO-\\d{5}$/i|company_code_unique';
    });

    const logoRules = computed(() => (params.type === 'edit' ? '' : 'required'));

    const adminUsernameRules = computed(() => {
        const userId = params.formData?.user_id;

        if (userId) {
            return `required|company_admin_username_unique:${userId}`;
        }

        return 'required|company_admin_username_unique';
    });

    const adminEmailRules = computed(() => {
        const userId = params.formData?.user_id;

        if (userId) {
            return `required|email|company_admin_email_unique:${userId}`;
        }

        return 'required|email|company_admin_email_unique';
    });

    function syncAdminUsernameFromName(name: unknown) {
        if (adminUsernameLocked.value) {
            return;
        }

        params.formRef?.update?.({ admin_username: usernameFromAdminName(name) });
    }

    function onAdminNameInput(value: unknown) {
        const name =
            typeof value === 'string'
                ? value
                : value && typeof value === 'object' && 'target' in value
                  ? String((value.target as HTMLInputElement | null)?.value ?? '')
                  : String(params.formData?.admin_name ?? '');

        syncAdminUsernameFromName(name);
    }

    function onAdminUsernameInput() {
        adminUsernameLocked.value = true;
    }

    function validatePassword(value: unknown) {
        const password =
            typeof value === 'string'
                ? value
                : value && typeof value === 'object' && 'target' in value
                  ? String((value.target as HTMLInputElement | null)?.value ?? '')
                  : '';

        hasLowercase.value = /[a-z]/.test(password);
        hasUppercase.value = /[A-Z]/.test(password);
        hasNumber.value = /\d/.test(password);
        hasSpecial.value = /[!@#$%^&*(),.?":{}|<>]/.test(password);
        hasLength.value = password.length >= 8;
    }

    function generatePassword() {
        const lower = 'abcdefghijklmnopqrstuvwxyz';
        const upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        const nums = '0123456789';
        const special = '!@#$%^&*(),.?":{}|<>';
        const all = lower + upper + nums + special;
        const pick = (set: string) => set[Math.floor(Math.random() * set.length)];

        let password = pick(lower) + pick(upper) + pick(nums) + pick(special);

        while (password.length < 14) {
            password += pick(all);
        }

        password = password
            .split('')
            .sort(() => Math.random() - 0.5)
            .join('');

        params.formRef?.update?.({
            password,
            password_confirmation: password,
        });

        validatePassword(password);
        showPassword.value = true;
        showPasswordConfirm.value = true;
    }

    function chooseLogo(event: MouseEvent) {
        openLfmImagePicker(event, appUrl);
    }

    function renderLogoPreview() {
        const holder = document.getElementById('logo-holder');

        if (!holder || !params.logoUrl) {
            return;
        }

        holder.innerHTML = '';
        const img = document.createElement('img');
        img.className = 'company-logo-preview-img d-block rounded object-fit-contain';
        img.style.height = '2.75rem';
        img.src = params.logoUrl;
        holder.appendChild(img);
    }

    function resetPasswordHints() {
        hasLowercase.value = false;
        hasUppercase.value = false;
        hasNumber.value = false;
        hasSpecial.value = false;
        hasLength.value = false;
        showPassword.value = false;
        showPasswordConfirm.value = false;
    }

    onMounted(renderLogoPreview);

    watch(() => params.logoUrl, renderLogoPreview);

    watch(
        () => params.formData?.code,
        () => {
            if (params.type !== 'edit') {
                adminUsernameLocked.value = false;
                resetPasswordHints();
            }
        },
    );
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="params.type === 'edit'" hidden="true" />

    <TextElement v-if="params.type === 'edit'" name="id" hidden="true" />
    <TextElement v-if="params.type === 'edit'" name="user_id" hidden="true" />

    <StaticElement name="section_company" :columns="colFull">
        <div class="company-section-header company-section-header-primary">
            <span class="company-section-icon company-section-icon-primary">
                <Buildings size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Company Information</h6>
                <p class="company-section-subtitle mb-0">Basic details that identify this company</p>
            </div>
        </div>
    </StaticElement>

    <TextElement
        id="Code"
        field-name="Code"
        name="code"
        label="Company Code"
        placeholder="CO-00001"
        :columns="colThird"
        autocomplete="off"
        readonly
        :rules="codeRules"
        info="This is a unique identifier for the company. It is auto-generated and cannot be changed."
    />

    <TextElement
        id="Name"
        field-name="Name"
        name="name"
        label="Company Name"
        placeholder="Enter company name"
        :columns="colThird"
        autocomplete="off"
        rules="required|min:3|max:200"
    />

    <TextElement
        id="Email"
        field-name="Email"
        name="email"
        label="Company Email"
        placeholder="company@example.com"
        :columns="colThird"
        autocomplete="off"
        rules="email"
        input-type="email"
    />

    <PhoneElement
        id="Phone"
        field-name="Phone"
        name="phone"
        label="Phone No"
        placeholder="Enter phone number"
        :columns="colThird"
        :allow-incomplete="true"
        :unmask="true"
    />

    <TextElement
        id="NtnNo"
        field-name="NtnNo"
        name="ntn_no"
        label="Tax ID / NTN"
        placeholder="Enter Tax ID / NTN"
        :columns="colThird"
        autocomplete="off"
    />

    <StaticElement name="section_admin" :columns="colFull">
        <div class="company-section-header company-section-header-indigo company-section-header-spaced">
            <span class="company-section-icon company-section-icon-indigo">
                <UserCircle size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Administrator Account</h6>
                <p class="company-section-subtitle mb-0">The first user who will manage this company</p>
            </div>
        </div>
    </StaticElement>

    <TextElement
        id="OwnerName"
        field-name="OwnerName"
        name="admin_name"
        label="Owner Name"
        placeholder="Enter owner full name"
        :columns="colThird"
        autocomplete="off"
        rules="required"
        @input="onAdminNameInput"
    />

    <TextElement
        :id="params.type === 'edit' ? 'EditOwnerUsername' : 'OwnerUsername'"
        field-name="OwnerUsername"
        name="admin_username"
        label="Owner Username"
        placeholder="Enter login username"
        :columns="colThird"
        autocomplete="off"
        :rules="adminUsernameRules"
        description="Auto-filled from owner name"
        @input="onAdminUsernameInput"
    />

    <TextElement
        id="OwnerEmail"
        field-name="OwnerEmail"
        name="admin_email"
        label="Owner Email"
        placeholder="admin@example.com"
        :columns="colThird"
        autocomplete="off"
        :rules="adminEmailRules"
        input-type="email"
    />

    <PhoneElement
        id="OwnerPhone"
        field-name="OwnerPhone"
        name="admin_phone"
        label="Owner Phone"
        placeholder="Enter owner phone number"
        :columns="colThird"
        :allow-incomplete="true"
        :unmask="true"
    />

    <StaticElement name="section_space" :columns="{ container: 12, label: 12, wrapper: 12 }"></StaticElement>

    <TextElement
        v-if="params.type !== 'edit'"
        id="Password"
        field-name="Password"
        name="password"
        label="Password"
        :input-type="showPassword ? 'text' : 'password'"
        :rules="['required', 'same:password_confirmation', strongPasswordRule]"
        placeholder="Create a strong password"
        autocomplete="new-password"
        :columns="colHalf"
        :add-classes="{
            container: 'password-field',
            inputContainer: 'password-input-shell',
            input: 'password-field-input',
            ElementLabel: {
                container: 'password-field-label',
            },
            ElementAddon: {
                container: 'password-field-addon flex-shrink-0 align-self-center',
            },
        }"
        @input="validatePassword"
    >
        <template #description>
            <button
                type="button"
                class="password-generate-btn"
                title="Generate a strong password"
                @click="generatePassword"
            >
                <RefreshCw size="xs" />
                <span>Generate Password</span>
            </button>
        </template>
        <template #addon-after>
            <button
                type="button"
                class="eye-placeholder"
                :title="showPassword ? 'Hide password' : 'Show password'"
                :aria-label="showPassword ? 'Hide password' : 'Show password'"
                :aria-pressed="showPassword"
                @click="showPassword = !showPassword"
            >
                <EyeAlt v-if="showPassword" size="xs" />
                <EyeClosed v-else size="xs" />
            </button>
        </template>
        <template #after>
            <div class="password-requirements-panel">
                <div class="password-requirements-header">
                    <Lock size="xs" />
                    <span>Password requirements</span>
                </div>
                <div class="password-requirements-grid">
                    <span :class="['password-requirement-item', passwordRuleClass(hasLowercase)]">
                        <CheckCircle v-if="hasLowercase" size="xs" />
                        <Circle v-else size="xs" />
                        <span>One lowercase letter</span>
                    </span>
                    <span :class="['password-requirement-item', passwordRuleClass(hasUppercase)]">
                        <CheckCircle v-if="hasUppercase" size="xs" />
                        <Circle v-else size="xs" />
                        <span>One uppercase letter</span>
                    </span>
                    <span :class="['password-requirement-item', passwordRuleClass(hasNumber)]">
                        <CheckCircle v-if="hasNumber" size="xs" />
                        <Circle v-else size="xs" />
                        <span>One number</span>
                    </span>
                    <span :class="['password-requirement-item', passwordRuleClass(hasSpecial)]">
                        <CheckCircle v-if="hasSpecial" size="xs" />
                        <Circle v-else size="xs" />
                        <span>One special character</span>
                    </span>
                    <span :class="['password-requirement-item', passwordRuleClass(hasLength)]">
                        <CheckCircle v-if="hasLength" size="xs" />
                        <Circle v-else size="xs" />
                        <span>At least 8 characters</span>
                    </span>
                </div>
            </div>
        </template>
    </TextElement>

    <TextElement
        v-if="params.type !== 'edit'"
        id="PasswordAgain"
        field-name="Confirm Password"
        name="password_confirmation"
        label="Confirm Password"
        placeholder="Re-enter password"
        :columns="colHalf"
        autocomplete="new-password"
        rules="required"
        :input-type="showPasswordConfirm ? 'text' : 'password'"
        :add-classes="{
            container: 'password-field',
            inputContainer: 'password-input-shell',
            input: 'password-field-input',
            ElementLabel: {
                container: 'password-field-label',
            },
            ElementAddon: {
                container: 'password-field-addon flex-shrink-0 align-self-center',
            },
        }"
    >
        <template #addon-after>
            <button
                type="button"
                class="eye-placeholder"
                :title="showPasswordConfirm ? 'Hide password' : 'Show password'"
                :aria-label="showPasswordConfirm ? 'Hide password' : 'Show password'"
                :aria-pressed="showPasswordConfirm"
                @click="showPasswordConfirm = !showPasswordConfirm"
            >
                <EyeAlt v-if="showPasswordConfirm" size="xs" />
                <EyeClosed v-else size="xs" />
            </button>
        </template>
    </TextElement>

    <StaticElement name="section_limits" :columns="colFull">
        <div class="company-section-header company-section-header-teal company-section-header-spaced">
            <span class="company-section-icon company-section-icon-teal">
                <SliderAlt size="sm" />
            </span>
            <div>
                <h6 class="company-section-title mb-0">Limits and Settings</h6>
                <p class="company-section-subtitle mb-0">Branding, capacity, and availability</p>
            </div>
        </div>
    </StaticElement>

    <TextElement
        id="MaxBranches"
        field-name="MaxBranches"
        name="max_branches"
        label="Branch Allow"
        placeholder="Maximum branches"
        :columns="colThird"
        autocomplete="off"
        rules="required|numeric"
        input-type="number"
        :default="2"
    />

    <TextElement
        id="MaxUsers"
        field-name="MaxUsers"
        name="max_users"
        label="User Allow"
        placeholder="Maximum users"
        :columns="colThird"
        autocomplete="off"
        rules="required|numeric"
        input-type="number"
        :default="10"
    />

    <TextElement
        :id="logoInputId"
        field-name="Logo"
        name="logo"
        label="Company Logo"
        placeholder="Select logo image"
        :columns="colThird"
        :rules="logoRules"
        :add-classes="{
            ElementAddon: {
                container: 'p-0',
            },
        }"
    >
        <template #addon-before>
            <button
                :data-input="logoInputId"
                data-field-name="logo"
                data-preview="logo-holder"
                type="button"
                class="company-logo-choose"
                @click="chooseLogo"
            >
                <ImagePlus size="xs" />
                <span>Choose</span>
            </button>
        </template>
        <template #after>
            <div id="logo-holder" class="company-logo-preview"></div>
        </template>
    </TextElement>

    <ToggleElement
        :labels="{ 1: 'Yes', 0: 'No' }"
        :columns="colThird"
        id="IsActive"
        field-name="IsActive"
        name="is_active"
        label="Active"
        :true-value="true"
        :false-value="false"
        :default="true"
    />
</template>
