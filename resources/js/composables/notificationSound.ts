import { ref } from 'vue';

/**
 * The user's choice for the sounds of notifications (the success / error / warning sounds of the messages and alerts, approval and
 * reminder alerts, to-do reminders). It is the user's own preference, saved on their account so it follows them to every device;
 * the browser keeps a copy so the choice also holds while the page is still loading or the server cannot be reached.
 *
 * The beep of the barcode scanner at the till is a different thing with its own setting (Scanner settings on the POS) and is
 * never touched by this switch.
 */
const STORAGE_KEY = 'notification-sound';

function readLocal(): boolean {
    try {
        return window.localStorage.getItem(STORAGE_KEY) !== 'off';
    } catch {
        return true;
    }
}

export const notificationSoundOn = ref<boolean>(typeof window === 'undefined' ? true : readLocal());

/** Takes the value the server sent with the page (the account's preference wins over the browser's copy). */
export function syncNotificationSound(fromServer: boolean | null | undefined): void {
    if (typeof fromServer === 'boolean') {
        notificationSoundOn.value = fromServer;
        writeLocal(fromServer);
    }
}

function writeLocal(on: boolean): void {
    try {
        window.localStorage.setItem(STORAGE_KEY, on ? 'on' : 'off');
    } catch {
        // storage blocked: the account setting still holds
    }
}

/** Switches the sound and saves it on the account. A failed save keeps the browser's copy and tries again at the next change. */
export async function setNotificationSound(on: boolean): Promise<void> {
    notificationSoundOn.value = on;
    writeLocal(on);

    try {
        await window.axios.put('/api/me/preferences', { notification_sound: on });
    } catch {
        // offline or signed out: the local copy is used until the next successful save
    }
}
