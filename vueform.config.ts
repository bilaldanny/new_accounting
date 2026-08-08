// vueform.config.(js|ts)

import en from '@vueform/vueform/locales/en'
import bootstrap from '@vueform/vueform/dist/bootstrap'
import { Validator, defineConfig } from '@vueform/vueform'
import PluginMask from '@vueform/plugin-mask'

const enExtended = {
    ...en,
    validation: {
        ...en.validation,
    },
}

async function postCheckIdentity(payload: Record<string, string>) {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
    const res = await fetch('/api/students/check-identity', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
    })
    if (!res.ok) {
        throw new Error(`HTTP ${res.status}`)
    }
    return res.json() as Promise<{ email_taken: boolean; username_taken: boolean }>
}

class StudentEmailUnique extends Validator {
    get isAsync() {
        return true
    }
    get debounce() {
        return 400
    }
    check(value: unknown) {
        const v = String(value ?? '').trim()
        if (!v) {
            return Promise.resolve(true)
        }
        return postCheckIdentity({ email: v })
            .then((data) => !data.email_taken)
            .catch(() => true)
    }
}

class StudentUsernameUnique extends Validator {
    get isAsync() {
        return true
    }
    get debounce() {
        return 400
    }
    check(value: unknown) {
        const v = String(value ?? '').trim()
        if (!v) {
            return Promise.resolve(true)
        }
        return postCheckIdentity({ username: v })
            .then((data) => !data.username_taken)
            .catch(() => true)
    }
}

export default defineConfig({
    theme: bootstrap,
    classHelpers: true,
    debounce: 500,
    locales: { en: enExtended },
    locale: 'en',
    /** DOMPurify (used for checkbox/label HTML) omits `target` by default; keep links that open in a new tab. */
    sanitizeOptions: {
        ADD_ATTR: ['target'],
    },
    rules: {
        student_email_unique: StudentEmailUnique,
        student_username_unique: StudentUsernameUnique,
    },
    // Vueform merges `axios` into its bundled axios (the old `http` key is not read by the installer).
    axios: {
        baseURL: import.meta.env.VITE_APP_URL || '',
        withCredentials: true,
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN':
                typeof document !== 'undefined'
                    ? document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
                    : '',
        },
    },
})
