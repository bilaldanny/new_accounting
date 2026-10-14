<?php

namespace App\Services\Documents;

use App\Services\AmountInWords;
use App\Services\Documents\TemplateCode\TemplateCodeData;
use App\Services\Documents\TemplateCode\TemplateCodeRenderer;

/**
 * Turns a template (blocks + branding, both already normalised by DocumentBlocks) and a document's data (DocumentData) into one
 * standalone HTML page that prints on A4, A5 or a 58 / 80 mm thermal roll. Every value from the data is escaped on output; the only
 * thing that reaches the style sheet is a validated colour, a font from a list or a clamped number; the QR code is generated here
 * (QrCode) as an SVG, so the page has no script and loads nothing from elsewhere except the company's own logo address.
 */
class DocumentRenderer
{
    /**
     * @param  array{blocks: list<array{type: string, props: array<string, mixed>}>, branding: array<string, string|int>, paper: string, language: string}  $template
     * @param  array<string, mixed>  $data
     */
    public function render(array $template, array $data, bool $autoPrint = false): string
    {
        if (($template['mode'] ?? 'blocks') === 'code') {
            return $this->renderCode((string) ($template['code_html'] ?? ''), (string) ($template['code_css'] ?? ''), $data);
        }

        $language = in_array($template['language'], DocumentBlocks::LANGUAGES, true) ? $template['language'] : 'en';
        $branding = DocumentBlocks::normaliseBranding($template['branding'] ?? []);
        $paper = in_array($template['paper'], DocumentBlocks::PAPERS, true) ? $template['paper'] : 'a4';
        $thermal = str_starts_with($paper, 'thermal');
        $word = fn (string $key): string => DocumentLabels::word($language, $key);
        $body = '';

        foreach ($template['blocks'] as $block) {
            $body .= $this->block($block['type'], $block['props'], $data, $word, $thermal);
        }

        $title = $word((string) ($data['doc']['type'] ?? 'invoice')).' '.($data['doc']['number'] ?? '');

        return '<!DOCTYPE html><html lang="'.$language.'" dir="'.DocumentLabels::direction($language).'"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
            .'<title>'.$this->e($title).'</title><style>'.$this->css($branding, $paper).'</style></head><body><main class="doc">'
            .$body.'</main>'.($autoPrint ? '<script>window.addEventListener("load",function(){window.print();});</script>' : '').'</body></html>';
    }

    /**
     * A company-written template: logic-less placeholders, sanitized HTML and CSS, no script. Serve it with
     * TemplateCodeRenderer::securityHeaders().
     *
     * @param  array<string, mixed>  $data
     */
    public function renderCode(string $html, string $css, array $data): string
    {
        $renderer = app(TemplateCodeRenderer::class);
        $logo = (string) ($data['company']['logo_url'] ?? '');
        $qr = null;

        if (! empty($data['share_url'])) {
            $qr = 'data:image/svg+xml;base64,'.base64_encode(QrCode::svg((string) $data['share_url'], 160));
        }

        try {
            return $renderer->render($html, $css, TemplateCodeData::from($data), $logo === '' ? [] : [$logo], $qr);
        } catch (\InvalidArgumentException $e) {
            return '<!DOCTYPE html><html><body><p>'.$this->e($e->getMessage()).'</p></body></html>';
        }
    }

