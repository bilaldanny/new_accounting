<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ChartOfAccount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'branch_id',
        'parent_id',
        'code',
        'name',
        'acc_type',
        'acc_nature',
        'pl',
        'bs',
        'active',
        'branches',
    ];

    protected function casts(): array
    {
        return [
            'pl' => 'boolean',
            'bs' => 'boolean',
            'active' => 'boolean',
        ];
    }

    protected function active(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === 1 || $value === '1' || $value === true,
            set: fn ($value) => ($value === 'false' || $value === false || $value === 0 || $value === '0') ? 0 : 1,
        );
    }

    protected function pl(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === 1 || $value === '1' || $value === true,
            set: fn ($value) => ($value === 'false' || $value === false || $value === 0 || $value === '0') ? 0 : 1,
        );
    }

    protected function bs(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === 1 || $value === '1' || $value === true,
            set: fn ($value) => ($value === 'false' || $value === false || $value === 0 || $value === '0') ? 0 : 1,
        );
    }

    protected function companyId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    protected function branchId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    protected function parentId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<ChartOfAccount, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    public function scopeVisibleToCurrentUser(Builder $query): Builder
    {
        return $query;
    }

    public static function findVisibleToCurrentUser(int $id): ?self
    {
        return self::query()->visibleToCurrentUser()->find($id);
    }

    public static function resolveScopedId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'undefined') {
            return null;
        }

        return (int) $value;
    }

    public static function codeExists(
        string $code,
        ?int $companyId = null,
        ?int $branchId = null,
        ?int $exceptId = null,
    ): bool {
        return self::query()
            ->when($exceptId !== null, fn (Builder $query) => $query->where('id', '!=', $exceptId))
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->where('code', $code)
            ->exists();
    }

    /**
     * @throws ValidationException
     */
    public static function assertUniqueCode(
        string $code,
        ?int $companyId = null,
        ?int $branchId = null,
        ?int $exceptId = null,
    ): void {
        if (self::codeExists($code, $companyId, $branchId, $exceptId)) {
            throw ValidationException::withMessages([
                'code' => ['This account code is already taken.'],
            ]);
        }
    }

    /**
     * @return \Closure(HasMany): void
     */
    public static function nestedChildrenRelation(): \Closure
    {
        return function ($query): void {
            $query->orderBy('code')->with(['children' => self::nestedChildrenRelation()]);
        };
    }

    /**
     * @param  Collection<int, ChartOfAccount>  $accounts
     * @return array<int, array<string, mixed>>
     */
    public static function buildTree(Collection $accounts, ?int $parentId = null): array
    {
        return $accounts
            ->filter(function (self $account) use ($parentId) {
                $rawParentId = $account->getRawOriginal('parent_id');

                if ($parentId === null) {
                    return $rawParentId === null;
                }

                return (int) $rawParentId === $parentId;
            })
            ->values()
            ->map(function (self $account) use ($accounts) {
                $node = $account->toArray();
                $node['parent_id'] = $account->getRawOriginal('parent_id');
                $node['children'] = self::buildTree($accounts, $account->id);

                return $node;
            })
            ->all();
    }

    public static function createChartOfAccount(object $request): self
    {
        $companyId = self::resolveScopedId($request->company_id);
        $branchId = self::resolveScopedId($request->branch_id);
        $parentId = self::resolveScopedId($request->parent_id);

        self::assertUniqueCode($request->code, $companyId, $branchId);

        $parent = self::query()->findOrFail($parentId);
        self::assertParentInScope($parent, $companyId, $branchId);

        $classification = self::classificationFromCode($parent->resolveRootAccount()->code);

        $chartOfAccount = new self;
        $chartOfAccount->parent_id = $parentId;
        $chartOfAccount->company_id = $companyId;
        $chartOfAccount->branch_id = $branchId;
        $chartOfAccount->name = $request->name;
        $chartOfAccount->code = $request->code;
        $chartOfAccount->acc_type = $request->acc_type;
        $chartOfAccount->acc_nature = $request->acc_nature;
        $chartOfAccount->active = $request->active ?? true;
        $chartOfAccount->bs = $classification['bs'];
        $chartOfAccount->pl = $classification['pl'];
        $chartOfAccount->save();

        return $chartOfAccount;
    }

    public static function updateChartOfAccount(object $request, int $id): self
    {
        $chartOfAccount = self::findVisibleToCurrentUser($id);

        if ($chartOfAccount === null) {
            abort(404);
        }

        $parentId = self::resolveScopedId($request->parent_id);
        $parent = self::query()->findOrFail($parentId);

        self::assertParentInScope(
            $parent,
            (int) $chartOfAccount->getRawOriginal('company_id'),
            (int) $chartOfAccount->getRawOriginal('branch_id'),
        );
        self::assertNotCircular($id, $parentId);

        $classification = self::classificationFromCode($parent->resolveRootAccount()->code);

        $chartOfAccount->parent_id = $parentId;
        $chartOfAccount->name = $request->name;
        $chartOfAccount->active = $request->active ?? true;
        $chartOfAccount->bs = $classification['bs'];
        $chartOfAccount->pl = $classification['pl'];
        $chartOfAccount->save();

        return $chartOfAccount;
    }

    public static function generateAccountCode(int $parentId, int $companyId, int $branchId, string $accType = 't'): string
    {
        $account = self::query()
            ->with(['parent.parent'])
            ->findOrFail($parentId);

        if ($accType === 't') {
            return self::generateTransactionalAccountCode($account, $companyId, $branchId);
        }

        $parentAccountId = $account->getRawOriginal('parent_id');

        if ($parentAccountId === null) {
            $childCount = self::query()
                ->where('parent_id', $account->id)
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->count();

            $codePrefix = explode('-', (string) $account->code)[0];
            $code = substr($codePrefix, 0, 1).($childCount + 1).'0'.'-00000';

            return ensureUniqueChartOfAccountCode($code, $companyId, $branchId);
        }

        $codePrefix = explode('-', (string) $account->code)[0];
        $grandParentId = $account->parent?->getRawOriginal('parent_id');

        if ($grandParentId === null) {
            $childCount = self::query()
                ->where('parent_id', $account->id)
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->count();

            $code = substr($codePrefix, 0, 2).($childCount + 1).'-00000';

            return ensureUniqueChartOfAccountCode($code, $companyId, $branchId);
        }

        $parts = explode('-', (string) $account->code);
        $childCount = self::query()
            ->where('parent_id', $account->id)
            ->count();

        $code = $parts[0].'-'.str_pad((string) (((int) $parts[1]) + $childCount + 1), 5, '0', STR_PAD_LEFT);

        return ensureUniqueChartOfAccountCode($code, $companyId, $branchId);
    }

    private static function generateTransactionalAccountCode(self $parent, int $companyId, int $branchId): string
    {
        $parts = explode('-', (string) $parent->code, 2);
        $prefix = $parts[0];
        $baseSuffix = isset($parts[1]) ? (int) $parts[1] : 0;

        $childCount = self::query()
            ->where('parent_id', $parent->id)
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->count();

        $code = $prefix.'-'.str_pad((string) ($baseSuffix + $childCount + 1), 5, '0', STR_PAD_LEFT);

        return ensureUniqueChartOfAccountCode($code, $companyId, $branchId);
    }

    public function resolveRootAccount(): self
    {
        $account = $this;

        for ($guard = 0; $guard < 50; $guard++) {
            $parentId = $account->getRawOriginal('parent_id');

            if ($parentId === null) {
                break;
            }

            $parent = self::query()->find($parentId);

            if ($parent === null) {
                break;
            }

            $account = $parent;
        }

        return $account;
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     bs: bool,
     *     pl: bool,
     *     default_nature: string,
     *     financial_statement: string,
     *     allow_transactions: bool
     * }
     */
    public static function classificationFromCode(string $code): array
    {
        $leadingDigit = (int) substr((string) explode('-', $code)[0], 0, 1);

        $map = [
            1 => ['key' => 'equity', 'label' => 'Equity', 'bs' => true, 'pl' => false, 'default_nature' => 'cr'],
            2 => ['key' => 'asset', 'label' => 'Asset', 'bs' => true, 'pl' => false, 'default_nature' => 'dr'],
            3 => ['key' => 'liability', 'label' => 'Liability', 'bs' => true, 'pl' => false, 'default_nature' => 'cr'],
            4 => ['key' => 'expense', 'label' => 'Expense', 'bs' => false, 'pl' => true, 'default_nature' => 'dr'],
            5 => ['key' => 'revenue', 'label' => 'Revenue', 'bs' => false, 'pl' => true, 'default_nature' => 'cr'],
            6 => ['key' => 'cost_of_sales', 'label' => 'Cost of Sales', 'bs' => false, 'pl' => true, 'default_nature' => 'dr'],
        ];

        $classification = $map[$leadingDigit] ?? [
            'key' => 'unknown',
            'label' => 'Unknown',
            'bs' => false,
            'pl' => false,
            'default_nature' => 'dr',
        ];

        $classification['financial_statement'] = $classification['bs'] ? 'Balance Sheet' : 'Profit & Loss';
        $classification['allow_transactions'] = true;

        return $classification;
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     bs: bool,
     *     pl: bool,
     *     default_nature: string,
     *     financial_statement: string,
     *     allow_transactions: bool
     * }
     */
    public static function metadataForParent(int $parentId, string $accGroup = 't'): array
    {
        $parent = self::query()->findOrFail($parentId);
        $classification = self::classificationFromCode($parent->resolveRootAccount()->code);
        $classification['allow_transactions'] = $accGroup === 't';

        return $classification;
    }

    public static function assertParentInScope(self $parent, ?int $companyId, ?int $branchId): void
    {
        if ($companyId !== null && (int) $parent->getRawOriginal('company_id') !== $companyId) {
            throw ValidationException::withMessages([
                'parent_id' => ['Parent account must belong to the same company.'],
            ]);
        }

        if ($branchId !== null && (int) $parent->getRawOriginal('branch_id') !== $branchId) {
            throw ValidationException::withMessages([
                'parent_id' => ['Parent account must belong to the same branch.'],
            ]);
        }
    }

    public static function assertNotCircular(int $accountId, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        if ($parentId === $accountId) {
            throw ValidationException::withMessages([
                'parent_id' => ['An account cannot be its own parent.'],
            ]);
        }

        $walkerId = $parentId;

        for ($guard = 0; $guard < 50 && $walkerId !== null; $guard++) {
            if ($walkerId === $accountId) {
                throw ValidationException::withMessages([
                    'parent_id' => ['Invalid parent account selection would create a circular hierarchy.'],
                ]);
            }

            $walkerId = self::query()
                ->whereKey($walkerId)
                ->value('parent_id');
        }
    }

    public static function createSubAccount(
        object $request,
        string $code,
        string $name,
        int $parentId,
        self $parentAccount,
    ): self {
        return self::query()->create([
            'parent_id' => $parentId,
            'company_id' => $request->company_id,
            'branch_id' => $request->branch_id,
            'name' => $name,
            'code' => $code,
            'acc_type' => 't',
            'acc_nature' => $parentAccount->acc_nature,
            'active' => true,
            'pl' => $parentAccount->pl,
            'bs' => $parentAccount->bs,
        ]);
    }

    /**
     * @param  Collection<int, ChartOfAccount>  $roots
     */
    public static function appendOpeningBalancesToTree(Collection $roots, int $companyId, int $branchId): void
    {
        $allAccounts = self::flattenTreeAccounts($roots);

        foreach ($allAccounts as $account) {
            $account->opening_balance = 0;
        }

        $financialYear = FinancialYear::query()
            ->where('company_id', $companyId)
            ->where('status', true)
            ->orderByDesc('id')
            ->first();

        if ($financialYear !== null) {
            $transactionalIds = $allAccounts
                ->filter(fn (self $account): bool => $account->acc_type === 't')
                ->pluck('id');

            if ($transactionalIds->isNotEmpty()) {
                $balancesByCoaId = AccountBalance::query()
                    ->where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->where('financial_id', $financialYear->id)
                    ->whereIn('coa_id', $transactionalIds)
                    ->pluck('opening_balance', 'coa_id');

                foreach ($allAccounts as $account) {
                    if ($account->acc_type !== 't') {
                        continue;
                    }

                    $account->opening_balance = (float) ($balancesByCoaId->get($account->id) ?? 0);
                }
            }
        }

        self::rollupControlOpeningBalances($roots);
    }

    /**
     * @param  Collection<int, ChartOfAccount>  $roots
     * @return Collection<int, ChartOfAccount>
     */
    private static function flattenTreeAccounts(Collection $roots): Collection
    {
        $accounts = collect();

        $walk = function (Collection $nodes) use (&$walk, $accounts): void {
            foreach ($nodes as $node) {
                $accounts->push($node);

                if ($node->relationLoaded('children') && $node->children->isNotEmpty()) {
                    $walk($node->children);
                }
            }
        };

        $walk($roots);

        return $accounts;
    }

    /**
     * @param  Collection<int, ChartOfAccount>  $roots
     */
    private static function rollupControlOpeningBalances(Collection $roots): void
    {
        $rollup = function (Collection $nodes) use (&$rollup): void {
            foreach ($nodes as $node) {
                if ($node->relationLoaded('children') && $node->children->isNotEmpty()) {
                    $rollup($node->children);
                }

                if ($node->acc_type !== 'c') {
                    continue;
                }

                $node->opening_balance = $node->relationLoaded('children')
                    ? $node->children->sum(fn (self $child): float => (float) ($child->opening_balance ?? 0))
                    : 0;
            }
        };

        $rollup($roots);
    }
}
