/**
 * The UI language framework: a dictionary per language, `t(key, fallback)` and the page direction. A key that has no entry falls
 * back to the English text passed by the caller (or the key), so a screen that is not translated yet simply stays in English.
 *
 * The language is the company's default (Business Settings, shared as the `locale` prop) unless the person chose another one,
 * which is kept in this browser only. Menu names come from the database and are not translated here.
 */
export type Locale = 'en' | 'ur';

export const dictionaries: Record<Locale, Record<string, string>> = {
    en: {},
    ur: {
        'app.dashboard': 'ڈیش بورڈ',
        'app.logout': 'لاگ آؤٹ',
        'app.search': 'تلاش کریں',
        'app.save': 'محفوظ کریں',
        'app.cancel': 'منسوخ کریں',
        'app.delete': 'حذف کریں',
        'app.edit': 'ترمیم',
        'app.add': 'شامل کریں',
        'app.close': 'بند کریں',
        'app.print': 'پرنٹ',
        'app.export': 'ایکسپورٹ',
        'app.yes': 'جی ہاں',
        'app.no': 'نہیں',
        'app.total': 'کل',
        'app.date': 'تاریخ',
        'app.amount': 'رقم',
        'app.status': 'حالت',
        'app.customer': 'گاہک',
        'app.supplier': 'سپلائر',
        'app.invoice': 'انوائس',
        'app.balance': 'بقایا',
        'app.language': 'زبان',
        'settings.title': 'کاروباری ترتیبات',
        'settings.number_format': 'اعداد کی شکل',
        'setup.title': 'سیٹ اپ مددگار',
        'help.title': 'مدد مرکز',
    },
};

let current: Locale = 'en';

export function setLocale(locale: string | null | undefined): void {
    current = locale === 'ur' ? 'ur' : 'en';

    if (typeof document !== 'undefined') {
        document.documentElement.setAttribute('lang', current);
        document.documentElement.setAttribute('dir', current === 'ur' ? 'rtl' : 'ltr');
    }
}

export function currentLocale(): Locale {
    return current;
}

export function t(key: string, fallback?: string): string {
    return dictionaries[current][key] ?? fallback ?? key;
}

/** The person's own choice (this browser), else the company default. */
export function preferredLocale(companyDefault: string | null | undefined): Locale {
    try {
        const stored = window.localStorage.getItem('app-locale');

        if (stored === 'en' || stored === 'ur') {
            return stored;
        }
    } catch {
        // storage can be blocked; the company default applies
    }

    return companyDefault === 'ur' ? 'ur' : 'en';
}

export function rememberLocale(locale: Locale): void {
    try {
        window.localStorage.setItem('app-locale', locale);
    } catch {
        // nothing to remember where storage is blocked
    }

    setLocale(locale);
}
