<?php

use App\Services\Documents\TemplateCode\CssSanitizer;
use App\Services\Documents\TemplateCode\HtmlSanitizer;
use App\Services\Documents\TemplateCode\LogicLessTemplate;
use App\Services\Documents\TemplateCode\TemplateCodeRenderer;

function codeRenderer(): TemplateCodeRenderer
{
    $css = new CssSanitizer;

    return new TemplateCodeRenderer(new LogicLessTemplate, new HtmlSanitizer($css), $css);
}

$data = [
    'invoice' => ['number' => 'INV-1', 'date' => '2026-10-15'],
    'customer' => ['name' => 'Ali <script>alert(1)</script>'],
    'lines' => [['name' => 'Rice', 'qty' => 2, 'price' => 10, 'total' => 20], ['name' => 'Oil', 'qty' => 1, 'price' => 5, 'total' => 5]],
    'totals' => ['grand' => '25.00'],
];

it('fills placeholders and repeats lines', function () use ($data) {
    $out = codeRenderer()->render('<p>{{invoice.number}}</p>{{#lines}}<i>{{name}}:{{total}}</i>{{/lines}}<b>{{totals.grand}}</b>', '', $data);

    expect($out)->toContain('INV-1')->toContain('Rice:20')->toContain('Oil:5')->toContain('25.00');
});

it('escapes data values', function () use ($data) {
    $out = codeRenderer()->render('<p>{{customer.name}}</p>', '', $data);

    expect($out)->not->toContain('<script')->toContain('&lt;script&gt;');
});

it('strips active content from the template itself', function (string $payload) {
    $out = codeRenderer()->render($payload, '', []);

    expect(strtolower($out))->not->toContain('<script')->not->toContain('onerror')->not->toContain('onclick')->not->toContain('javascript:')
        ->not->toContain('<iframe')->not->toContain('<form')->not->toContain('<base')->not->toContain('<link')->not->toContain('<object');
})->with([
    '<script>alert(1)</script>',
    '<img src=x onerror=alert(1)>',
    '<a href="javascript:alert(1)" onclick="x()">x</a>',
    '<iframe src="https://evil.test"></iframe>',
    '<form action="https://evil.test"><input name=a></form>',
    '<base href="https://evil.test/">',
    '<link rel="stylesheet" href="https://evil.test/a.css">',
    '<object data="x"></object><embed src="x">',
    '<svg onload=alert(1)><circle/></svg>',
    '<div style="background:url(javascript:alert(1))">x</div>',
]);

it('refuses external images and keeps data and logo images', function () {
    $logo = 'https://app.test/storage/logo.png';
    $out = codeRenderer()->render('<img src="https://evil.test/p.png"><img src="'.$logo.'"><img src="data:image/png;base64,AAAA">', '', [], [$logo]);

    expect($out)->not->toContain('evil.test')->toContain($logo)->toContain('data:image/png;base64,AAAA');
});

it('cleans css', function () {
    $css = '@import url(https://evil.test/x.css); body{background:url(https://evil.test/a.png);color:red} .a{position:fixed;width:expression(alert(1))} .b{position:relative;z-index:9999;margin:0}'
        .' @font-face{font-family:x;src:url(https://evil.test/f)} @media print{.c{color:blue}} .d{color:'.chr(92).'72 ed}';
    $out = (new CssSanitizer)->sanitize($css);

    expect($out)->not->toContain('evil.test')->not->toContain('@import')->not->toContain('fixed')->not->toContain('expression')
        ->not->toContain('z-index')->not->toContain('@font-face')->not->toContain(chr(92))
        ->toContain('color:red')->toContain('@media print')->toContain('margin:0');
});

it('cannot run php, blade or reach other data', function () use ($data) {
    $out = codeRenderer()->render('<?php echo 1; ?>@php echo 2 @endphp {{ config.app.key }} {{company.secret}}', '', $data);

    expect($out)->not->toContain('echo 1')->not->toContain('base64:');
});

it('rejects unescaped output, partials and bad sections', function (string $template) {
    expect(fn () => codeRenderer()->validate($template))->toThrow(InvalidArgumentException::class);
})->with(['{{{invoice.number}}}', '{{&invoice.number}}', '{{> partial}}', '{{#lines}}x', '{{/lines}}', '{{system("ls")}}']);

it('limits size and work', function () {
    expect(fn () => codeRenderer()->render(str_repeat('a', 200 * 1024), '', []))->toThrow(InvalidArgumentException::class);

    $lines = array_fill(0, 3001, ['name' => 'x']);

    expect(fn () => codeRenderer()->render('{{#lines}}{{name}}{{/lines}}', '', ['lines' => $lines]))->toThrow(InvalidArgumentException::class);
});

it('places the qr image after sanitizing', function () {
    $qr = 'data:image/png;base64,QUJD';
    $out = codeRenderer()->render('<img src="{{qr}}">', '', [], [], $qr);

    expect($out)->toContain($qr);
    expect(codeRenderer()->render('<img src="{{qr}}">', '', [], [], 'https://evil.test/q.png'))->not->toContain('evil.test');
});
