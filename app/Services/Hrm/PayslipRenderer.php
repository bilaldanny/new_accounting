<?php

namespace App\Services\Hrm;

use App\Models\Company;
use App\Services\Documents\DocumentBlocks;
use App\Services\Documents\DocumentLabels;
use Illuminate\Support\Facades\DB;

/**
 * A payslip as one standalone HTML page (it prints on A4 and the browser saves it as PDF): the company, the employee, the month,
 * earnings, deductions, employer contributions and the net pay, with the same branding allowlist as the invoice. Every value is
 * escaped; the page has no script. The data comes from the stored payroll line, so a payslip never changes after the run is approved.
 */
class PayslipRenderer
{
    public function render(int $lineId, int $companyId, string $language = 'en'): string
    {
        $line = DB::table('payroll_lines as l')->join('payroll_runs as r', 'r.id', '=', 'l.payroll_run_id')->join('employees as e', 'e.id', '=', 'l.employee_id')
            ->where('l.company_id', $companyId)->where('l.id', $lineId)
            ->first(['l.*', 'r.period', 'r.status as run_status', 'e.first_name', 'e.last_name', 'e.employee_no', 'e.designation', 'e.cnic', 'e.bank_name', 'e.bank_account', 'e.join_date']);
        abort_if($line === null, 404);

        $detail = (array) json_decode((string) $line->detail, true);
        $company = Company::query()->with('companySetting')->find($companyId);
        $branding = DocumentBlocks::defaultBranding();
        $e = fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $money = fn (mixed $v): string => number_format((float) $v, 2);
        $word = fn (string $k): string => $language === 'ur' ? DocumentLabels::word('ur', $k) : DocumentLabels::word('en', $k);
        $rows = function (array $items) use ($e, $money): string {
            $html = '';

            foreach ($items as $item) {
                $html .= '<tr><td>'.$e($item['name'] ?? '').'</td><td class="num">'.$e($money($item['amount'] ?? 0)).'</td></tr>';
            }

            return $html === '' ? '<tr><td colspan="2" class="muted">-</td></tr>' : $html;
        };

        $page = '<!DOCTYPE html><html lang="'.$e($language).'" dir="'.DocumentLabels::direction($language).'"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Payslip '.$e($line->period).' '.$e($line->employee_no).'</title><style>'
            .'@page{size:A4;margin:10mm;}body{font-family:'.DocumentBlocks::FONTS['sans'].';font-size:10pt;color:'.$branding['text_color'].';margin:0}.slip{max-width:190mm;margin:0 auto}h1,h2{margin:0;color:'.$branding['primary_color'].'}'
            .'table{width:100%;border-collapse:collapse;margin:3mm 0}td,th{padding:1.2mm 1.5mm;border-bottom:1px solid '.$branding['accent_color'].';text-align:start}th{background:'.$branding['accent_color'].'}.num{text-align:end;white-space:nowrap}.muted{opacity:.7}.two{display:flex;gap:8mm}.two>div{flex:1}.net td{font-weight:bold;border-top:2px solid '.$branding['primary_color'].'}'
            .'</style></head><body><div class="slip"><h2>'.$e($company?->companySetting?->business_name ?: $company?->name).'</h2><h1>Payslip - '.$e($line->period).'</h1>'
            .'<table><tbody><tr><td>Employee</td><td>'.$e(trim($line->first_name.' '.$line->last_name)).'</td><td>No</td><td>'.$e($line->employee_no).'</td></tr>'
            .'<tr><td>Designation</td><td>'.$e($line->designation).'</td><td>CNIC</td><td>'.$e($line->cnic).'</td></tr>'
            .'<tr><td>Bank</td><td>'.$e($line->bank_name).'</td><td>Account</td><td>'.$e($line->bank_account).'</td></tr></tbody></table>'
            .'<div class="two"><div><table><thead><tr><th>Earnings</th><th class="num">'.$e($word('amount')).'</th></tr></thead><tbody>'.$rows((array) ($detail['earnings'] ?? [])).'<tr class="net"><td>Gross pay</td><td class="num">'.$e($money($line->gross)).'</td></tr></tbody></table></div>'
            .'<div><table><thead><tr><th>Deductions</th><th class="num">'.$e($word('amount')).'</th></tr></thead><tbody>'.$rows((array) ($detail['deductions'] ?? [])).'<tr class="net"><td>Total deductions</td><td class="num">'.$e($money((float) $line->gross - (float) $line->net)).'</td></tr></tbody></table></div></div>'
            .'<table><tbody><tr class="net"><td>Net pay</td><td class="num">'.$e($money($line->net)).'</td></tr></tbody></table>'
            .'<h2 style="font-size:11pt">Employer contributions (not deducted from you)</h2><table><tbody>'.$rows((array) ($detail['employer'] ?? [])).'</tbody></table>'
            .'<p class="muted">Days: '.$e($detail['attendance']['records'] ?? 0).' attendance records, '.$e($detail['attendance']['absent'] ?? 0).' absent / unpaid, '.$e($detail['attendance']['overtime_minutes'] ?? 0).' overtime minutes.</p></div></body></html>';

        return $page;
    }
}
