<?php

namespace App\Services\System;

use App\Services\Documents\XlsxWriter;
use Illuminate\Support\Facades\DB;

/**
 * The export side of the import / export center and the templates of the import side. Exports are company scoped (and branch scoped
 * for a branch-bound user) lists of customers, suppliers, products and the chart of accounts, as rows ready for CSV or Excel. The
 * import templates are the exact column names the customer and supplier importer (ContactImporter) reads.
 */
class DataTransfer
{
    public const EXPORTS = ['customers', 'suppliers', 'products', 'chart_of_accounts'];

    public const TEMPLATES = [
        'customers' => ['business_name', 'first_name', 'last_name', 'mobile', 'email', 'address', 'ntn_number', 'strn_number', 'code', 'opening_balance', 'credit_limit', 'pay_term', 'pay_type', 'type'],
        'suppliers' => ['business_name', 'first_name', 'last_name', 'mobile', 'email', 'address', 'ntn_number', 'strn_number', 'code', 'opening_balance', 'credit_limit', 'pay_term', 'pay_type', 'type'],
    ];

    /**
     * @return list<list<scalar|null>> the header row first
     */
    public function export(string $entity, int $companyId, ?int $branchId): array
    {
        return match ($entity) {
            'customers', 'suppliers' => $this->contacts($entity === 'customers' ? 'customer' : 'supplier', $companyId, $branchId),
            'products' => $this->products($companyId),
            'chart_of_accounts' => $this->accounts($companyId, $branchId),
            default => abort(404),
        };
    }

    /**
     * @param  list<list<scalar|null>>  $rows
     */
    public function csv(array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");

        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn ($cell): string => $this->neutralise($cell), $row));
        }

        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fclose($stream);

        return $content;
    }

    /**
     * @param  list<list<scalar|null>>  $rows
     */
    public function xlsx(array $rows, string $sheet): string
    {
        return XlsxWriter::build(array_map(fn (array $row): array => array_map(fn ($cell) => is_string($cell) ? $this->neutralise($cell) : $cell, $row), $rows), $sheet);
    }

    private function neutralise(mixed $cell): string
    {
        $text = is_scalar($cell) || $cell === null ? (string) $cell : '';

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@'], true) && ! is_numeric($text) ? "'".$text : $text;
    }

    /**
     * @return list<list<scalar|null>>
     */
    private function contacts(string $type, int $companyId, ?int $branchId): array
    {
        $rows = [['Code', 'Business name', 'First name', 'Last name', 'Mobile', 'Email', 'Address', 'NTN', 'STRN', 'Credit limit', 'Pay term', 'Pay type', 'Type']];

        DB::table('contacts')->where('company_id', $companyId)->where('user_type', $type)->whereNull('deleted_at')
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))->orderBy('id')
            ->get(['code', 'business_name', 'first_name', 'last_name', 'mobile', 'email', 'address', 'ntn_number', 'strn_number', 'credit_limit', 'pay_term', 'pay_type', 'type'])
            ->each(function (object $row) use (&$rows): void {
                $rows[] = [$row->code, $row->business_name, $row->first_name, $row->last_name, $row->mobile, $row->email, $row->address, $row->ntn_number, $row->strn_number, (float) $row->credit_limit, $row->pay_term, $row->pay_type, $row->type];
            });

        return $rows;
    }

    /**
     * @return list<list<scalar|null>>
     */
    private function products(int $companyId): array
    {
        $rows = [['SKU', 'Name', 'Type', 'Category', 'Brand', 'Unit', 'Alert quantity', 'Active']];

        DB::table('products as p')->leftJoin('categories as c', 'c.id', '=', 'p.category_id')->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
            ->where('p.company_id', $companyId)->whereNull('p.deleted_at')->orderBy('p.id')
            ->get(['p.sku', 'p.name', 'p.type', 'c.name as category', 'b.name as brand', 'u.short_name as unit', 'p.alert_qty', 'p.active'])
            ->each(function (object $row) use (&$rows): void {
                $rows[] = [$row->sku, $row->name, $row->type, $row->category, $row->brand, $row->unit, (float) $row->alert_qty, $row->active ? 'Yes' : 'No'];
            });

        return $rows;
    }

    /**
     * @return list<list<scalar|null>>
     */
    private function accounts(int $companyId, ?int $branchId): array
    {
        $rows = [['Code', 'Name', 'Type', 'Nature', 'Active']];

        DB::table('chart_of_accounts')->where('company_id', $companyId)->whereNull('deleted_at')
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))->orderBy('code')->get(['code', 'name', 'acc_type', 'acc_nature', 'active'])
            ->each(function (object $row) use (&$rows): void {
                $rows[] = [$row->code, $row->name, $row->acc_type, $row->acc_nature, $row->active ? 'Yes' : 'No'];
            });

        return $rows;
    }
}
