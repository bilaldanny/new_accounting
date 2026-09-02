import { ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import useCommons from './common';
import { API_ENDPOINTS } from './apiEndpoints';

function applyPhoneDefaults<T extends Record<string, unknown>>(data: T, defaultDialCode: string): T {
    const value = data.contact_no;

    if (value === null || value === undefined || value === '') {
        data.contact_no = defaultDialCode;
    }

    return data;
}

export default function useSoftwareSettings() {
    const { props: pageProps } = usePage();
    const defaultDialCode = String(pageProps.dailCode ?? '');
    const { Notify, fetchWithRetry } = useCommons();

    const loading = ref(false);
    const saving = ref(false);
    const testingSmtp = ref(false);
    const sendingTest = ref(false);

    const defaultFormData = {
        id: null as number | null,
        name: '',
        email: '',
        contact_no: defaultDialCode,
        address: '',
        system_logo: '',
        system_logo_url: '',
        email_logo: '',
        email_logo_url: '',
        smtp_host: '',
        smtp_port: '',
        smtp_username: '',
        smtp_password: '',
        smtp_encryption: 'tls',
        smtp_scheme: 'smtp',
        smtp_from_address: '',
        smtp_from_name: '',
        has_smtp_password: false,
        test_email: '',
    };

    const formData = ref({ ...defaultFormData });

    const smtpPayload = (data: Record<string, unknown> = {}) => ({
        smtp_host: data.smtp_host ?? formData.value.smtp_host,
        smtp_port: data.smtp_port ?? formData.value.smtp_port,
        smtp_username: data.smtp_username ?? formData.value.smtp_username,
        smtp_password: data.smtp_password ?? formData.value.smtp_password,
        smtp_encryption: data.smtp_encryption ?? formData.value.smtp_encryption,
        smtp_scheme: data.smtp_scheme ?? formData.value.smtp_scheme,
        smtp_from_address: data.smtp_from_address ?? formData.value.smtp_from_address,
        smtp_from_name: data.smtp_from_name ?? formData.value.smtp_from_name,
    });

    const applySetting = (softwareSetting: Record<string, unknown> = {}) => {
        formData.value = applyPhoneDefaults({
            ...defaultFormData,
            ...softwareSetting,
            smtp_password: '',
            test_email: formData.value.test_email,
        }, defaultDialCode);
    };

    const getSoftwareSetting = async () => {
        loading.value = true;

        try {
            const response = await fetchWithRetry(window.axios.get, API_ENDPOINTS.softwareSettings);
            applySetting(response.data.softwareSetting ?? {});
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error)) {
                if (error.response?.data?.message !== 'Unauthenticated.') {
                    Notify(error.response?.data?.message || 'Unable to load software settings', 'alert');
                }
            } else {
                Notify('Unexpected error occurred', 'alert');
            }
        } finally {
            loading.value = false;
        }
    };

    const saveSoftwareSetting = async (payload: Record<string, unknown>) => {
        saving.value = true;

        try {
            const response = await fetchWithRetry(
                window.axios.put,
                API_ENDPOINTS.softwareSettings,
                payload,
            );

            applySetting(response.data.softwareSetting ?? {});
            Notify(response.data.message || 'Successfully Saved', 'success');

            return response.data;
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error)) {
                if (error.response?.data?.message !== 'Unauthenticated.') {
                    Notify(error.response?.data?.message || 'Unable to save software settings', 'alert');
                }
            } else {
                Notify('Unexpected error occurred', 'alert');
            }

            throw error;
        } finally {
            saving.value = false;
        }
    };

    const testSmtpConnection = async (payload: Record<string, unknown> = {}) => {
        testingSmtp.value = true;

        try {
            const response = await fetchWithRetry(
                window.axios.post,
                API_ENDPOINTS.softwareSettingsTestSmtp,
                smtpPayload(payload),
            );

            Notify(response.data.message || 'SMTP connection successful!', 'success');

            return response.data;
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error)) {
                Notify(error.response?.data?.message || 'SMTP connection failed', 'alert');
            } else {
                Notify('Unexpected error occurred', 'alert');
            }

            throw error;
        } finally {
            testingSmtp.value = false;
        }
    };

    const sendTestEmail = async (payload: Record<string, unknown> = {}) => {
        const testEmail = String(payload.test_email ?? formData.value.test_email ?? '').trim();

        if (testEmail === '') {
            Notify('Enter a test email address', 'alert');

            return;
        }

        sendingTest.value = true;

        try {
            const response = await fetchWithRetry(
                window.axios.post,
                API_ENDPOINTS.softwareSettingsTestSend,
                {
                    ...smtpPayload(payload),
                    test_email: payload.test_email ?? formData.value.test_email,
                },
            );

            Notify(response.data.message || 'Test email sent successfully.', 'success');

            return response.data;
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error)) {
                Notify(error.response?.data?.message || 'Failed to send test email', 'alert');
            } else {
                Notify('Unexpected error occurred', 'alert');
            }

            throw error;
        } finally {
            sendingTest.value = false;
        }
    };

    return {
        loading,
        saving,
        testingSmtp,
        sendingTest,
        formData,
        defaultFormData,
        getSoftwareSetting,
        saveSoftwareSetting,
        testSmtpConnection,
        sendTestEmail,
        Notify,
    };
}