    /**
     * @param  array<string, string|int>  $branding
     */
    private function css(array $branding, string $paper): string
    {
        $size = match ($paper) {
            'a5' => 'A5',
            'thermal80' => '80mm auto',
            'thermal58' => '58mm auto',
            default => 'A4',
        };
        $width = match ($paper) {
            'a5' => '148mm',
            'thermal80' => '76mm',
            'thermal58' => '54mm',
            default => '190mm',
        };
        $font = DocumentBlocks::FONTS[$branding['font_family']] ?? DocumentBlocks::FONTS['sans'];
        $fontSize = str_starts_with($paper, 'thermal') ? min((int) $branding['font_size_pt'], 9) : (int) $branding['font_size_pt'];
        $border = match ($branding['border_style']) {
            'thin' => 'border:1px solid '.$branding['accent_color'].';padding:4mm;',
            'box' => 'border:2px solid '.$branding['primary_color'].';padding:5mm;',
            default => '',
        };
        $striped = $branding['table_style'] === 'striped' ? 'table.lines tbody tr:nth-child(even){background:'.$branding['accent_color'].'33;}' : '';
        $grid = $branding['table_style'] === 'grid' ? 'table.lines td,table.lines th{border:1px solid '.$branding['accent_color'].';}' : 'table.lines td,table.lines th{border-bottom:1px solid '.$branding['accent_color'].';}';

        return '@page{size:'.$size.';margin:8mm;}*{box-sizing:border-box;}body{margin:0;background:#fff;color:'.$branding['text_color'].';font-family:'.$font.';font-size:'.$fontSize.'pt;line-height:1.35;}'
            .'.doc{width:'.$width.';max-width:100%;margin:0 auto;'.$border.'}h1,h2,h3{margin:0;color:'.$branding['primary_color'].';}'
            .'.row{display:flex;justify-content:space-between;gap:8mm;margin:0 0 4mm;}.block{margin:0 0 4mm;}.muted{opacity:.75;}'
            .'.logo{max-height:'.(int) $branding['logo_height_px'].'px;max-width:100%;}.left{text-align:start;}.center{text-align:center;}.right{text-align:end;}'
            .'table{width:100%;border-collapse:collapse;}th{background:'.$branding['accent_color'].';color:'.$branding['text_color'].';text-align:start;}td,th{padding:1.2mm 1.5mm;vertical-align:top;}'
            .'.num{text-align:end;white-space:nowrap;}'.$grid.$striped.'.totals{width:55%;margin-inline-start:auto;}.totals td{border:0;padding:.8mm 1.5mm;}'
            .'.totals .grand td{font-weight:bold;border-top:2px solid '.$branding['primary_color'].';}.sign{display:flex;gap:10mm;margin-top:14mm;}'
            .'.sign div{flex:1;border-top:1px solid '.$branding['text_color'].';text-align:center;padding-top:1mm;}hr{border:0;border-top:1px solid '.$branding['accent_color'].';}'
            .'@media print{body{-webkit-print-color-adjust:exact;print-color-adjust:exact;}}';
    }

