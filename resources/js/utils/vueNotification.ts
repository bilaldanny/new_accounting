import VueNotification from '@dafcoe/vue-notification';

type NotificationOptions = Record<string, unknown>;

type NotificationStore = {
    setNotification: (options: NotificationOptions) => void;
    unsetNotification: (id: string) => void;
    notifications?: { value?: Record<string, unknown> };
};

type VueNotificationExports = {
    useNotificationStore?: () => NotificationStore;
    default?: VueNotificationExports;
};

function resolveVueNotificationExports(): VueNotificationExports {
    const mod = VueNotification as VueNotificationExports;

    if (typeof mod.useNotificationStore === 'function') {
        return mod;
    }

    if (mod.default && typeof mod.default.useNotificationStore === 'function') {
        return mod.default;
    }

    throw new Error('useNotificationStore is not available from @dafcoe/vue-notification');
}

export function useNotificationStore(): NotificationStore {
    return resolveVueNotificationExports().useNotificationStore!();
}
