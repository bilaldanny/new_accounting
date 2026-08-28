<script setup lang="ts">
    import { colFull, colHalf, smtpEncryptionItems, smtpSchemeItems } from './constants';

    defineProps({
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
    <StaticElement name="section_smtp" :columns="colFull">
        <div class="company-setting-section-label">SMTP Server</div>
        <p class="company-setting-section-help">
            Used for system emails such as credentials, invitations, and invoices.
            Leave the password blank to keep the current value.
        </p>
    </StaticElement>

    <TextElement
        name="smtp_host"
        label="Host"
        placeholder="smtp.example.com"
        :columns="colHalf"
    />

    <TextElement
        name="smtp_port"
        label="Port"
        placeholder="587"
        input-type="number"
        :columns="colHalf"
    />

    <SelectElement
        name="smtp_scheme"
        :native="false"
        :items="smtpSchemeItems"
        label="Scheme"
        placeholder="Select scheme"
        :columns="colHalf"
        :search="false"
        :floating="false"
        :can-clear="false"
        info="Use smtp for port 587 (STARTTLS) and smtps for port 465."
    />

    <SelectElement
        name="smtp_encryption"
        :native="false"
        :items="smtpEncryptionItems"
        label="Encryption"
        placeholder="Select encryption"
        :columns="colHalf"
        :search="false"
        :floating="false"
        :can-clear="false"
    />

    <TextElement
        name="smtp_username"
        label="Username"
        placeholder="SMTP username"
        :columns="colHalf"
        autocomplete="off"
    />

    <TextElement
        name="smtp_password"
        label="Password"
        :placeholder="hasSmtpPassword ? 'Leave blank to keep current password' : 'SMTP password'"
        input-type="password"
        :columns="colHalf"
        autocomplete="new-password"
    />

    <StaticElement name="section_from" :columns="colFull">
        <div class="company-setting-section-label">From Address</div>
    </StaticElement>

    <TextElement
        name="smtp_from_name"
        label="From Name"
        placeholder="Sender name"
        :columns="colHalf"
    />

    <TextElement
        name="smtp_from_address"
        label="From Email"
        placeholder="noreply@example.com"
        :columns="colHalf"
        rules="nullable|email"
    />

    <StaticElement name="section_test" :columns="colFull">
        <div class="company-setting-section-label">Test Delivery</div>
        <p class="company-setting-section-help">
            Verify the connection or send a test message using the values currently in this form.
        </p>
    </StaticElement>

    <TextElement
        name="test_email"
        label="Test Email"
        placeholder="you@example.com"
        :columns="colHalf"
        rules="nullable|email"
    />

    <StaticElement name="section_test_actions" :columns="colFull">
        <div class="d-flex flex-wrap gap-2 mt-1">
            <button
                type="button"
                class="btn btn-outline-primary"
                :disabled="testingSmtp || sendingTest"
                :aria-busy="testingSmtp"
                @click="emit('test-smtp')"
            >
                <span
                    v-if="testingSmtp"
                    class="spinner-border spinner-border-sm me-2"
                    role="status"
                    aria-hidden="true"
                ></span>
                {{ testingSmtp ? 'Testing…' : 'Test Connection' }}
            </button>
            <button
                type="button"
                class="btn btn-outline-secondary"
                :disabled="testingSmtp || sendingTest"
                :aria-busy="sendingTest"
                @click="emit('test-send')"
            >
                <span
                    v-if="sendingTest"
                    class="spinner-border spinner-border-sm me-2"
                    role="status"
                    aria-hidden="true"
                ></span>
                {{ sendingTest ? 'Sending…' : 'Send Test Email' }}
            </button>
        </div>
    </StaticElement>
</template>
