<script setup lang="ts">
    import { ImagePlus } from '@boxicons/vue';
    import { resolvePublicAppBaseUrl } from '@/utils/publicAppUrl';
    import { openLfmImagePicker } from '@/utils/openLfmImagePicker';
    import { usePage } from '@inertiajs/vue3';
    import { computed, onMounted, watch } from 'vue';
    import { colFull, colHalf, colThird } from './constants';

    const props = defineProps({
        logoUrl: { type: String, default: '' },
        emailLogoUrl: { type: String, default: '' },
    });

    const page = usePage();
    const defaultDialCode = computed(() => String(page.props.dailCode ?? ''));
    const appUrl = resolvePublicAppBaseUrl();
    const logoInputId = 'SoftwareSettingLogo';
    const emailLogoInputId = 'SoftwareSettingEmailLogo';

    function chooseLogo(event: MouseEvent) {
        openLfmImagePicker(event, appUrl);
    }

    function renderLogoPreview(holderId: string, url: string, alt: string) {
        const holder = document.getElementById(holderId);

        if (!holder) {
            return;
        }

        holder.innerHTML = '';

        if (!url) {
            return;
        }

        const img = document.createElement('img');
        img.className = 'company-logo-preview-img d-block rounded object-fit-contain';
        img.style.height = '2.75rem';
        img.alt = alt;
        img.src = url;
        holder.appendChild(img);
    }

    watch(() => props.logoUrl, (url) => {
        renderLogoPreview('software-setting-logo-holder', url, 'Software logo preview');
    });
    watch(() => props.emailLogoUrl, (url) => {
        renderLogoPreview('software-setting-email-logo-holder', url, 'Email logo preview');
    });
    onMounted(() => {
        renderLogoPreview('software-setting-logo-holder', props.logoUrl, 'Software logo preview');
        renderLogoPreview('software-setting-email-logo-holder', props.emailLogoUrl, 'Email logo preview');
    });
</script>

<template>
    <StaticElement name="section_general" :columns="colFull">
        <div class="company-setting-section-label">Application</div>
        <p class="company-setting-section-help">
            Name, contact details, and branding used across the software.
            The application name replaces APP_NAME in titles, emails, and the footer.
        </p>
    </StaticElement>

    <TextElement
        name="name"
        label="Application Name"
        placeholder="Application name"
        :columns="colHalf"
        rules="required|min:3|max:200"
    />

    <TextElement
        name="email"
        label="Support Email"
        placeholder="support@example.com"
        :columns="colHalf"
        rules="nullable|email"
    />

    <PhoneElement
        id="SoftwareSettingContactNo"
        field-name="SoftwareSettingContactNo"
        name="contact_no"
        label="Contact Number"
        placeholder="Enter phone number"
        :columns="colHalf"
        :default="defaultDialCode"
        :allow-incomplete="true"
        :unmask="true"
    />

    <TextareaElement
        name="address"
        label="Address"
        placeholder="Software address"
        :columns="colFull"
    />

    <StaticElement name="section_branding" :columns="colFull">
        <div class="company-setting-section-label">Branding</div>
    </StaticElement>

    <TextElement
        :id="logoInputId"
        field-name="SoftwareLogo"
        name="system_logo"
        label="Software Logo"
        placeholder="Select logo image"
        :columns="colThird"
        :add-classes="{
            ElementAddon: {
                container: 'p-0',
            },
        }"
    >
        <template #addon-before>
            <button
                :data-input="logoInputId"
                data-field-name="system_logo"
                data-preview="software-setting-logo-holder"
                type="button"
                class="company-logo-choose"
                @click="chooseLogo"
            >
                <ImagePlus size="xs" />
                <span>Choose</span>
            </button>
        </template>
        <template #after>
            <div id="software-setting-logo-holder" class="company-logo-preview"></div>
        </template>
    </TextElement>

    <TextElement
        :id="emailLogoInputId"
        field-name="EmailLogo"
        name="email_logo"
        label="Email Logo"
        placeholder="Select email logo"
        :columns="colThird"
        :add-classes="{
            ElementAddon: {
                container: 'p-0',
            },
        }"
    >
        <template #addon-before>
            <button
                :data-input="emailLogoInputId"
                data-field-name="email_logo"
                data-preview="software-setting-email-logo-holder"
                type="button"
                class="company-logo-choose"
                @click="chooseLogo"
            >
                <ImagePlus size="xs" />
                <span>Choose</span>
            </button>
        </template>
        <template #after>
            <div id="software-setting-email-logo-holder" class="company-logo-preview"></div>
        </template>
    </TextElement>
</template>
