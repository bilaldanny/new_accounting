import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import useCommons from '@/composables/common';

/**
 * What the small closeout pages (custom attributes, discount rules, warranty claims, consignment, kits, opening stock and
 * the procurement / sales / accounts pages that follow) share: the permission check, a company id for a superadmin (a
 * company user is always scoped to their own company by the server), a readable error and a money format.
 */
export default function useCloseout() {
    const page = usePage();
    const { Notify } = useCommons();

    const user = computed(() => page.props.auth?.user as { rolename?: string; permission_paths?: string[] } | null);
    const isSuperadmin = computed(() => String(user.value?.rolename ?? '').toLowerCase().replace(/\s+/g, '') === 'superadmin');
    const can = (path: string): boolean => isSuperadmin.value || (user.value?.permission_paths ?? []).includes(path);

    const companyId = ref<number | ''>('');

    /** Query / body fields that say which company a superadmin means. */
    const scope = (): Record<string, number> => (isSuperadmin.value && companyId.value !== '' ? { company_id: Number(companyId.value) } : {});

    function errorMessage(error: unknown, fallback = 'The request failed.'): string {
        if (! window.axios.isAxiosError(error)) {
            return 'Unexpected error occurred';
        }

        const data = error.response?.data;
        const first = data?.errors ? (Object.values(data.errors as Record<string, string[]>).flat()[0] ?? null) : null;

        return first || data?.message || data?.errormessage || fallback;
    }

    const money = (value: number | string | null | undefined): string => Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    async function run<T>(work: () => Promise<T>, success?: string): Promise<T | null> {
        try {
            const result = await work();

            if (success) {
                Notify(success, 'success');
            }

            return result;
        } catch (error: unknown) {
            const message = errorMessage(error);

            // a superadmin who has not chosen a company yet is told by the company picker on the page; a pop-up for it on every load is noise
            if (isSuperadmin.value && companyId.value === '' && /^choose a company/i.test(message)) {
                return null;
            }

            Notify(message, 'alert');

            return null;
        }
    }

    return { isSuperadmin, can, companyId, scope, errorMessage, money, run, Notify };
}
