<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Contact;
use App\Models\FinancialYear;
use App\Services\Documents\PdfWriter;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The account statement of a customer or supplier for a period, built from the same figures as the ledger screen (ContactLedger), and
 * its PDF. A customer's positive balance is what they owe us, a supplier's is what we owe them; the statement says so in words.
 */
class ContactStatement
{
    public function __construct(private readonly ContactLedger $ledger) {}

    /**
     * The period as the ledger screen reads it: the dates asked for, else the active financial year, never outside it.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    public function range(Contact $contact, ?string $start, ?string $end): array
    {
        $year = FinancialYear::query()->where('company_id', $contact->company_id)->where('status', true)->orderByDesc('id')->first();
        $parse = fn (?string $value, CarbonInterface $fallback): CarbonInterface => trim((string) $value) === '' ? $fallback->copy() : Carbon::parse(str_replace('/', '-', (string) $value));

        $from = $parse($start, $year?->start_date?->copy() ?? now()->startOfMonth());
        $to = $parse($end, $year?->end_date?->copy() ?? now());

        if ($year?->start_date !== null && $year->end_date !== null) {
            $yearStart = $year->start_date->copy()->startOfDay();
            $yearEnd = $year->end_date->copy()->startOfDay();

            $from = $from->lt($yearStart) ? $yearStart->copy() : $from;
            $to = $to->gt($yearEnd) ? $yearEnd->copy() : $to;

            if ($from->gt($to)) {
                return [$yearStart->copy(), $yearEnd->copy()];
            }
        }

        return [$from, $to];
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Contact $contact, CarbonInterface $from, CarbonInterface $to, ?string $branchId = null): array
    {
        // the same call as the ledger screen, so the statement shows the figures the screen shows
        $ledger = $this->ledger->forContact($contact, $from, $to, $branchId);
        $company = Company::query()->with('companySetting')->find((int) $contact->company_id);
        $balance = (float) $ledger['openingbalance'];
        $debit = 0.0;
        $credit = 0.0;
        $rows = [];

        foreach ($ledger['taccount'] as $row) {
            $d = round((float) ($row['debit'] ?? 0), 2);
            $c = round((float) ($row['credit'] ?? 0), 2);
            $balance = ($row['acc_nature'] ?? ($contact->user_type === 'customer' ? 'dr' : 'cr')) === 'cr' ? $balance + $c - $d : $balance + $d - $c;
            $debit += $d;
            $credit += $c;
            $rows[] = [
                'date' => substr((string) ($row['voucher_date'] ?? ''), 0, 10), 'voucher' => (string) ($row['voucher_no'] ?? ''), 'reference' => (string) ($row['ref_no'] ?? ''),
                'narration' => (string) ($row['description'] ?? ''), 'debit' => $d, 'credit' => $c, 'balance' => round($balance, 2),
            ];
        }

        $isCustomer = $contact->user_type === 'customer';

        return [
            'company' => [
                'name' => (string) ($company?->companySetting?->business_name ?: $company?->name),
                'address' => (string) ($company?->companySetting?->address ?: $company?->address),
                'phone' => (string) ($company?->phone ?: $company?->cell),
                'email' => (string) $company?->email,
                'ntn' => (string) $company?->ntn_no,
            ],
            'party' => [
                'name' => trim((string) ($contact->business_name ?: trim($contact->first_name.' '.$contact->last_name))), 'code' => (string) $contact->code,
                'address' => (string) $contact->address, 'phone' => (string) $contact->mobile, 'email' => (string) $contact->email,
                'kind' => $isCustomer ? 'Customer' : 'Supplier',
            ],
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'opening' => round((float) $ledger['openingbalance'], 2), 'debit' => round($debit, 2), 'credit' => round($credit, 2), 'closing' => round($balance, 2),
            'closing_label' => $isCustomer
                ? ($balance >= 0 ? 'Balance due from you' : 'Balance in your favour')
                : ($balance >= 0 ? 'Balance payable to you' : 'Balance receivable from you'),
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $statement  from {@see self::build()}
     */
    public function pdf(array $statement): string
    {
        $pdf = new PdfWriter;
        $left = 36.0;
        $right = $pdf->pageWidth() - 36.0;
        $money = fn (float $value): string => number_format($value, 2);

        // column right edges: date | voucher | reference | narration | debit | credit | balance
        $columns = ['date' => [$left, 58], 'voucher' => [$left + 58, 68], 'reference' => [$left + 126, 68], 'narration' => [$left + 194, 135]];
        $debitRight = $left + 329 + 62;
        $creditRight = $debitRight + 62;
        $balanceRight = $right;

        $header = function (PdfWriter $pdf, bool $first) use ($statement, $left, $right, $columns, $debitRight, $creditRight, $balanceRight): float {
            $pdf->addPage();
            $y = 48.0;

            if ($first) {
                $pdf->text($left, $y, $pdf->fit((string) $statement['company']['name'], 330, 15, true), 15, true);
                $y += 14;

                foreach (array_filter([$statement['company']['address'], trim(($statement['company']['phone'] !== '' ? 'Phone: '.$statement['company']['phone'].'  ' : '').($statement['company']['ntn'] !== '' ? 'NTN: '.$statement['company']['ntn'] : ''))]) as $line) {
                    $pdf->text($left, $y, $pdf->fit((string) $line, 330, 8.5), 8.5, false, 0.35);
                    $y += 11;
                }

                $pdf->text($right - $pdf->width('Account statement', 15, true), 48, 'Account statement', 15, true);
                $pdf->textRight($right, 62, 'From '.$statement['from'].' to '.$statement['to'], 9, false, 0.3);
                $y = max($y, 76) + 8;
                $pdf->line($left, $y, $right, $y, 0.8, 0.2);
                $y += 16;
                $pdf->text($left, $y, $statement['party']['kind'].':', 9, false, 0.4);
                $pdf->text($left + 62, $y, $pdf->fit((string) $statement['party']['name'], 300, 11, true), 11, true);
                $y += 13;

                foreach (array_filter([$statement['party']['address'], trim(($statement['party']['phone'] !== '' ? 'Phone: '.$statement['party']['phone'].'  ' : '').($statement['party']['code'] !== '' ? 'Code: '.$statement['party']['code'] : ''))]) as $line) {
                    $pdf->text($left + 62, $y, $pdf->fit((string) $line, 330, 8.5), 8.5, false, 0.35);
                    $y += 11;
                }

                $y += 8;
            } else {
                $pdf->text($left, $y, $pdf->fit((string) $statement['party']['name'], 300, 10, true).' - account statement (continued)', 10, true);
                $y += 16;
            }

            $pdf->fillRect($left, $y - 10, $right - $left, 16, 0.92);
            $labels = ['date' => 'Date', 'voucher' => 'Voucher', 'reference' => 'Reference', 'narration' => 'Narration'];

            foreach ($columns as $key => [$x]) {
                $pdf->text($x + 2, $y + 1, $labels[$key], 8.5, true);
            }

            $pdf->textRight($debitRight - 2, $y + 1, 'Debit', 8.5, true);
            $pdf->textRight($creditRight - 2, $y + 1, 'Credit', 8.5, true);
            $pdf->textRight($balanceRight - 2, $y + 1, 'Balance', 8.5, true);

            return $y + 16;
        };

        $y = $header($pdf, true);
        $pdf->text($left + 2, $y, 'Opening balance', 9, true);
        $pdf->textRight($balanceRight - 2, $y, $money((float) $statement['opening']), 9, true);
        $y += 14;
        $limit = $pdf->pageHeight() - 70;

        foreach ($statement['rows'] as $row) {
            if ($y > $limit) {
                $y = $header($pdf, false);
            }

            $pdf->text($columns['date'][0] + 2, $y, (string) $row['date'], 8.5);
            $pdf->text($columns['voucher'][0] + 2, $y, $pdf->fit((string) $row['voucher'], 64, 8.5), 8.5);
            $pdf->text($columns['reference'][0] + 2, $y, $pdf->fit((string) $row['reference'], 64, 8.5), 8.5);
            $pdf->text($columns['narration'][0] + 2, $y, $pdf->fit((string) $row['narration'], 131, 8.5), 8.5);
            $pdf->textRight($debitRight - 2, $y, $row['debit'] > 0 ? $money($row['debit']) : '-', 8.5);
            $pdf->textRight($creditRight - 2, $y, $row['credit'] > 0 ? $money($row['credit']) : '-', 8.5);
            $pdf->textRight($balanceRight - 2, $y, $money($row['balance']), 8.5);
            $pdf->line($left, $y + 4, $right, $y + 4, 0.3, 0.85);
            $y += 14;
        }

        if ($y > $limit - 40) {
            $y = $header($pdf, false);
        }

        $y += 4;
        $pdf->line($left, $y - 8, $right, $y - 8, 0.8, 0.2);
        $pdf->text($left + 2, $y + 4, 'Totals', 9, true);
        $pdf->textRight($debitRight - 2, $y + 4, $money((float) $statement['debit']), 9, true);
        $pdf->textRight($creditRight - 2, $y + 4, $money((float) $statement['credit']), 9, true);
        $y += 24;
        $pdf->fillRect($right - 230, $y - 12, 230, 24, 0.92);
        $pdf->text($right - 224, $y + 3, (string) $statement['closing_label'], 9, true);
        $pdf->textRight($right - 6, $y + 3, $money(abs((float) $statement['closing'])), 11, true);
        $pdf->text($left, $y + 3, 'Please check this statement and tell us about any difference.', 8, false, 0.4);

        return $pdf->output();
    }
}
