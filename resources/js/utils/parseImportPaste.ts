function detectDelimiter(line: string): string {
    const tabs = (line.match(/\t/g) ?? []).length;
    const semicolons = (line.match(/;/g) ?? []).length;
    const commas = (line.match(/,/g) ?? []).length;

    if (tabs > semicolons && tabs > commas) {
        return '\t';
    }

    if (semicolons > commas) {
        return ';';
    }

    return ',';
}

function parseDelimitedLine(line: string, delimiter: string): string[] {
    const values: string[] = [];
    let current = '';
    let inQuotes = false;

    for (let index = 0; index < line.length; index++) {
        const char = line[index];

        if (char === '"') {
            if (inQuotes && line[index + 1] === '"') {
                current += '"';
                index++;
            } else {
                inQuotes = ! inQuotes;
            }

            continue;
        }

        if (char === delimiter && ! inQuotes) {
            values.push(current.trim());
            current = '';

            continue;
        }

        current += char;
    }

    values.push(current.trim());

    return values;
}

function isRowEmpty(row: Record<string, unknown>): boolean {
    return Object.values(row).every((value) => String(value ?? '').trim() === '');
}

export function parseImportPaste(text: string): Record<string, unknown>[] {
    const lines = text
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter((line) => line !== '');

    if (lines.length < 2) {
        return [];
    }

    const delimiter = detectDelimiter(lines[0]);
    const headers = parseDelimitedLine(lines[0], delimiter).map((header) => header.trim());
    const rows: Record<string, unknown>[] = [];

    for (let lineIndex = 1; lineIndex < lines.length; lineIndex++) {
        const values = parseDelimitedLine(lines[lineIndex], delimiter);
        const row: Record<string, unknown> = {};

        headers.forEach((header, headerIndex) => {
            if (header === '') {
                return;
            }

            row[header] = values[headerIndex] ?? '';
        });

        if (! isRowEmpty(row)) {
            rows.push(row);
        }
    }

    return rows;
}
