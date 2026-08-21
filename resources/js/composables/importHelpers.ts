import { parseImportPaste } from '@/utils/parseImportPaste';
import { TABLE_EXPORT_PAGE_SIZE } from '@/composables/tableExportList';

export type ImportColumnDefinition = {
    heading: string;
    required: boolean;
    notes: string;
};

export type ImportSummary = {
    total: number;
    created: number;
    updated: number;
    failed: number;
};

export type ImportResult = {
    status: 'success' | 'error';
    message: string;
    summary: ImportSummary;
    errors: string[];
};

export function createImportSummary(
    total: number,
    overrides: Partial<ImportSummary> = {},
): ImportSummary {
    return {
        total: overrides.total ?? total,
        created: overrides.created ?? 0,
        updated: overrides.updated ?? 0,
        failed: overrides.failed ?? 0,
    };
}

function normalizeErrorList(errors: unknown): string[] {
    if (Array.isArray(errors)) {
        return errors.map((item) => String(item));
    }

    if (typeof errors === 'string') {
        return [errors];
    }

    if (errors && typeof errors === 'object') {
        const record = errors as Record<string, unknown>;

        if (Array.isArray(record.rows)) {
            return record.rows.map((item) => String(item));
        }

        if (typeof record.rows === 'string') {
            return [record.rows];
        }

        return Object.values(record).flatMap((value) => normalizeErrorList(value));
    }

    return [];
}

export function parseImportSuccessResponse(
    data: { message?: string; summary?: Partial<ImportSummary> } | undefined,
    attemptedTotal: number,
): ImportResult {
    return {
        status: 'success',
        message: data?.message ?? 'Import completed successfully.',
        summary: createImportSummary(attemptedTotal, data?.summary ?? {}),
        errors: [],
    };
}

export function parseImportErrorResponse(error: unknown, attemptedTotal: number): ImportResult {
    if (window.axios.isAxiosError(error)) {
        const data = error.response?.data as {
            message?: string;
            summary?: Partial<ImportSummary>;
            errors?: unknown;
        } | undefined;

        const errors = normalizeErrorList(data?.errors);

        if (errors.length === 0 && typeof data?.message === 'string') {
            errors.push(data.message);
        }

        if (errors.length === 0) {
            errors.push('Import failed.');
        }

        return {
            status: 'error',
            message: data?.message ?? 'Import could not be completed.',
            summary: createImportSummary(attemptedTotal, {
                ...(data?.summary ?? {}),
                failed: data?.summary?.failed ?? attemptedTotal,
            }),
            errors: [...new Set(errors)],
        };
    }

    if (error instanceof Error) {
        return {
            status: 'error',
            message: error.message,
            summary: createImportSummary(attemptedTotal, { failed: attemptedTotal }),
            errors: [error.message],
        };
    }

    return {
        status: 'error',
        message: 'Import failed.',
        summary: createImportSummary(attemptedTotal, { failed: attemptedTotal }),
        errors: ['Import failed.'],
    };
}

export function parseImportApiResponse(
    response: {
        data?: {
            message?: string;
            summary?: Partial<ImportSummary>;
            errormessage?: unknown;
            errors?: unknown;
        };
    },
    attemptedTotal: number,
): ImportResult {
    if (response.data?.errormessage) {
        const errorMessage = response.data.errormessage;
        let errorText = 'Import failed.';

        if (typeof errorMessage === 'object' && errorMessage !== null && 'errorInfo' in errorMessage) {
            const errorInfo = (errorMessage as { errorInfo?: string[] }).errorInfo;
            errorText = errorInfo?.[errorInfo.length - 1] ?? errorText;
        } else {
            errorText = String(errorMessage);
        }

        return {
            status: 'error',
            message: 'Import could not be completed.',
            summary: createImportSummary(attemptedTotal, { failed: attemptedTotal }),
            errors: [errorText],
        };
    }

    if (response.data?.errors) {
        return {
            status: 'error',
            message: response.data.message ?? 'Import could not be completed.',
            summary: createImportSummary(attemptedTotal, {
                ...(response.data.summary ?? {}),
                failed: response.data.summary?.failed ?? attemptedTotal,
            }),
            errors: normalizeErrorList(response.data.errors),
        };
    }

    return parseImportSuccessResponse(response.data, attemptedTotal);
}

