<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Reads a bank statement file into the rows the reconciliation takes: OFX / QFX (SGML or XML), SWIFT MT940 and a plain CSV.
 * Money in is positive, money out negative. Nothing is saved here; the caller shows the result and the user confirms it.
 *
 * @phpstan-type Row array{txn_date: string, amount: float, description: ?string, reference: ?string}
 * @phpstan-type Parsed array{format: string, account: ?string, from: ?string, to: ?string, opening_balance: ?float, closing_balance: ?float, rows: list<Row>}
 */
class BankStatementParser
{
    public const FORMATS = ['ofx', 'mt940', 'csv'];

    /**
     * @return Parsed
     *
     * @throws ValidationException
     */
    public function parse(string $format, string $content): array
    {
        $content = trim(str_replace("\xEF\xBB\xBF", '', $content));

        if ($content === '') {
            throw ValidationException::withMessages(['content' => ['The statement is empty.']]);
        }

        $result = match ($format) {
            'ofx' => $this->ofx($content),
            'mt940' => $this->mt940($content),
            'csv' => $this->csv($content),
            default => throw ValidationException::withMessages(['format' => ['Choose OFX, MT940 or CSV.']]),
        };

        if ($result['rows'] === []) {
            throw ValidationException::withMessages(['content' => ['No transactions were found in this '.strtoupper($format).' statement.']]);
        }

        $dates = array_column($result['rows'], 'txn_date');
        $result['from'] ??= min($dates);
        $result['to'] ??= max($dates);

        return $result;
    }

    /**
     * @return Parsed
     */
    private function ofx(string $content): array
    {
        $tag = fn (string $name, string $source): ?string => preg_match('/<'.$name.'>\s*([^<\r\n]+)/i', $source, $m) === 1 ? trim($m[1]) : null;
        $rows = [];

        preg_match_all('/<STMTTRN>(.*?)(?:<\/STMTTRN>|(?=<STMTTRN>)|<\/BANKTRANLIST>)/is', $content, $blocks);

        foreach ($blocks[1] as $block) {
            $date = $tag('DTPOSTED', $block);
            $amount = $tag('TRNAMT', $block);

            if ($date === null || $amount === null) {
                continue;
            }

            $rows[] = [
                'txn_date' => $this->ofxDate($date),
                'amount' => round((float) str_replace(',', '.', $amount), 2),
                'description' => trim(($tag('NAME', $block) ?? '').' '.($tag('MEMO', $block) ?? '')) ?: null,
                'reference' => $tag('CHECKNUM', $block) ?? $tag('FITID', $block),
            ];
        }

        $ledgerBlock = preg_match('/<LEDGERBAL>(.*?)(?:<\/LEDGERBAL>|$)/is', $content, $m) === 1 ? $m[1] : '';
        $closing = $tag('BALAMT', $ledgerBlock);
        $closing = $closing === null ? null : round((float) $closing, 2);
        $start = $tag('DTSTART', $content);
        $end = $tag('DTEND', $content);

        return [
            'format' => 'ofx',
            'account' => $tag('ACCTID', $content),
            'from' => $start === null ? null : $this->ofxDate($start),
            'to' => $end === null ? null : $this->ofxDate($end),
            'opening_balance' => $closing === null ? null : round($closing - array_sum(array_column($rows, 'amount')), 2),
            'closing_balance' => $closing,
            'rows' => $rows,
        ];
    }

    private function ofxDate(string $value): string
    {
        $digits = preg_replace('/\D/', '', substr($value, 0, 8)) ?? '';

        if (strlen($digits) !== 8 || ! checkdate((int) substr($digits, 4, 2), (int) substr($digits, 6, 2), (int) substr($digits, 0, 4))) {
            throw ValidationException::withMessages(['content' => ["The date \"{$value}\" in the OFX file is not a date."]]);
        }

        return substr($digits, 0, 4).'-'.substr($digits, 4, 2).'-'.substr($digits, 6, 2);
    }

    /**
     * @return Parsed
     */
    private function mt940(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $fields = [];

        // a field starts with ":NN:" or ":NNa:"; following lines that do not are its continuation
        foreach ($lines as $line) {
            if (preg_match('/^:(\d{2}[A-Z]?):(.*)$/', $line, $m) === 1) {
                $fields[] = [$m[1], $m[2]];
            } elseif ($fields !== [] && trim($line) !== '' && ! str_starts_with($line, '-') && ! str_starts_with($line, '{')) {
                $fields[count($fields) - 1][1] .= "\n".$line;
            }
        }

        $rows = [];
        $account = null;
        $opening = null;
        $closing = null;
        $openingDate = null;
        $closingDate = null;

        foreach ($fields as $index => [$code, $value]) {
            if ($code === '25') {
                $account = trim($value);
            } elseif (in_array($code, ['60F', '60M'], true)) {
                [$opening, $openingDate] = $this->mt940Balance($value);
            } elseif (in_array($code, ['62F', '62M'], true)) {
                [$closing, $closingDate] = $this->mt940Balance($value);
            } elseif ($code === '61') {
                $row = $this->mt940Line($value);

                $next = $fields[$index + 1] ?? null;
                $details = $next !== null && $next[0] === '86' ? trim(preg_replace('/\s+/', ' ', $next[1]) ?? '') : '';
                $row['description'] = $details !== '' ? $details : $row['description'];
                $rows[] = $row;
            }
        }

        return [
            'format' => 'mt940',
            'account' => $account,
            'from' => $openingDate,
            'to' => $closingDate,
            'opening_balance' => $opening,
            'closing_balance' => $closing,
            'rows' => $rows,
        ];
    }