    /**
     * @param  array<string, mixed>  $props
     * @param  array<string, mixed>  $data
     */
    private function block(string $type, array $props, array $data, callable $word, bool $thermal): string
    {
        $align = in_array($props['align'] ?? '', ['left', 'center', 'right'], true) ? $props['align'] : 'left';
        $flag = fn (string $key, bool $default = true): bool => array_key_exists($key, $props) ? (bool) $props[$key] : $default;
        $money = fn (mixed $value): string => number_format((float) $value, 2);

        switch ($type) {
            case 'header':
                $company = $data['company'];
                $html = '<div class="block '.$align.'">';

                if ($flag('show_logo') && ! empty($company['logo_url']) && $this->isSafeUrl((string) $company['logo_url'])) {
                    $html .= '<img class="logo" src="'.$this->e((string) $company['logo_url']).'" alt=""><br>';
                }

                $html .= '<h2>'.$this->e((string) $company['name']).'</h2>';

                if ($flag('show_address') && $company['address'] !== '') {
                    $html .= '<div>'.$this->e((string) $company['address']).'</div>';
                }

                if ($flag('show_contacts')) {
                    $contacts = array_filter([$company['phone'] !== '' ? $word('phone').': '.$company['phone'] : '', $company['email'] !== '' ? $company['email'] : '']);
                    $html .= $contacts === [] ? '' : '<div class="muted">'.$this->e(implode('  ', $contacts)).'</div>';
                }

                if ($flag('show_tax_numbers') && ($data['show_tax_numbers'] ?? true)) {
                    $numbers = array_filter([$company['ntn'] !== '' ? $word('ntn').': '.$company['ntn'] : '', $company['strn'] !== '' ? $word('strn').': '.$company['strn'] : '']);
                    $html .= $numbers === [] ? '' : '<div class="muted">'.$this->e(implode('  ', $numbers)).'</div>';
                }

                return $html.'</div>';

            case 'title':
                $doc = $data['doc'];
                $label = (string) ($props['text'] ?? '') !== '' ? $this->fill((string) $props['text'], $data) : $word((string) $doc['type']);
                $html = '<div class="block '.$align.'"><h1>'.$this->e($label).'</h1>';
                $facts = [];

                if ($flag('show_number')) {
                    $facts[] = $word('number').': '.$doc['number'];
                }

                if ($flag('show_date')) {
                    $facts[] = $word('date').': '.$doc['date'];
                }

                if ($flag('show_due_date', false) && $doc['type'] === 'invoice') {
                    $facts[] = $word('due_date').': '.$doc['due_date'];
                }

                if (! empty($data['fbr_number'])) {
                    $facts[] = $word('fbr_number').': '.$data['fbr_number'];
                }

                return $html.($facts === [] ? '' : '<div>'.$this->e(implode('   ', $facts)).'</div>').'</div>';

            case 'parties':
                $party = $data['party'];
                $isPurchase = ($data['doc']['type'] ?? '') === 'purchase_order';
                $html = '<div class="row">';

                if ($flag('show_bill_to')) {
                    $html .= '<div><strong>'.$this->e($word($isPurchase ? 'supplier' : 'bill_to')).'</strong><br>'.$this->e($party['name']);

                    foreach (array_filter([$party['address'], $flag('show_phone') && $party['phone'] !== '' ? $word('phone').': '.$party['phone'] : '']) as $line) {
                        $html .= '<br>'.$this->e((string) $line);
                    }

                    if (($data['show_tax_numbers'] ?? true) && $party['ntn'] !== '') {
                        $html .= '<br>'.$this->e($word('ntn').': '.$party['ntn']);
                    }

                    $html .= '</div>';
                }

                if ($flag('show_ship_to', false) && ($data['ship_to'] ?? '') !== '') {
                    $html .= '<div><strong>'.$this->e($word('ship_to')).'</strong><br>'.nl2br($this->e((string) $data['ship_to'])).'</div>';
                }

                return $html.'</div>';

            case 'lines':
                $columns = $props['columns'] ?? ['name', 'qty', 'amount'];
                $headers = ['sr' => 'sr', 'sku' => 'sku', 'name' => 'item', 'qty' => 'qty', 'unit' => 'unit', 'price' => 'price', 'discount' => 'discount', 'tax' => 'tax', 'amount' => 'amount'];
                $numeric = ['qty', 'price', 'discount', 'tax', 'amount'];

                if ($thermal) {
                    $columns = array_values(array_intersect($columns, ['name', 'qty', 'amount'])) ?: ['name', 'qty', 'amount'];
                }

                $html = '<table class="lines block">';

                if ($flag('show_header')) {
                    $html .= '<thead><tr>';

                    foreach ($columns as $column) {
                        $html .= '<th'.(in_array($column, $numeric, true) ? ' class="num"' : '').'>'.$this->e($word($headers[$column])).'</th>';
                    }

                    $html .= '</tr></thead>';
                }

                $html .= '<tbody>';

                foreach ($data['lines'] as $line) {
                    $html .= '<tr>';

                    foreach ($columns as $column) {
                        $value = $line[$column] ?? '';
                        $text = match ($column) {
                            'qty' => rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.'),
                            'price', 'tax', 'amount' => $money($value),
                            'discount' => (float) $value > 0 ? rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.').'%' : '',
                            default => (string) $value,
                        };
                        $html .= '<td'.(in_array($column, $numeric, true) ? ' class="num"' : '').'>'.$this->e($text).'</td>';
                    }

                    $html .= '</tr>';
                }

                return $html.'</tbody></table>';

            case 'totals':
                $t = $data['totals'];
                $rows = [[$word('subtotal'), $t['subtotal'], false]];

                if ($flag('show_discount') && $t['discount'] > 0) {
                    $rows[] = [$word('discount'), -$t['discount'], false];
                }

                if ($flag('show_tax') && $t['tax'] > 0) {
                    $rows[] = [$word('tax'), $t['tax'], false];
                }

                if ($flag('show_shipping') && $t['shipping'] > 0) {
                    $rows[] = [$word('shipping'), $t['shipping'], false];
                }

                $rows[] = [$word('total'), $t['total'], true];

                if ($flag('show_paid') && $t['paid'] > 0) {
                    $rows[] = [$word('paid'), $t['paid'], false];
                }

                if ($flag('show_balance', true) && ($data['doc']['type'] ?? '') !== 'quotation' && $t['balance'] != 0.0) {
                    $rows[] = [$word('balance'), $t['balance'], false];
                }

                if ($flag('show_previous_balance', false) && $t['previous_balance'] != 0.0) {
                    $rows[] = [$word('previous_balance'), $t['previous_balance'], false];
                }

                $html = '<table class="totals block"><tbody>';

                foreach ($rows as [$label, $value, $grand]) {
                    $html .= '<tr'.($grand ? ' class="grand"' : '').'><td>'.$this->e((string) $label).'</td><td class="num">'.$this->e($money($value)).'</td></tr>';
                }

                return $html.'</tbody></table>';

            case 'text':
                $text = (string) ($props['text'] ?? '');

                $size = ['small' => '85%', 'large' => '125%', 'xlarge' => '160%'][$props['size'] ?? 'normal'] ?? null;
                $style = (! empty($props['bold']) ? 'font-weight:bold;' : '').($size !== null ? 'font-size:'.$size.';' : '');

                return $text === '' ? '' : '<div class="block '.$align.'"'.($style !== '' ? ' style="'.$style.'"' : '').'>'.nl2br($this->e($this->fill($text, $data))).'</div>';

            case 'terms':
                $text = (string) ($props['text'] ?? '') !== '' ? (string) $props['text'] : (string) ($data['terms'] ?? '');

                return $text === '' ? '' : '<div class="block"><strong>'.$this->e($word('terms')).'</strong><div class="muted">'.nl2br($this->e($this->fill($text, $data))).'</div></div>';

            case 'notes':
                $note = (string) ($data['doc']['note'] ?? '');

                return $note === '' ? '' : '<div class="block"><strong>'.$this->e($word('notes')).'</strong><div>'.nl2br($this->e($note)).'</div></div>';

            case 'custom_fields':
                if (empty($data['custom_fields'])) {
                    return '';
                }

                $html = '<table class="block"><tbody>';

                foreach ($data['custom_fields'] as $field) {
                    $html .= '<tr><td><strong>'.$this->e((string) $field['label']).'</strong></td><td>'.$this->e((string) $field['value']).'</td></tr>';
                }

                return $html.'</tbody></table>';

            case 'qr':
                $content = ($props['content'] ?? 'link') === 'invoice_no' ? (string) ($data['doc']['number'] ?? '') : (string) ($data['share_url'] ?? $data['doc']['number'] ?? '');

                if ($content === '') {
                    return '';
                }

                try {
                    return '<div class="block '.$align.'">'.QrCode::svg(mb_substr($content, 0, 170), (int) ($props['size_px'] ?? 100)).'</div>';
                } catch (\Throwable) {
                    return '';
                }

            case 'signature':
                $labels = $props['labels'] ?? [$word('prepared_by'), $word('received_by')];
                $html = '<div class="sign">';

                foreach ($labels as $label) {
                    $html .= '<div>'.$this->e((string) $label).'</div>';
                }

                return $html.'</div>';

            case 'footer':
                $text = (string) ($props['text'] ?? '') !== '' ? (string) $props['text'] : (string) ($data['footer_note'] ?? $word('thank_you'));

                return '<div class="block muted '.$align.'">'.$this->e($this->fill($text, $data)).'</div>';

            case 'divider':
                return '<hr>';

            case 'spacer':
                return '<div style="height:'.(int) ($props['height_mm'] ?? 5).'mm"></div>';
        }

        return '';
    }

