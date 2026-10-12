<?php

namespace App\Services\System;

use App\Services\ContactImporter;

/**
 * Moving contacts in from another system: a CSV is mapped to the rows the contact importer (ContactImporter) takes, then checked
 * (dry run) or committed through that same importer, so every rule of the add form (numbering, duplicates, account link, opening
 * balance) applies. Three sources:
 *
 *  - template: this application's own column names (the downloadable template);
 *  - tally: a Tally ledger export with Name / Parent / Opening Balance (Sundry Debtors become customers, Sundry Creditors suppliers;
 *    "1,500.00 Dr" and "Cr" suffixes are read; other groups are skipped and counted);
 *  - quickbooks: a QuickBooks customer or vendor contact list (Customer / Vendor, Company, First Name, Last Name, Phone, Email,
 *    Bill to, Balance).
 *
 * The Tally and QuickBooks layouts depend on the version and on the report the user exported, so those two mappers match header
 * names case-insensitively and anything unmatched is reported; they are not proven against files from every version.
 */
class DataMigration
{
    public const SOURCES = ['template', 'tally', 'quickbooks'];

    public const MAX_ROWS = 1000;

    public function __construct(private readonly ContactImporter $importer) {}

    /**
     * Parses CSV text into rows keyed by header.
     *
     * @return array{headers: list<string>, rows: list<array<string, string>>}
     */
    public function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = [];
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }

            $lines[] = array_map(fn ($cell): string => trim((string) $cell), $row);
        }

        fclose($stream);

        if ($lines === []) {
            return ['headers' => [], 'rows' => []];
        }

        $headers = array_shift($lines);
        $rows = [];

        foreach ($lines as $line) {
            $rows[] = array_combine($headers, array_pad(array_slice($line, 0, count($headers)), count($headers), ''));
        }

        return ['headers' => $headers, 'rows' => array_slice($rows, 0, self::MAX_ROWS)];
    }

    /**
     * Maps the parsed rows of a source to importer rows, grouped by contact kind.
     *
     * @param  array{headers: list<string>, rows: list<array<string, string>>}  $parsed
     * @return array{customers: list<array<string, mixed>>, suppliers: list<array<string, mixed>>, skipped: int, unmatched: list<string>}
     */
    public function map(string $source, array $parsed, string $kind = 'customer'): array
    {
        $lookup = [];

        foreach ($parsed['headers'] as $header) {
            $lookup[strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $header) ?? $header)] = $header;
        }

        $find = function (array $names) use ($lookup): ?string {
            foreach ($names as $name) {
                if (isset($lookup[$name])) {
                    return $lookup[$name];
                }
            }

            return null;
        };

        $result = ['customers' => [], 'suppliers' => [], 'skipped' => 0, 'unmatched' => []];

        if ($source === 'template') {
            $known = DataTransfer::TEMPLATES['customers'];
            $result['unmatched'] = array_values(array_filter($parsed['headers'], fn (string $h): bool => ! in_array($h, $known, true)));

            foreach ($parsed['rows'] as $row) {
                $result[$kind === 'supplier' ? 'suppliers' : 'customers'][] = array_intersect_key($row, array_flip($known));
            }

            return $result;
        }

        $columns = $source === 'tally'
            ? ['name' => $find(['name', 'ledger name', 'ledger', 'particulars']), 'parent' => $find(['parent', 'under', 'group']), 'balance' => $find(['opening balance', 'opening', 'closing balance', 'balance']),
                'address' => $find(['address', 'mailing address']), 'phone' => $find(['phone', 'phone no', 'mobile', 'telephone']), 'email' => $find(['email', 'e mail']), 'ntn' => $find(['ntn', 'gstin uin', 'gstin', 'pan it no', 'tax id'])]
            : ['name' => $find(['customer', 'vendor', 'company', 'name', 'customer name', 'vendor name']), 'first' => $find(['first name']), 'last' => $find(['last name']),
                'phone' => $find(['main phone', 'phone', 'mobile', 'phone numbers']), 'email' => $find(['main email', 'email']), 'address' => $find(['bill to 1', 'billing address', 'bill to', 'address']),
                'balance' => $find(['balance total', 'balance', 'open balance']), 'ntn' => $find(['tax id', 'resale num', 'ntn'])];

        $mapped = array_filter(array_values($columns));
        $result['unmatched'] = array_values(array_diff($parsed['headers'], $mapped));

        foreach ($parsed['rows'] as $row) {
            $get = fn (string $key): string => isset($columns[$key]) && $columns[$key] !== null ? (string) ($row[$columns[$key]] ?? '') : '';
            $name = $get('name');

            if ($name === '') {
                $result['skipped']++;

                continue;
            }

            $balanceText = $get('balance');
            $contactKind = $kind;

            if ($source === 'tally') {
                $parent = strtolower($get('parent'));
                $contactKind = str_contains($parent, 'debtor') ? 'customer' : (str_contains($parent, 'creditor') ? 'supplier' : null);

                if ($contactKind === null) {
                    $result['skipped']++;

                    continue;
                }
            }

            $entry = [
                'business_name' => $name, 'first_name' => $get('first') ?: $name, 'last_name' => $get('last'), 'mobile' => $get('phone') ?: '0000000000', 'email' => $get('email'),
                'address' => $get('address'), 'ntn_number' => $get('ntn'), 'opening_balance' => $this->balance($balanceText, $contactKind === 'supplier'),
            ];

            $result[$contactKind === 'supplier' ? 'suppliers' : 'customers'][] = $entry;
        }

        return $result;
    }

    /**
     * A balance as the importer wants it: positive is what the customer owes us / what we owe the supplier. "1,500 Dr" / "Cr"
     * suffixes (Tally) and a leading minus are read; a debit balance on a supplier or a credit balance on a customer is negative.
     */
    public function balance(string $text, bool $isSupplier): float
    {
        $text = trim($text);

        if ($text === '') {
            return 0.0;
        }

        $credit = (bool) preg_match('/\bcr\b/i', $text);
        $debit = (bool) preg_match('/\bdr\b/i', $text);
        $number = (float) preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $text));
        $amount = abs($number);

        if ($number < 0 && ! $credit && ! $debit) {
            return $isSupplier ? -$amount : -$amount;
        }

        if ($credit) {
            return $isSupplier ? $amount : -$amount;
        }

        if ($debit) {
            return $isSupplier ? -$amount : $amount;
        }

        return round($number, 2);
    }

    /**
     * Checks or commits mapped rows through the importer.
     *
     * @param  array{customers: list<array<string, mixed>>, suppliers: list<array<string, mixed>>, skipped: int, unmatched: list<string>}  $mapped
     * @return array{customers: array<string, mixed>|null, suppliers: array<string, mixed>|null}
     */
    public function run(array $mapped, int $companyId, int $branchId, bool $dryRun): array
    {
        $out = ['customers' => null, 'suppliers' => null];

        foreach (['customers' => 'customer', 'suppliers' => 'supplier'] as $key => $kind) {
            if ($mapped[$key] !== []) {
                $out[$key] = $this->importer->run($kind, $mapped[$key], $companyId, $branchId, $dryRun, false);
            }
        }

        return $out;
    }
}
