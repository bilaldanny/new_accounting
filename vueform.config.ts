// vueform.config.(js|ts)

import en from '@vueform/vueform/locales/en'
import bootstrap from '@vueform/vueform/dist/bootstrap'
import { Validator, defineConfig } from '@vueform/vueform'
import PluginMask from '@vueform/plugin-mask'

const enExtended = {
    ...en,
    validation: {
        ...en.validation,
        role_name_unique: 'A role with this name already exists.',
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

async function postCheckRoleName(payload: Record<string, string | number>) {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
    const res = await fetch('/api/roles/check-name', {
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
    return res.json() as Promise<{ name_taken: boolean }>
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

class RoleNameUnique extends Validator {
    get isAsync() {
        return true
    }
    get debounce() {
        return 400
    }
    check(value: unknown) {
        const name = String(value ?? '').trim()
        if (!name) {
            return Promise.resolve(true)
        }

        const exceptId = this.params?.[0]
        const formData = (this as { form$?: { data?: Record<string, unknown> } }).form$?.data ?? {}
        const payload: Record<string, string | number> = { name }

        if (exceptId !== undefined && exceptId !== null && String(exceptId) !== '') {
            payload.except_id = Number(exceptId)
        }

        if (formData.company_id !== undefined && formData.company_id !== null && formData.company_id !== '') {
            payload.company_id = Number(formData.company_id)
        }

        if (formData.branch_id !== undefined && formData.branch_id !== null && formData.branch_id !== '') {
            payload.branch_id = Number(formData.branch_id)
        }

        return postCheckRoleName(payload)
            .then((data) => !data.name_taken)
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
        role_name_unique: RoleNameUnique,
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