export function buildImportColumns(
    headings: readonly string[],
    options: {
        required?: readonly string[];
        notes?: Record<string, string>;
    } = {},
): ImportColumnDefinition[] {
    const required = new Set(options.required ?? []);

    return headings.map((heading) => ({
        heading,
        required: required.has(heading),
        notes: options.notes?.[heading] ?? 'Optional. Leave blank to use the default.',
    }));
}

export function isImportRowEmpty(row: Record<string, unknown>): boolean {
    return Object.values(row).every((value) => String(value ?? '').trim() === '');
}

export function isSpreadsheetFile(file: File): boolean {
    const extension = file.name.split('.').pop()?.toLowerCase() ?? '';

    return ['xlsx', 'xls', 'csv'].includes(extension);
}

export async function parseSpreadsheetFile(file: File): Promise<Record<string, unknown>[]> {
    const extension = file.name.split('.').pop()?.toLowerCase() ?? '';

    if (extension === 'csv') {
        const text = await file.text();

        return parseImportPaste(text);
    }

    const XLSX = await import('xlsx');
    const buffer = await file.arrayBuffer();
    const workbook = XLSX.read(buffer, { type: 'array' });
    const sheetName = workbook.SheetNames[0];

    if (! sheetName) {
        throw new Error('The file does not contain any worksheets.');
    }

    const sheet = workbook.Sheets[sheetName];
    const rows = XLSX.utils.sheet_to_json<Record<string, unknown>>(sheet, { defval: '' });

    return rows.filter((row) => ! isImportRowEmpty(row));
}

export async function writeImportWorkbook(
    columns: readonly string[],
    rows: (string | number)[][],
    sheetName: string,
    fileName: string,
): Promise<void> {
    const XLSX = await import('xlsx');
    const worksheet = XLSX.utils.aoa_to_sheet([columns, ...rows]);
    const workbook = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(workbook, worksheet, sheetName);
    XLSX.writeFile(workbook, fileName);
}

export function buildPastePlaceholder(columns: readonly string[], sampleRow?: (string | number)[]): string {
    const headerLine = columns.join(',');
    const dataLine = (sampleRow ?? columns.map(() => '')).join(',');

    return `${headerLine}\n${dataLine}`;
}

export function normalizeImportExportValue(value: unknown): string | number {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    return value as string | number;
}

export function normalizeImportBool(value: unknown): number {
    return value === true || value === 1 || value === '1' ? 1 : 0;
}

export type ImportListQuery = Record<string, unknown>;

export async function fetchAllImportListRows(
    listApiUrl: string,
    query: ImportListQuery = {},
): Promise<Record<string, unknown>[]> {
    const params = {
        status: 'all',
        search: '',
        sort_by: 'created_at',
        sort_type: 'asc',
        show_record: TABLE_EXPORT_PAGE_SIZE,
        cur_page: 1,
        ...query,
    };

    const { data: body } = await window.axios.get(listApiUrl, { params });
    const paginator = body?.data;
    let rows = (paginator?.data ?? []) as Record<string, unknown>[];
    const lastPage = Number(paginator?.last_page) || 1;

    for (let page = 2; page <= lastPage; page++) {
        const { data: nextBody } = await window.axios.get(listApiUrl, {
            params: { ...params, cur_page: page },
        });

        rows = rows.concat((nextBody?.data?.data ?? []) as Record<string, unknown>[]);
    }

    return rows;
}

export async function downloadImportTemplate(options: {
    columns: readonly string[];
    sheetName: string;
    fileName: string;
    listApiUrl: string;
    listQuery?: ImportListQuery;
    mapRow: (row: Record<string, unknown>) => (string | number)[];
    fallbackRows: (string | number)[][];
}): Promise<void> {
    const records = await fetchAllImportListRows(options.listApiUrl, options.listQuery);
    const rows = records.length > 0
        ? records.map(options.mapRow)
        : options.fallbackRows;

    await writeImportWorkbook(options.columns, rows, options.sheetName, options.fileName);
}
