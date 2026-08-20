export type ChartOfAccountClassification = {
    key: string;
    label: string;
    bs: boolean;
    pl: boolean;
    default_nature: 'dr' | 'cr';
    financial_statement: string;
    allow_transactions: boolean;
};

export type ControlAccountOption = {
    id: number | string;
    parent_id?: number | string | null;
    code?: string;
    text?: string;
    name?: string;
    bs?: boolean;
    pl?: boolean;
    acc_type?: 't' | 'c';
};

export function classificationFromCode(code: string): ChartOfAccountClassification {
    const leadingDigit = Number.parseInt(String(code).split('-')[0]?.charAt(0) ?? '0', 10);

    const map: Record<number, Omit<ChartOfAccountClassification, 'financial_statement' | 'allow_transactions'>> = {
        1: { key: 'equity', label: 'Equity', bs: true, pl: false, default_nature: 'cr' },
        2: { key: 'asset', label: 'Asset', bs: true, pl: false, default_nature: 'dr' },
        3: { key: 'liability', label: 'Liability', bs: true, pl: false, default_nature: 'cr' },
        4: { key: 'expense', label: 'Expense', bs: false, pl: true, default_nature: 'dr' },
        5: { key: 'revenue', label: 'Revenue', bs: false, pl: true, default_nature: 'cr' },
        6: { key: 'cost_of_sales', label: 'Cost of Sales', bs: false, pl: true, default_nature: 'dr' },
    };

    const base = map[leadingDigit] ?? {
        key: 'unknown',
        label: 'Unknown',
        bs: false,
        pl: false,
        default_nature: 'dr' as const,
    };

    return {
        ...base,
        financial_statement: base.bs ? 'Balance Sheet' : 'Profit & Loss',
        allow_transactions: true,
    };
}

export function classificationFromFinancialFlags(bs?: boolean, pl?: boolean): ChartOfAccountClassification | null {
    if (bs === undefined && pl === undefined) {
        return null;
    }

    const isBalanceSheet = Boolean(bs);

    return {
        key: isBalanceSheet ? 'balance_sheet' : 'profit_and_loss',
        label: isBalanceSheet ? 'Balance Sheet Account' : 'Profit & Loss Account',
        bs: isBalanceSheet,
        pl: ! isBalanceSheet,
        default_nature: 'dr',
        financial_statement: isBalanceSheet ? 'Balance Sheet' : 'Profit & Loss',
        allow_transactions: true,
    };
}

export function findControlAccountById(
    accounts: ControlAccountOption[],
    id: unknown,
): ControlAccountOption | undefined {
    const normalizedId = String(id ?? '');

    return accounts.find((account) => String(account.id) === normalizedId);
}

export function resolveRootAccount(
    account: ControlAccountOption | undefined,
    accounts: ControlAccountOption[],
): ControlAccountOption | undefined {
    if (! account) {
        return undefined;
    }

    let current = account;
    let guard = 0;

    while (current.parent_id && guard < 50) {
        const parent = findControlAccountById(accounts, current.parent_id);

        if (! parent) {
            break;
        }

        current = parent;
        guard += 1;
    }

    return current;
}

export function resolveClassificationForParent(
    parentId: unknown,
    accounts: ControlAccountOption[],
    accGroup: string = 't',
): ChartOfAccountClassification | null {
    const parent = findControlAccountById(accounts, parentId);

    if (! parent?.code) {
        return null;
    }

    const root = resolveRootAccount(parent, accounts) ?? parent;
    const classification = classificationFromCode(root.code ?? '');

    return {
        ...classification,
        allow_transactions: accGroup === 't',
    };
}

export function resolveClassificationForAccount(
    code: string | undefined,
    bs?: boolean,
    pl?: boolean,
    accGroup: string = 't',
): ChartOfAccountClassification | null {
    if (code) {
        return {
            ...classificationFromCode(code),
            allow_transactions: accGroup === 't',
        };
    }

    const fromFlags = classificationFromFinancialFlags(bs, pl);

    if (! fromFlags) {
        return null;
    }

    return {
        ...fromFlags,
        allow_transactions: accGroup === 't',
    };
}
