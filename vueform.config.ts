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
        department_name_unique: 'A department with this name already exists.',
        customer_group_name_unique: 'A customer group with this name already exists.',
        unit_name_unique: 'A unit with this name already exists.',
        brand_name_unique: 'A brand with this name already exists.',
        warranty_name_unique: 'A warranty with this name already exists.',
        product_name_unique: 'A product with this name already exists.',
        category_name_unique: 'A category with this name already exists.',
        itemtype_name_unique: 'An item type with this name already exists.',
        chart_of_account_code_unique: 'This account code is already taken.',
        timezone_name_unique: 'A timezone with this name already exists.',
        company_code_unique: 'This company code is already taken.',
        currency_code_unique: 'This currency code is already taken.',
        company_admin_email_unique: 'This admin email is already taken.',
        company_admin_username_unique: 'This admin username is already taken.',
        user_email_unique: 'This email is already taken.',
        user_username_unique: 'This username is already taken.',
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

async function postCheckCurrencyCode(payload: Record<string, string | number>) {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
    const res = await fetch('/api/currencies/check-code', {
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
    return res.json() as Promise<{ code_taken: boolean }>
}

async function postCheckCompanyCode(payload: Record<string, string | number>) {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
    const res = await fetch('/api/companies/check-code', {
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
    return res.json() as Promise<{ code_taken: boolean }>
}

async function postCheckCompanyAdminIdentity(payload: Record<string, string | number>) {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
    const res = await fetch('/api/companies/check-admin-identity', {
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
    return res.json() as Promise<{ email_taken?: boolean; username_taken?: boolean }>
}

async function postCheckUserIdentity(payload: Record<string, string | number>) {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
    const res = await fetch('/api/users/check-identity', {
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
    return res.json() as Promise<{ email_taken?: boolean; username_taken?: boolean }>
}

async function postCheckTimezoneName(payload: Record<string, string | number>) {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
    const res = await fetch('/api/timezones/check-name', {
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

async function postCheckDepartmentName(payload: Record<string, string | number>) {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
    const res = await fetch('/api/departments/check-name', {
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

async function postCheckCustomerGroupName(payload: Record<string, string | number>) {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
    const res = await fetch('/api/customer-groups/check-name', {
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

async function postCheckChartOfAccountCode(payload: Record<string, string | number>) {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
    const res = await fetch('/api/chart-of-accounts/check-code', {
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
    return res.json() as Promise<{ code_taken: boolean }>
}

async function postCheckUnitName(payload: Record<string, string | number>) {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
    const res = await fetch('/api/units/check-name', {
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

async function postCheckBrandName(payload: Record<string, string | number>) {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
    const res = await fetch('/api/brands/check-name', {
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

async function postCheckWarrantyName(payload: Record<string, string | number>) {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
    const res = await fetch('/api/warranties/check-name', {
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

async function postCheckProductName(payload: Record<string, string | number>) {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
    const res = await fetch('/api/products/check-name', {
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

async function postCheckItemTypeName(payload: Record<string, string | number>) {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
    const res = await fetch('/api/item-types/check-name', {
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

async function postCheckCategoryName(payload: Record<string, string | number>) {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
    const res = await fetch('/api/categories/check-name', {
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

type ValidatorWithAttributes = Validator & {
    attributes: Record<string | number, unknown>
    form$?: { data?: Record<string, unknown> }
}

function ruleExceptId(
    validator: Validator,
    formFallbackKey?: string,
): number | undefined {
    const ctx = validator as ValidatorWithAttributes
    const raw = ctx.attributes?.[0] ?? (formFallbackKey ? ctx.form$?.data?.[formFallbackKey] : undefined)

    if (raw === undefined || raw === null || String(raw) === '') {
        return undefined
    }

    return Number(raw)
}

class CurrencyCodeUnique extends Validator {
    get isAsync() {
        return true
    }
    get debounce() {
        return 400
    }
    check(value: unknown) {
        const code = String(value ?? '').trim()
        if (!code) {
            return Promise.resolve(true)
        }

        const exceptId = ruleExceptId(this)
        const payload: Record<string, string | number> = { code }

        if (exceptId !== undefined) {
            payload.except_id = exceptId
        }

        return postCheckCurrencyCode(payload)
            .then((data) => !data.code_taken)
            .catch(() => true)
    }
}

class CompanyCodeUnique extends Validator {
    get isAsync() {
        return true
    }
    get debounce() {
        return 400
    }
    check(value: unknown) {
        const code = String(value ?? '').trim()
        if (!code) {
            return Promise.resolve(true)
        }

        const exceptId = ruleExceptId(this, 'id')
        const payload: Record<string, string | number> = { code }

        if (exceptId !== undefined) {
            payload.except_id = exceptId
        }

        return postCheckCompanyCode(payload)
            .then((data) => !data.code_taken)
            .catch(() => true)
    }
}

class CompanyAdminEmailUnique extends Validator {
    get isAsync() {
        return true
    }
    get debounce() {
        return 400
    }
    check(value: unknown) {
        const email = String(value ?? '').trim()
        if (!email) {
            return Promise.resolve(true)
        }

        const exceptUserId = ruleExceptId(this, 'user_id')
        const payload: Record<string, string | number> = { email }

        if (exceptUserId !== undefined) {
            payload.except_user_id = exceptUserId
        }

        return postCheckCompanyAdminIdentity(payload)
            .then((data) => !data.email_taken)
            .catch(() => true)
    }
}

class CompanyAdminUsernameUnique extends Validator {
    get isAsync() {
        return true
    }
    get debounce() {
        return 400
    }
    check(value: unknown) {
        const username = String(value ?? '').trim().toLowerCase().replace(/\s+/g, '')
        if (!username) {
            return Promise.resolve(true)
        }

        const exceptUserId = ruleExceptId(this, 'user_id')
        const payload: Record<string, string | number> = { username }

        if (exceptUserId !== undefined) {
            payload.except_user_id = exceptUserId
        }

        return postCheckCompanyAdminIdentity(payload)
            .then((data) => !data.username_taken)
            .catch(() => true)
    }
}

class UserEmailUnique extends Validator {
    get isAsync() {
        return true
    }
    get debounce() {
        return 400
    }
    check(value: unknown) {
        const email = String(value ?? '').trim()
        if (!email) {
            return Promise.resolve(true)
        }

        const exceptUserId = ruleExceptId(this, 'id')
        const payload: Record<string, string | number> = { email }

        if (exceptUserId !== undefined) {
            payload.except_user_id = exceptUserId
        }

        return postCheckUserIdentity(payload)
            .then((data) => !data.email_taken)
            .catch(() => true)
    }
}

class UserUsernameUnique extends Validator {
    get isAsync() {
        return true
    }
    get debounce() {
        return 400
    }
    check(value: unknown) {
        const username = String(value ?? '').trim().toLowerCase().replace(/\s+/g, '')
        if (!username) {
            return Promise.resolve(true)
        }

        const exceptUserId = ruleExceptId(this, 'id')
        const payload: Record<string, string | number> = { username }

        if (exceptUserId !== undefined) {
            payload.except_user_id = exceptUserId
        }

        return postCheckUserIdentity(payload)
            .then((data) => !data.username_taken)
            .catch(() => true)
    }
}

class TimezoneNameUnique extends Validator {
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

        const exceptId = ruleExceptId(this)
        const payload: Record<string, string | number> = { name }

        if (exceptId !== undefined) {
            payload.except_id = exceptId
        }

        return postCheckTimezoneName(payload)
            .then((data) => !data.name_taken)
            .catch(() => true)
    }
}

class DepartmentNameUnique extends Validator {
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

        const exceptId = ruleExceptId(this)
        const formData = (this as ValidatorWithAttributes).form$?.data ?? {}
        const payload: Record<string, string | number> = { name }

        if (exceptId !== undefined) {
            payload.except_id = exceptId
        }

        if (formData.company_id !== undefined && formData.company_id !== null && formData.company_id !== '') {
            payload.company_id = Number(formData.company_id)
        }

        if (formData.branch_id !== undefined && formData.branch_id !== null && formData.branch_id !== '') {
            payload.branch_id = Number(formData.branch_id)
        }

        return postCheckDepartmentName(payload)
            .then((data) => !data.name_taken)
            .catch(() => true)
    }
}

class CustomerGroupNameUnique extends Validator {
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

        const exceptId = ruleExceptId(this)
        const formData = (this as ValidatorWithAttributes).form$?.data ?? {}
        const payload: Record<string, string | number> = { name }

        if (exceptId !== undefined) {
            payload.except_id = exceptId
        }

        if (formData.company_id !== undefined && formData.company_id !== null && formData.company_id !== '') {
            payload.company_id = Number(formData.company_id)
        }

        if (formData.branch_id !== undefined && formData.branch_id !== null && formData.branch_id !== '') {
            payload.branch_id = Number(formData.branch_id)
        }

        return postCheckCustomerGroupName(payload)
            .then((data) => !data.name_taken)
            .catch(() => true)
    }
}

class ChartOfAccountCodeUnique extends Validator {
    get isAsync() {
        return true
    }
    get debounce() {
        return 400
    }
    check(value: unknown) {
        const code = String(value ?? '').trim()
        if (!code) {
            return Promise.resolve(true)
        }

        const exceptId = ruleExceptId(this, 'id')
        const formData = (this as ValidatorWithAttributes).form$?.data ?? {}
        const payload: Record<string, string | number> = { code }

        if (exceptId !== undefined) {
            payload.except_id = exceptId
        }

        if (formData.company_id !== undefined && formData.company_id !== null && formData.company_id !== '') {
            payload.company_id = Number(formData.company_id)
        }

        if (formData.branch_id !== undefined && formData.branch_id !== null && formData.branch_id !== '') {
            payload.branch_id = Number(formData.branch_id)
        }

        return postCheckChartOfAccountCode(payload)
            .then((data) => !data.code_taken)
            .catch(() => true)
    }
}

class UnitNameUnique extends Validator {
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

        const exceptId = ruleExceptId(this)
        const formData = (this as ValidatorWithAttributes).form$?.data ?? {}
        const payload: Record<string, string | number> = { name }

        if (exceptId !== undefined) {
            payload.except_id = exceptId
        }

        if (formData.company_id !== undefined && formData.company_id !== null && formData.company_id !== '') {
            payload.company_id = Number(formData.company_id)
        }

        return postCheckUnitName(payload)
            .then((data) => !data.name_taken)
            .catch(() => true)
    }
}

class BrandNameUnique extends Validator {
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

        const exceptId = ruleExceptId(this)
        const formData = (this as ValidatorWithAttributes).form$?.data ?? {}
        const payload: Record<string, string | number> = { name }

        if (exceptId !== undefined) {
            payload.except_id = exceptId
        }

        if (formData.company_id !== undefined && formData.company_id !== null && formData.company_id !== '') {
            payload.company_id = Number(formData.company_id)
        }

        return postCheckBrandName(payload)
            .then((data) => !data.name_taken)
            .catch(() => true)
    }
}

class WarrantyNameUnique extends Validator {
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

        const exceptId = ruleExceptId(this)
        const formData = (this as ValidatorWithAttributes).form$?.data ?? {}
        const payload: Record<string, string | number> = { name }

        if (exceptId !== undefined) {
            payload.except_id = exceptId
        }

        if (formData.company_id !== undefined && formData.company_id !== null && formData.company_id !== '') {
            payload.company_id = Number(formData.company_id)
        }

        return postCheckWarrantyName(payload)
            .then((data) => !data.name_taken)
            .catch(() => true)
    }
}

class ProductNameUnique extends Validator {
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

        const exceptId = ruleExceptId(this)
        const formData = (this as ValidatorWithAttributes).form$?.data ?? {}
        const payload: Record<string, string | number> = { name }

        if (exceptId !== undefined) {
            payload.except_id = exceptId
        }

        if (formData.company_id !== undefined && formData.company_id !== null && formData.company_id !== '') {
            payload.company_id = Number(formData.company_id)
        }

        return postCheckProductName(payload)
            .then((data) => !data.name_taken)
            .catch(() => true)
    }
}

class ItemTypeNameUnique extends Validator {
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

        const exceptId = ruleExceptId(this)
        const formData = (this as ValidatorWithAttributes).form$?.data ?? {}
        const payload: Record<string, string | number> = { name }

        if (exceptId !== undefined) {
            payload.except_id = exceptId
        }

        if (formData.company_id !== undefined && formData.company_id !== null && formData.company_id !== '') {
            payload.company_id = Number(formData.company_id)
        }

        return postCheckItemTypeName(payload)
            .then((data) => !data.name_taken)
            .catch(() => true)
    }
}

class CategoryNameUnique extends Validator {
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

        const exceptId = ruleExceptId(this)
        const formData = (this as ValidatorWithAttributes).form$?.data ?? {}
        const payload: Record<string, string | number> = { name }

        if (exceptId !== undefined) {
            payload.except_id = exceptId
        }

        if (formData.company_id !== undefined && formData.company_id !== null && formData.company_id !== '') {
            payload.company_id = Number(formData.company_id)
        }

        return postCheckCategoryName(payload)
            .then((data) => !data.name_taken)
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

        const exceptId = ruleExceptId(this)
        const formData = (this as ValidatorWithAttributes).form$?.data ?? {}
        const payload: Record<string, string | number> = { name }

        if (exceptId !== undefined) {
            payload.except_id = exceptId
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
    plugins: [PluginMask],
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
        currency_code_unique: CurrencyCodeUnique,
        company_code_unique: CompanyCodeUnique,
        company_admin_email_unique: CompanyAdminEmailUnique,
        company_admin_username_unique: CompanyAdminUsernameUnique,
        user_email_unique: UserEmailUnique,
        user_username_unique: UserUsernameUnique,
        role_name_unique: RoleNameUnique,
        department_name_unique: DepartmentNameUnique,
        customer_group_name_unique: CustomerGroupNameUnique,
        unit_name_unique: UnitNameUnique,
        brand_name_unique: BrandNameUnique,
        warranty_name_unique: WarrantyNameUnique,
        product_name_unique: ProductNameUnique,
        category_name_unique: CategoryNameUnique,
        itemtype_name_unique: ItemTypeNameUnique,
        chart_of_account_code_unique: ChartOfAccountCodeUnique,
        timezone_name_unique: TimezoneNameUnique,
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