    /**
     * ":60F:C240101EUR1234,56" -> [1234.56, "2024-01-01"]
     *
     * @return array{0: float, 1: string}
     */
    private function mt940Balance(string $value): array
    {
        if (preg_match('/^([CD])(\d{6})([A-Z]{3})([\d,\.]+)/', trim($value), $m) !== 1) {
            throw ValidationException::withMessages(['content' => ['A balance line of the MT940 file could not be read.']]);
        }

        $amount = round((float) str_replace(',', '.', $m[4]), 2);

        return [$m[1] === 'D' ? -$amount : $amount, $this->mt940Date($m[2])];
    }

    /**
     * ":61:2401020102D123,45NTRFNONREF//BANKREF" -> a row
     *
     * @return array{txn_date: string, amount: float, description: ?string, reference: ?string}
     */
    private function mt940Line(string $value): array
    {
        $first = trim(explode("\n", $value)[0]);

        if (preg_match('/^(\d{6})(\d{4})?(R?[CD])([A-Z])?([\d,\.]+)([A-Z0-9]{4})([^\/]*?)(?:\/\/(.*))?$/', $first, $m) !== 1) {
            throw ValidationException::withMessages(['content' => ['A transaction line of the MT940 file could not be read: '.$first]]);
        }

        $amount = round((float) str_replace(',', '.', $m[5]), 2);
        $debit = str_ends_with($m[3], 'D');
        $reference = trim($m[7]) !== '' && trim($m[7]) !== 'NONREF' ? trim($m[7]) : (trim($m[8] ?? '') ?: null);

        return [
            'txn_date' => $this->mt940Date($m[1]),
            'amount' => $debit ? -$amount : $amount,
            'description' => $reference,
            'reference' => $reference,
        ];
    }

    private function mt940Date(string $yymmdd): string
    {
        $year = (int) substr($yymmdd, 0, 2);
        $year += $year >= 70 ? 1900 : 2000;

        if (! checkdate((int) substr($yymmdd, 2, 2), (int) substr($yymmdd, 4, 2), $year)) {
            throw ValidationException::withMessages(['content' => ["The date \"{$yymmdd}\" in the MT940 file is not a date."]]);
        }

        return sprintf('%04d-%s-%s', $year, substr($yymmdd, 2, 2), substr($yymmdd, 4, 2));
    }

    /**
     * Header row required: date, amount (or debit and credit), description / reference optional.
     *
     * @return Parsed
     */
    private function csv(string $content): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $content) ?: []), fn (string $line): bool => $line !== ''));
        $delimiter = substr_count($lines[0] ?? '', ';') > substr_count($lines[0] ?? '', ',') ? ';' : ',';
        $header = array_map(fn (?string $cell): string => strtolower(trim((string) $cell)), str_getcsv($lines[0] ?? '', $delimiter, '"', '\\'));
        $find = function (array $names) use ($header): ?int {
            foreach ($names as $name) {
                $index = array_search($name, $header, true);

                if ($index !== false) {
                    return (int) $index;
                }
            }

            return null;
        };

        $date = $find(['date', 'txn_date', 'transaction date', 'value date']);
        $amount = $find(['amount']);
        $debit = $find(['debit', 'withdrawal', 'paid out']);
        $credit = $find(['credit', 'deposit', 'paid in']);
        $description = $find(['description', 'narration', 'details', 'memo']);
        $reference = $find(['reference', 'ref', 'cheque', 'cheque no']);

        if ($date === null || ($amount === null && $debit === null && $credit === null)) {
            throw ValidationException::withMessages(['content' => ['The CSV needs a header row with a date column and an amount (or debit and credit) column.']]);
        }

        $number = fn (?string $cell): float => (float) str_replace([',', ' '], '', (string) $cell);
        $rows = [];

        foreach (array_slice($lines, 1) as $line) {
            $cells = str_getcsv($line, $delimiter, '"', '\\');
            $day = strtotime((string) ($cells[$date] ?? ''));

            if ($day === false) {
                continue;
            }

            $value = $amount !== null
                ? $number($cells[$amount] ?? null)
                : ($credit === null ? 0.0 : $number($cells[$credit] ?? null)) - ($debit === null ? 0.0 : $number($cells[$debit] ?? null));

            $rows[] = [
                'txn_date' => date('Y-m-d', $day),
                'amount' => round($value, 2),
                'description' => $description === null ? null : (trim((string) ($cells[$description] ?? '')) ?: null),
                'reference' => $reference === null ? null : (trim((string) ($cells[$reference] ?? '')) ?: null),
            ];
        }

        return ['format' => 'csv', 'account' => null, 'from' => null, 'to' => null, 'opening_balance' => null, 'closing_balance' => null, 'rows' => $rows];
    }
}
