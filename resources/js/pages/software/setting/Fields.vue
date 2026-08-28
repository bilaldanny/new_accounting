<script setup lang="ts">
    import GeneralSettingTab from './tabs/GeneralSettingTab.vue';
    import SmtpSettingTab from './tabs/SmtpSettingTab.vue';

    defineProps({
        activeTab: { type: String, default: 'general' },
        logoUrl: { type: String, default: '' },
        testingSmtp: { type: Boolean, default: false },
        sendingTest: { type: Boolean, default: false },
        hasSmtpPassword: { type: Boolean, default: false },
    });

    const emit = defineEmits<{
        'test-smtp': [];
        'test-send': [];
    }>();
</script>

<template>
    <TextElement name="id" hidden="true" />
    <TextElement name="_method" default="PUT" hidden="true" />

    <GeneralSettingTab
        v-if="activeTab === 'general'"
        :logo-url="logoUrl"
    />

    <SmtpSettingTab
        v-else-if="activeTab === 'smtp'"
        :testing-smtp="testingSmtp"
        :sending-test="sendingTest"
        :has-smtp-password="hasSmtpPassword"
        @test-smtp="emit('test-smtp')"
        @test-send="emit('test-send')"
    />
</template>