    /**
     * The codes of DocumentBlocks::CODES in free text, e.g. {invoice}, {customer}, {total}. Unknown ones are left as they are.
     *
     * @param  array<string, mixed>  $data
     */
    private function fill(string $text, array $data): string
    {
        if (! str_contains($text, '{')) {
            return $text;
        }

        $doc = $data['doc'] ?? [];
        $party = $data['party'] ?? [];
        $company = $data['company'] ?? [];
        $totals = $data['totals'] ?? [];
        $money = fn (string $key): string => number_format((float) ($totals[$key] ?? 0), 2);

        return strtr($text, [
            '{invoice}' => (string) ($doc['number'] ?? ''), '{date}' => (string) ($doc['date'] ?? ''), '{due_date}' => (string) ($doc['due_date'] ?? ''),
            '{status}' => (string) ($doc['status'] ?? ''), '{reference}' => (string) ($doc['reference'] ?? ''), '{note}' => (string) ($doc['note'] ?? ''),
            '{today}' => now()->toDateString(), '{fbr_number}' => (string) ($data['fbr_number'] ?? ''),
            '{customer}' => (string) ($party['name'] ?? ''), '{customer_address}' => (string) ($party['address'] ?? ''), '{customer_phone}' => (string) ($party['phone'] ?? ''),
            '{customer_email}' => (string) ($party['email'] ?? ''), '{customer_ntn}' => (string) ($party['ntn'] ?? ''), '{customer_strn}' => (string) ($party['strn'] ?? ''),
            '{ship_to}' => (string) ($data['ship_to'] ?? ''),
            '{company}' => (string) ($company['name'] ?? ''), '{company_address}' => (string) ($company['address'] ?? ''), '{company_phone}' => (string) ($company['phone'] ?? ''),
            '{company_email}' => (string) ($company['email'] ?? ''), '{company_ntn}' => (string) ($company['ntn'] ?? ''), '{company_strn}' => (string) ($company['strn'] ?? ''),
            '{subtotal}' => $money('subtotal'), '{discount}' => $money('discount'), '{tax}' => $money('tax'), '{shipping}' => $money('shipping'), '{total}' => $money('total'),
            '{paid}' => $money('paid'), '{balance}' => $money('balance'), '{previous_balance}' => $money('previous_balance'),
            '{amount_in_words}' => AmountInWords::convert((float) ($totals['total'] ?? 0)), '{items}' => (string) count($data['lines'] ?? []),
        ]);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Only an http(s) address or a path on this site can be a logo; never a javascript: or data: address.
     */
    private function isSafeUrl(string $url): bool
    {
        return preg_match('#^(https?://|/)[^\s"\'<>]*$#i', $url) === 1;
    }
}
