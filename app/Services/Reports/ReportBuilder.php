<?php

namespace App\Services\Reports;

use App\Models\Transaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The custom report builder. A report is a definition (JSON) over one of a few whitelisted datasets: which of the dataset's columns to
 * show, filters on its filterable columns, an optional grouping by one column (numbers are then summed and rows counted) and a sort.
 *
 * Nothing the user sends ever reaches SQL as text: columns, operators and the dataset are looked up in the whitelist below and a name
 * that is not there is refused; values are bound parameters. The company is always applied from the signed-in user's scope (and the
 * branch for a branch-bound user), never from the definition, so a saved report run by another company reads that company's rows.
 * A result is capped at LIMIT rows.
 */
class ReportBuilder
{
    public const LIMIT = 5000;

    public const OPERATORS = ['=', '!=', '>', '>=', '<', '<=', 'like', 'between'];

    /**
     * @return array<string, array{label: string, columns: array<string, array{label: string, type: string, sql: string, filter: bool, group: bool}>, date: string}>
     */
    public function datasets(): array
    {
        $contact = "coalesce(nullif(c.business_name, ''), c.first_name)";
        $user = "coalesce(u.first_name, '')";

        $document = fn (string $label): array => [
            'transaction_date' => ['label' => 'Date', 'type' => 'date', 'sql' => 't.transaction_date', 'filter' => true, 'group' => false],
            'invoice_no' => ['label' => 'Invoice No', 'type' => 'text', 'sql' => 't.invoice_no', 'filter' => true, 'group' => false],
            'contact' => ['label' => $label, 'type' => 'text', 'sql' => $contact, 'filter' => true, 'group' => true],
            'branch' => ['label' => 'Branch', 'type' => 'text', 'sql' => 'b.name', 'filter' => true, 'group' => true],
            'created_by' => ['label' => 'Entered by', 'type' => 'text', 'sql' => $user, 'filter' => true, 'group' => true],
            'status' => ['label' => 'Status', 'type' => 'text', 'sql' => 't.status', 'filter' => true, 'group' => true],
            'payment_status' => ['label' => 'Payment status', 'type' => 'text', 'sql' => 't.payment_status', 'filter' => true, 'group' => true],
            'total_before_tax' => ['label' => 'Before tax', 'type' => 'number', 'sql' => 't.total_before_tax', 'filter' => true, 'group' => false],
            'tax_amount' => ['label' => 'Tax', 'type' => 'number', 'sql' => 't.tax_amount', 'filter' => true, 'group' => false],
            'discount_amount' => ['label' => 'Discount', 'type' => 'number', 'sql' => 't.discount_amount', 'filter' => true, 'group' => false],
            'final_amount' => ['label' => 'Total', 'type' => 'number', 'sql' => 't.final_amount', 'filter' => true, 'group' => false],
            'paid_amount' => ['label' => 'Paid', 'type' => 'number', 'sql' => 't.paid_amount', 'filter' => true, 'group' => false],
        ];

        return [
            'sales' => ['label' => 'Sales invoices', 'date' => 'transaction_date', 'columns' => $document('Customer')],
            'purchases' => ['label' => 'Purchases', 'date' => 'transaction_date', 'columns' => $document('Supplier')],
            'sale_lines' => ['label' => 'Sale lines', 'date' => 'transaction_date', 'columns' => [
                'transaction_date' => ['label' => 'Date', 'type' => 'date', 'sql' => 't.transaction_date', 'filter' => true, 'group' => false],
                'invoice_no' => ['label' => 'Invoice No', 'type' => 'text', 'sql' => 't.invoice_no', 'filter' => true, 'group' => false],
                'contact' => ['label' => 'Customer', 'type' => 'text', 'sql' => $contact, 'filter' => true, 'group' => true],
                'branch' => ['label' => 'Branch', 'type' => 'text', 'sql' => 'b.name', 'filter' => true, 'group' => true],
                'product' => ['label' => 'Product', 'type' => 'text', 'sql' => 'p.name', 'filter' => true, 'group' => true],
                'sku' => ['label' => 'SKU', 'type' => 'text', 'sql' => 'p.sku', 'filter' => true, 'group' => true],
                'category' => ['label' => 'Category', 'type' => 'text', 'sql' => 'cat.name', 'filter' => true, 'group' => true],
                'quantity' => ['label' => 'Quantity', 'type' => 'number', 'sql' => 'l.quantity', 'filter' => true, 'group' => false],
                'unit_price' => ['label' => 'Unit price', 'type' => 'number', 'sql' => 'l.unit_price', 'filter' => true, 'group' => false],
                'subtotal' => ['label' => 'Line total', 'type' => 'number', 'sql' => 'l.subtotal', 'filter' => true, 'group' => false],
            ]],
            'payments' => ['label' => 'Payments', 'date' => 'paid_on', 'columns' => [
                'paid_on' => ['label' => 'Paid on', 'type' => 'date', 'sql' => 'pm.paid_on', 'filter' => true, 'group' => false],
                'method' => ['label' => 'Method', 'type' => 'text', 'sql' => 'pm.method', 'filter' => true, 'group' => true],
                'contact' => ['label' => 'Party', 'type' => 'text', 'sql' => $contact, 'filter' => true, 'group' => true],
                'branch' => ['label' => 'Branch', 'type' => 'text', 'sql' => 'b.name', 'filter' => true, 'group' => true],
                'invoice_no' => ['label' => 'Invoice No', 'type' => 'text', 'sql' => 't.invoice_no', 'filter' => true, 'group' => false],
                'document_type' => ['label' => 'Document type', 'type' => 'text', 'sql' => 't.type', 'filter' => true, 'group' => true],
                'amount' => ['label' => 'Amount', 'type' => 'number', 'sql' => 'pm.amount', 'filter' => true, 'group' => false],
            ]],
            'contacts' => ['label' => 'Customers and suppliers', 'date' => '', 'columns' => [
                'name' => ['label' => 'Name', 'type' => 'text', 'sql' => $contact, 'filter' => true, 'group' => false],
                'user_type' => ['label' => 'Type', 'type' => 'text', 'sql' => 'c.user_type', 'filter' => true, 'group' => true],
                'mobile' => ['label' => 'Mobile', 'type' => 'text', 'sql' => 'c.mobile', 'filter' => true, 'group' => false],
                'email' => ['label' => 'Email', 'type' => 'text', 'sql' => 'c.email', 'filter' => true, 'group' => false],
                'branch' => ['label' => 'Branch', 'type' => 'text', 'sql' => 'b.name', 'filter' => true, 'group' => true],
                'credit_limit' => ['label' => 'Credit limit', 'type' => 'number', 'sql' => 'c.credit_limit', 'filter' => true, 'group' => false],
            ]],
            'products' => ['label' => 'Products', 'date' => '', 'columns' => [
                'name' => ['label' => 'Name', 'type' => 'text', 'sql' => 'p.name', 'filter' => true, 'group' => false],
                'sku' => ['label' => 'SKU', 'type' => 'text', 'sql' => 'p.sku', 'filter' => true, 'group' => false],
                'category' => ['label' => 'Category', 'type' => 'text', 'sql' => 'cat.name', 'filter' => true, 'group' => true],
                'brand' => ['label' => 'Brand', 'type' => 'text', 'sql' => 'br.name', 'filter' => true, 'group' => true],
                'type' => ['label' => 'Type', 'type' => 'text', 'sql' => 'p.type', 'filter' => true, 'group' => true],
                'alert_qty' => ['label' => 'Alert quantity', 'type' => 'number', 'sql' => 'p.alert_qty', 'filter' => true, 'group' => false],
            ]],
        ];
    }

