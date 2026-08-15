<script setup lang="ts">
    import useCommons from '@/composables/common';
    import { resolvePublicAppBaseUrl } from '@/utils/publicAppUrl';
    import { openLfmImagePicker } from '@/utils/openLfmImagePicker';
    import { CheckCircle, Circle, EyeAlt, EyeClosed, ImagePlus, Lock, RefreshCw } from '@boxicons/vue';
    import { computed, onMounted, ref, watch } from 'vue';
    import { usePage } from '@inertiajs/vue3';

    const params = defineProps({
        type: String,
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

    const page = usePage();

    const appUrl = resolvePublicAppBaseUrl();

    const normalizeRoleName = (name: unknown): string =>
        String(name ?? '').toLowerCase().replace(/\s+/g, '');

    const authUser = computed(() => page.props.auth?.user as {
        rolename?: string;
        company_id?: number | string | null;
        branch_id?: number | string | null;
    } | null);

    const roleName = computed(() => normalizeRoleName(authUser.value?.rolename));

    const isSuperadmin = computed(() => roleName.value === 'superadmin');
    const isCompanyadmin = computed(() => roleName.value === 'companyadmin');

    const showCompanyField = computed(() => isSuperadmin.value);
    const showBranchField = computed(() => isSuperadmin.value || isCompanyadmin.value);
    const showHiddenCompanyField = computed(() => isCompanyadmin.value || (! isSuperadmin.value && ! isCompanyadmin.value));
    const showHiddenBranchField = computed(() => ! isSuperadmin.value && ! isCompanyadmin.value);
    const isEdit = computed(() => params.type === 'edit');

    const colThird = { container: 4, label: 12, wrapper: 12 };
    const colHalf = { container: 6, label: 12, wrapper: 12 };

    const imageInputId = computed(() => (isEdit.value ? 'EditUserImage' : 'UserImage'));

    const strongPasswordRule = 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\\d)(?=.*[!@#$%^&*(),.?":{}|<>]).{8,}$/';

    const hasLowercase = ref(false);
    const hasUppercase = ref(false);
    const hasNumber = ref(false);
    const hasSpecial = ref(false);
    const hasLength = ref(false);

    const showPassword = ref(false);
    const showPasswordConfirm = ref(false);

    const passwordRuleClass = (met: boolean) => (met ? 'text-success' : 'text-muted');

    const {
        fetchCompany,
        fetchBranch,
        fetchDepartment,
        fetchRole,
        companiesdata,
        branchesdata,
        departmentsdata,
        rolesdata,
    } = useCommons();

    const normalizeId = (id: unknown) =>
        id === null || id === undefined || id === '' ? '' : String(id);

    const selectedCompanyId = computed(() => params.formData?.company_id ?? '');
    const selectedBranchId = computed(() => params.formData?.branch_id ?? '');

    const lastFetchedCompanyId = ref('');
    const lastFetchedBranchKey = ref('');

    const branchRules = computed(() => (isSuperadmin.value || isCompanyadmin.value ? 'required' : ''));

    const companyRules = computed(() => (isSuperadmin.value ? 'required' : ''));

    const departmentRules = computed(() => 'required');

    const usernameRules = computed(() => {
        if (params.recordId) {
            return `required|user_username_unique:${params.recordId}`;
        }

        return 'required|user_username_unique';
    });

    const emailRules = computed(() => {
        if (params.recordId) {
            return `required|email|user_email_unique:${params.recordId}`;
        }

        return 'required|email|user_email_unique';
    });

    const branchPlaceholder = computed(() =>
        isSuperadmin.value || isCompanyadmin.value ? 'Select branch' : 'Select branch (optional)',
    );

    const branchDisabled = computed(() => isSuperadmin.value && ! selectedCompanyId.value);
    const departmentDisabled = computed(() => ! selectedBranchId.value);

    const userImageUrl = computed(() => {
        const raw = String(params.formData?.user_image ?? '').trim();

        if (raw === '') {
            return '';
        }

        if (/^https?:\/\//i.test(raw)) {
            return raw;
        }

        const normalized = raw.startsWith('/') ? raw : `/${raw}`;

        return `${appUrl.replace(/\/$/, '')}${normalized}`;
    });

    const usernameLocked = ref(false);

    const usernameFromNames = (firstName: unknown, lastName: unknown): string =>
        `${String(firstName ?? '').trim()}${String(lastName ?? '').trim()}`
            .toLowerCase()
            .replace(/\s+/g, '');

    function syncUsernameFromNames() {
        if (usernameLocked.value || isEdit.value) {
            return;
        }

        params.formRef?.update?.({
            username: usernameFromNames(params.formData?.first_name, params.formData?.last_name),
        });
    }

    function onUsernameInput() {
        usernameLocked.value = true;
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

    function resetPasswordHints() {
        hasLowercase.value = false;
        hasUppercase.value = false;
        hasNumber.value = false;
        hasSpecial.value = false;
        hasLength.value = false;
        showPassword.value = false;
        showPasswordConfirm.value = false;
    }

    function chooseUserImage(event: MouseEvent) {
        openLfmImagePicker(event, appUrl);
    }

    function renderUserImagePreview() {
        const holder = document.getElementById('user-image-holder');

        if (! holder || ! userImageUrl.value) {
            return;
        }

        holder.innerHTML = '';
        const img = document.createElement('img');
        img.className = 'company-logo-preview-img d-block rounded object-fit-contain';
        img.style.height = '2.75rem';
        img.src = userImageUrl.value;
        holder.appendChild(img);
    }

    function applyScopedDefaults() {
        if (isSuperadmin.value) {
            return;
        }

        const updates: Record<string, string | number> = {};

        if (authUser.value?.company_id) {
            updates.company_id = authUser.value.company_id;
        }

        if (! isCompanyadmin.value && authUser.value?.branch_id) {
            updates.branch_id = authUser.value.branch_id;
        }

        if (Object.keys(updates).length > 0) {
            params.formRef?.update?.(updates);
        }
    }

    async function loadBranchOptions(companyId: string | number | null | undefined) {
        if (! showBranchField.value) {
            return;
        }

        const normalizedCompanyId = normalizeId(companyId);

        if (! normalizedCompanyId) {
            branchesdata.value = [];

            return;
        }

        if (normalizedCompanyId === lastFetchedCompanyId.value) {
            return;
        }

        lastFetchedCompanyId.value = normalizedCompanyId;
        await fetchBranch(normalizedCompanyId);
    }

    async function loadDepartmentOptions(
        companyId: string | number | null | undefined,
        branchId: string | number | null | undefined,
    ) {
        const normalizedCompanyId = normalizeId(companyId);
        const normalizedBranchId = normalizeId(branchId);
        const key = `${normalizedCompanyId}:${normalizedBranchId}`;

        if (! normalizedCompanyId || ! normalizedBranchId) {
            departmentsdata.value = [];

            return;
        }

        if (key === lastFetchedBranchKey.value) {
            return;
        }

        lastFetchedBranchKey.value = key;
        await fetchDepartment(normalizedCompanyId, normalizedBranchId);
    }

    async function handleCompanyChange(companyId: string | number | null | undefined) {
        if (! isSuperadmin.value) {
            return;
        }

        const normalizedCompanyId = normalizeId(companyId);

        if (params.formData?.branch_id) {
            params.formRef?.update?.({ branch_id: '', department_id: '' });
        }

        lastFetchedCompanyId.value = '';
        lastFetchedBranchKey.value = '';
        await loadBranchOptions(normalizedCompanyId || undefined);
    }

    async function handleBranchChange(
        companyId: string | number | null | undefined,
        branchId: string | number | null | undefined,
    ) {
        if (params.formData?.department_id) {
            params.formRef?.update?.({ department_id: '' });
        }

        lastFetchedBranchKey.value = '';
        await loadDepartmentOptions(companyId, branchId);
    }

    onMounted(async () => {
        applyScopedDefaults();
        await fetchRole();

        if (showCompanyField.value) {
            await fetchCompany();
        }

        const companyId = isCompanyadmin.value
            ? authUser.value?.company_id
            : selectedCompanyId.value;

        if (companyId) {
            await loadBranchOptions(companyId);
        }

        const branchId = isCompanyadmin.value
            ? (params.formData?.branch_id || authUser.value?.branch_id)
            : selectedBranchId.value;

        if (companyId && branchId) {
            await loadDepartmentOptions(companyId, branchId);
        }

        renderUserImagePreview();
    });

    watch(userImageUrl, renderUserImagePreview);

    watch(
        () => normalizeId(params.formData?.company_id),
        async (companyId, previousCompanyId) => {
            if (companyId === previousCompanyId) {
                return;
            }

            await handleCompanyChange(companyId || undefined);
        },
    );

    watch(
        () => [normalizeId(params.formData?.company_id), normalizeId(params.formData?.branch_id)],
        async ([companyId, branchId], [prevCompanyId, prevBranchId]) => {
            if (companyId === prevCompanyId && branchId === prevBranchId) {
                return;
            }

            await handleBranchChange(companyId || undefined, branchId || undefined);
        },
    );

    watch(
        () => [params.formData?.first_name, params.formData?.last_name],
        ([firstName, lastName]) => {
            if (! String(firstName ?? '').trim() && ! String(lastName ?? '').trim()) {
                usernameLocked.value = false;
                resetPasswordHints();
            }

            syncUsernameFromNames();
        },
    );
</script>

<template>
    <TextElement name="_method" default="PUT" v-if="isEdit" hidden="true" />

    <TextElement v-if="isEdit" name="id" hidden="true" />

    <TextElement
        v-if="showHiddenCompanyField"
        name="company_id"
        hidden="true"
    />

    <TextElement
        v-if="showHiddenBranchField"
        name="branch_id"
        hidden="true"
    />

    <SelectElement
        v-if="showCompanyField"
        name="company_id"
        :native="false"
        :items="companiesdata"
        id="CompanyId"
        field-name="CompanyId"
        placeholder="Select company"
        label="Company"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="false"
        :rules="companyRules"
    />

    <SelectElement
        v-if="showBranchField"
        name="branch_id"
        :native="false"
        :items="branchesdata"
        id="BranchId"
        field-name="BranchId"
        :placeholder="branchPlaceholder"
        label="Branch"
        :columns="colThird"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="false"
        :disabled="branchDisabled"
        :rules="branchRules"
    />

    <SelectElement
        name="department_id"
        :native="false"
        :items="departmentsdata"
        id="DepartmentId"
        field-name="DepartmentId"
        placeholder="Select department"
        label="Department"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="false"
        :disabled="departmentDisabled"
        :rules="departmentRules"
    />

    <SelectElement
        name="role_id"
        :native="false"
        :items="rolesdata"
        id="RoleId"
        field-name="RoleId"
        placeholder="Select role"
        label="Role"
        rules="required"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        label-prop="text"
        value-prop="id"
        :search="true"
        :floating="false"
        :can-clear="false"
    />

    <TextElement
        id="FirstName"
        field-name="FirstName"
        name="first_name"
        label="First Name"
        placeholder="Enter first name"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        autocomplete="off"
        rules="required"
    />

    <TextElement
        id="LastName"
        field-name="LastName"
        name="last_name"
        label="Last Name"
        placeholder="Enter last name"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        autocomplete="off"
        rules="required"
    />

    <TextElement
        v-if="!isEdit"
        id="Username"
        field-name="Username"
        name="username"
        label="Username"
        placeholder="Enter username"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        autocomplete="off"
        :rules="usernameRules"
        description="Auto-filled from first and last name"
        @input="onUsernameInput"
    />

    <TextElement
        id="Email"
        field-name="Email"
        name="email"
        label="Email"
        placeholder="Enter email"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        autocomplete="off"
        :rules="emailRules"
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
        :id="imageInputId"
        field-name="UserImage"
        name="user_image"
        label="Profile Image"
        placeholder="Select profile image"
        :columns="colThird"
        :add-classes="{
            ElementAddon: {
                container: 'p-0',
            },
        }"
    >
        <template #addon-before>
            <button
                :data-input="imageInputId"
                data-field-name="user_image"
                data-preview="user-image-holder"
                type="button"
                class="company-logo-choose"
                @click="chooseUserImage"
            >
                <ImagePlus size="xs" />
                <span>Choose</span>
            </button>
        </template>
        <template #after>
            <div id="user-image-holder" class="company-logo-preview"></div>
        </template>
    </TextElement>

    <TextElement
        id="Password"
        v-if="!isEdit"
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
        v-if="!isEdit"
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

    <StaticElement tag="br" name="element" />

    <ToggleElement
        :labels="{ 1: 'On', 0: 'Off' }"
        :columns="{ container: 4, label: 12, wrapper: 12 }"
        id="IsActive"
        field-name="IsActive"
        name="is_active"
        label="Is Active"
        :true-value="true"
        :false-value="false"
        :default="true"
    />
</template>
