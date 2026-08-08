import { useNotificationStore } from '@/utils/vueNotification'

export function showAppNotification(message: string, type: 'success' | 'alert' = 'success'): void {
    const store = useNotificationStore()
    const current = store.notifications?.value

    if (current && typeof current === 'object') {
        Object.keys(current).forEach((id) => store.unsetNotification(id))
    }

    store.setNotification({
        message,
        type,
        showIcon: true,
        dismiss: {
            manually: true,
            automatically: true,
        },
        duration: 3000,
        showDurationProgress: true,
        appearance: 'light',
    })
}
