import { ref } from 'vue';
import useCommons from './common';
import { API_ENDPOINTS } from './apiEndpoints';
import axios from 'axios';

export interface Notification {
    id: number;
    title: string;
    message: string;
    type: string;
    reference_id: number | null;
    is_read: boolean;
    created_at: string;
    action_url: string | null;
}

// Shared state so header and sidebar stay in sync
const notifications = ref<Notification[]>([]);
const unreadCount = ref(0);
const loading = ref(false);
const countsByType = ref<Record<string, number>>({});
let fetchNotificationsInFlight: Promise<void> | null = null;
let lastFetchedAtMs = 0;
const FETCH_NOTIFICATIONS_COOLDOWN_MS = 1500;

export default function useNotifications() {
    const { fetchWithRetry } = useCommons();

    const fetchNotifications = async (options?: { force?: boolean }) => {
        const force = options?.force === true;
        const now = Date.now();

        if (!force && now - lastFetchedAtMs < FETCH_NOTIFICATIONS_COOLDOWN_MS) {
            return;
        }

        if (fetchNotificationsInFlight) {
            return fetchNotificationsInFlight;
        }

        loading.value = true;

        fetchNotificationsInFlight = (async () => {
            try {
                const response = await fetchWithRetry(axios.get, API_ENDPOINTS.notifications, {
                    params: { limit: 20 },
                });
                notifications.value = response.data.notifications ?? [];
                unreadCount.value = response.data.unread_count ?? 0;
                countsByType.value = response.data.counts_by_type ?? {};
                lastFetchedAtMs = Date.now();
            } catch (error) {
                console.error('Error fetching notifications:', error);
            } finally {
                loading.value = false;
                fetchNotificationsInFlight = null;
            }
        })();

        return fetchNotificationsInFlight;
    };

    const markAsRead = async (id: number) => {
        try {
            await fetchWithRetry(axios.post, `${API_ENDPOINTS.notifications}/mark-read/${id}`);
            const n = notifications.value.find((x) => x.id === id);
            if (n) {
                n.is_read = true;
                if (n.type && countsByType.value[n.type] > 0) {
                    countsByType.value = { ...countsByType.value, [n.type]: countsByType.value[n.type] - 1 };
                }
            }
            if (unreadCount.value > 0) unreadCount.value--;
        } catch (error) {
            console.error('Error marking notification as read:', error);
        }
    };

    const markAllAsRead = async () => {
        try {
            await fetchWithRetry(axios.post, `${API_ENDPOINTS.notifications}/mark-all-read`);
            notifications.value.forEach((n) => (n.is_read = true));
            unreadCount.value = 0;
            countsByType.value = {};
        } catch (error) {
            console.error('Error marking all as read:', error);
        }
    };

    const fetchCountsByType = async () => {
        if (!API_ENDPOINTS.notifications) {
            return;
        }

        try {
            const response = await axios.get(`${API_ENDPOINTS.notifications}/counts-by-type`);
            countsByType.value = response.data.counts_by_type ?? {};
        } catch (error) {
            // Optional sidebar badges — fail silently when API is not available yet
            console.debug('Notification counts unavailable:', error);
        }
    };

    /** Get notification type from menu route path (e.g. /exam_retake_request -> exam_retake) */
    const getNotificationTypeFromRoute = (routePath: string | null): string | null => {
        if (!routePath) return null;
        const path = String(routePath).replace(/^\/+/, '').split('/')[0].toLowerCase();
        const map: Record<string, string> = {
            exam_retake_request: 'exam_retake',
            exam_retake_requests: 'exam_retake',
            modification_request: 'modification_request',
            modification_requests: 'modification_request',
            order: 'order',
            orders: 'order',
            quicklink: 'quick_link',
            quicklinks: 'quick_link',
        };
        return map[path] ?? null;
    };

    const timeAgo = (dateStr: string) => {
        const date = new Date(dateStr);
        const now = new Date();
        const seconds = Math.floor((now.getTime() - date.getTime()) / 1000);

        if (seconds < 60) return 'Just now';
        if (seconds < 3600) return `${Math.floor(seconds / 60)} minutes ago`;
        if (seconds < 86400) return `${Math.floor(seconds / 3600)} hours ago`;
        if (seconds < 604800) return `${Math.floor(seconds / 86400)} days ago`;
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const year = date.getFullYear();
        return `${month}-${day}-${year}`;
    };

    return {
        notifications,
        unreadCount,
        loading,
        countsByType,
        fetchNotifications,
        fetchCountsByType,
        markAsRead,
        markAllAsRead,
        getNotificationTypeFromRoute,
        timeAgo,
    };
}
