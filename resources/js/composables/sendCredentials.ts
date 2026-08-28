import { inject, ref } from 'vue';
import useCommons from './common';

export function useSendCredentials() {
    const { Notify } = useCommons();
    const $swal = inject<any>('$swal', null);
    const isSending = ref(false);

    async function sendCredentials(url: string): Promise<void> {
        if (!url || isSending.value) {
            return;
        }

        let confirmed = false;

        if ($swal) {
            const result = await $swal.mixin({
                customClass: {
                    confirmButton: 'btn btn-primary ms-3',
                    cancelButton: 'btn btn-light',
                },
                buttonsStyling: false,
            }).fire({
                title: 'Email login credentials?',
                text: 'A new password will be generated and emailed. The current password will stop working.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, send email',
                cancelButtonText: 'Cancel',
                reverseButtons: true,
            });
            confirmed = Boolean(result.isConfirmed);
        } else {
            confirmed = window.confirm(
                'Email a new password to this user? Their current password will stop working.',
            );
        }

        if (!confirmed) {
            return;
        }

        isSending.value = true;

        try {
            const response = await window.axios.post(url);
            Notify(response.data?.message || 'Login credentials have been queued for email.', 'success');
        } catch (error: unknown) {
            if (window.axios.isAxiosError(error)) {
                Notify(error.response?.data?.message || 'An error occurred', 'alert');
            } else {
                Notify('Unexpected error occurred', 'alert');
            }
        } finally {
            isSending.value = false;
        }
    }

    return { isSending, sendCredentials };
}