    /**
     * What the screen needs to build a definition: datasets with their columns, operators and the row cap.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'datasets' => collect($this->datasets())->map(fn (array $d, string $key): array => [
                'key' => $key, 'label' => $d['label'], 'has_date' => $d['date'] !== '',
                'columns' => collect($d['columns'])->map(fn (array $c, string $name): array => ['key' => $name, 'label' => $c['label'], 'type' => $c['type'], 'filter' => $c['filter'], 'group' => $c['group']])->values()->all(),
            ])->values()->all(),
            'operators' => self::OPERATORS,
            'limit' => self::LIMIT,
        ];
    }

    /**
     * Checks a definition against the whitelist and returns it normalised.
     *
     * @param  array<string, mixed>  $definition
     * @return array{columns: list<string>, filters: list<array{column: string, operator: string, value: mixed}>, group_by: ?string, sort_by: ?string, sort_dir: string, from: ?string, to: ?string}
     *
     * @throws ValidationException
     */
    public function normalise(string $dataset, array $definition): array
    {
        $datasets = $this->datasets();

        if (! isset($datasets[$dataset])) {
            throw ValidationException::withMessages(['dataset' => ['Choose one of the available datasets.']]);
        }

        $columns = $datasets[$dataset]['columns'];
        $known = fn (string $name): bool => array_key_exists($name, $columns);

        $chosen = array_values(array_unique(array_map('strval', (array) ($definition['columns'] ?? []))));

        foreach ($chosen as $name) {
            if (! $known($name)) {
                throw ValidationException::withMessages(['columns' => ["Column [{$name}] is not available in this dataset."]]);
            }
        }

        $group = ($definition['group_by'] ?? null) !== null && $definition['group_by'] !== '' ? (string) $definition['group_by'] : null;

        if ($group !== null && (! $known($group) || ! $columns[$group]['group'])) {
            throw ValidationException::withMessages(['group_by' => ['Group by one of the columns marked as groupable.']]);
        }

        if ($chosen === [] && $group === null) {
            throw ValidationException::withMessages(['columns' => ['Choose at least one column.']]);
        }

        $filters = [];

        foreach ((array) ($definition['filters'] ?? []) as $filter) {
            $column = (string) ($filter['column'] ?? '');
            $operator = (string) ($filter['operator'] ?? '=');

            if (! $known($column) || ! $columns[$column]['filter']) {
                throw ValidationException::withMessages(['filters' => ["Cannot filter on [{$column}]."]]);
            }

            if (! in_array($operator, self::OPERATORS, true)) {
                throw ValidationException::withMessages(['filters' => ["Operator [{$operator}] is not allowed."]]);
            }

            $value = $filter['value'] ?? null;

            if ($operator === 'between' && (! is_array($value) || count($value) !== 2)) {
                throw ValidationException::withMessages(['filters' => ['Between needs two values.']]);
            }

            if ($operator !== 'between' && ! is_scalar($value)) {
                throw ValidationException::withMessages(['filters' => ['A filter value must be a single value.']]);
            }

            $filters[] = ['column' => $column, 'operator' => $operator, 'value' => $value];
        }

        $sort = ($definition['sort_by'] ?? null) !== null && $definition['sort_by'] !== '' ? (string) $definition['sort_by'] : null;

        if ($sort !== null && ! $known($sort) && $sort !== 'row_count') {
            throw ValidationException::withMessages(['sort_by' => ['Sort by one of the chosen columns.']]);
        }

        return [
            'columns' => $chosen, 'filters' => $filters, 'group_by' => $group, 'sort_by' => $sort,
            'sort_dir' => ($definition['sort_dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
            'from' => $this->date($definition['from'] ?? null), 'to' => $this->date($definition['to'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{columns: list<array{key: string, label: string, type: string}>, rows: Collection<int, array<string, mixed>>, truncated: bool}
     */
    public function run(string $dataset, array $definition, ?int $companyId, ?int $branchId): array
    {
        $definition = $this->normalise($dataset, $definition);
        $meta = $this->datasets()[$dataset];
        $columns = $meta['columns'];
        $query = $this->base($dataset, $companyId, $branchId);

        if ($meta['date'] !== '') {
            $dateSql = $columns[$meta['date']]['sql'];
            $definition['from'] !== null && $query->whereDate(DB::raw($dateSql), '>=', $definition['from']);
            $definition['to'] !== null && $query->whereDate(DB::raw($dateSql), '<=', $definition['to']);
        }

        foreach ($definition['filters'] as $filter) {
            $sql = $columns[$filter['column']]['sql'];

            match ($filter['operator']) {
                'between' => $query->whereBetween(DB::raw($sql), [$filter['value'][0], $filter['value'][1]]),
                'like' => $query->where(DB::raw($sql), 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filter['value']).'%'),
                default => $query->where(DB::raw($sql), $filter['operator'], $filter['value']),
            };
        }

        $output = [];

        if ($definition['group_by'] !== null) {
            $group = $definition['group_by'];
            $query->selectRaw($columns[$group]['sql'].' as grp')->selectRaw('count(*) as row_count')->groupBy(DB::raw($columns[$group]['sql']));
            $output[] = ['key' => $group, 'label' => $columns[$group]['label'], 'type' => $columns[$group]['type']];

            foreach ($definition['columns'] as $name) {
                if ($columns[$name]['type'] === 'number') {
                    $query->selectRaw('coalesce(sum('.$columns[$name]['sql'].'), 0) as agg_'.$name);
                    $output[] = ['key' => $name, 'label' => 'Total '.strtolower($columns[$name]['label']), 'type' => 'number'];
                }
            }

            $output[] = ['key' => 'row_count', 'label' => 'Rows', 'type' => 'number'];
            $sortable = array_column($output, 'key');
            $sort = in_array($definition['sort_by'], $sortable, true) ? $definition['sort_by'] : $group;
            $query->orderBy($sort === $group ? 'grp' : ($sort === 'row_count' ? 'row_count' : 'agg_'.$sort), $definition['sort_dir']);
        } else {
            foreach ($definition['columns'] as $name) {
                $query->selectRaw($columns[$name]['sql'].' as c_'.$name);
                $output[] = ['key' => $name, 'label' => $columns[$name]['label'], 'type' => $columns[$name]['type']];
            }

            $sort = $definition['sort_by'] ?? ($meta['date'] !== '' && in_array($meta['date'], $definition['columns'], true) ? $meta['date'] : null);

            if ($sort !== null && in_array($sort, $definition['columns'], true)) {
                $query->orderBy('c_'.$sort, $definition['sort_dir']);
            }
        }

        $fetched = $query->limit(self::LIMIT + 1)->get();
        $truncated = $fetched->count() > self::LIMIT;
        $isGrouped = $definition['group_by'] !== null;

        $rows = $fetched->take(self::LIMIT)->values()->map(function (object $row, int $index) use ($output, $isGrouped, $definition): array {
            $data = ['id' => $index + 1];

            foreach ($output as $column) {
                $key = $column['key'];
                $raw = match (true) {
                    $isGrouped && $key === $definition['group_by'] => $row->grp,
                    $isGrouped && $key === 'row_count' => $row->row_count,
                    $isGrouped => $row->{'agg_'.$key},
                    default => $row->{'c_'.$key},
                };
                $data[$key] = $column['type'] === 'number' && $raw !== null ? round((float) $raw, 2) : $raw;
            }

            return $data;
        });

        return ['columns' => $output, 'rows' => $rows, 'truncated' => $truncated];
    }

    private function base(string $dataset, ?int $companyId, ?int $branchId): Builder
    {
        $scope = function (Builder $query, string $company, ?string $branch) use ($companyId, $branchId): Builder {
            return $query
                ->when($companyId !== null, fn (Builder $q) => $q->where($company, $companyId))
                ->when($branchId !== null && $branch !== null, fn (Builder $q) => $q->where($branch, $branchId));
        };

        return match ($dataset) {
            'sales', 'purchases' => $scope(
                DB::table('transactions as t')
                    ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')->leftJoin('branches as b', 'b.id', '=', 't.branch_id')->leftJoin('users as u', 'u.id', '=', 't.created_by')
                    ->where('t.type', $dataset === 'sales' ? Transaction::TYPE_SELL : Transaction::TYPE_PURCHASE)->whereNull('t.deleted_at')
                    ->when($dataset === 'sales', fn (Builder $q) => $q->whereNotIn('t.status', Transaction::UNPOSTED_SELL_STATUSES)),
                't.company_id', 't.branch_id'),
            'sale_lines' => $scope(
                DB::table('sell_lines as l')->join('transactions as t', 't.id', '=', 'l.transaction_id')
                    ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')->leftJoin('branches as b', 'b.id', '=', 't.branch_id')
                    ->leftJoin('products as p', 'p.id', '=', 'l.product_id')->leftJoin('categories as cat', 'cat.id', '=', 'p.category_id')
                    ->where('t.type', Transaction::TYPE_SELL)->whereNull('t.deleted_at')->whereNotIn('t.status', Transaction::UNPOSTED_SELL_STATUSES),
                't.company_id', 't.branch_id'),
            'payments' => $scope(
                DB::table('payments as pm')->join('transactions as t', 't.id', '=', 'pm.transaction_id')
                    ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')->leftJoin('branches as b', 'b.id', '=', 't.branch_id')
                    ->whereNull('t.deleted_at'),
                't.company_id', 't.branch_id'),
            'contacts' => $scope(
                DB::table('contacts as c')->leftJoin('branches as b', 'b.id', '=', 'c.branch_id')->whereNull('c.deleted_at'),
                'c.company_id', 'c.branch_id'),
            'products' => $scope(
                DB::table('products as p')->leftJoin('categories as cat', 'cat.id', '=', 'p.category_id')->leftJoin('brands as br', 'br.id', '=', 'p.brand_id')->whereNull('p.deleted_at'),
                'p.company_id', null),
            default => throw ValidationException::withMessages(['dataset' => ['Unknown dataset.']]),
        };
    }

    private function date(mixed $value): ?string
    {
        $text = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1 ? $text : null;
    }
}
