<?php

use App\Models\Company;
use App\Models\Contact;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Documents\DocumentBlocks;
use App\Services\Documents\DocumentReminders;
use App\Services\Documents\DocumentRenderer;
use App\Services\Documents\QrCode;
use App\Services\Documents\XlsxWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

/**
 * Wave 5: the QR encoder, block and branding sanitising, the renderer, templates by branch and type, export, send, instant delivery,
 * reminders, attachments, custom fields and the public receipt.
 */
function w5Scope(): array
{
    $scope = fcoScope();
    DB::table('companies')->where('id', $scope['company_id'])->update(['name' => 'Acme Traders', 'ntn_no' => '1234567-8']);
    DB::table('contacts')->where('id', $scope['contact_id'])->update(['email' => 'sara@example.com', 'mobile' => '03007654321']);

    return $scope;
}

function w5Staff(array $scope, array $paths = []): User
{
    return fcoStaff($scope, array_merge(['/documentcenter', '/documentcenter/manage', '/sell/:id/view', '/sell/:id/edit', '/sell/add'], $paths), false);
}

function w5Blocks(string $text = 'Hello template'): array
{
    return [
        ['type' => 'header', 'props' => ['show_logo' => false]],
        ['type' => 'title', 'props' => ['show_number' => true]],
        ['type' => 'text', 'props' => ['text' => $text]],
        ['type' => 'lines', 'props' => ['columns' => ['name', 'qty', 'amount']]],
        ['type' => 'totals', 'props' => []],
    ];
}

function w5Template(array $scope, array $overrides = []): array
{
    $user = $overrides['_user'] ?? null;
    unset($overrides['_user']);

    return array_merge(['company_id' => $scope['company_id'], 'name' => 'Main invoice', 'doc_type' => 'invoice', 'language' => 'en', 'paper' => 'a4', 'blocks' => w5Blocks(), 'is_default' => true], $overrides);
}

function w5SampleData(array $overrides = []): array
{
    return array_replace_recursive([
        'company' => ['name' => 'Acme Traders', 'address' => 'Mall Road', 'phone' => '042-111', 'email' => 'a@example.com', 'logo_url' => null, 'ntn' => '123', 'strn' => ''],
        'doc' => ['id' => 1, 'type' => 'invoice', 'number' => 'INV-1', 'date' => '2026-09-10', 'due_date' => '2026-09-20', 'status' => 'final', 'note' => '', 'reference' => ''],
        'party' => ['name' => 'Customer One', 'address' => 'Street 1', 'phone' => '0300', 'email' => '', 'ntn' => '', 'strn' => ''],
        'ship_to' => '', 'show_tax_numbers' => true, 'terms' => null, 'footer_note' => null, 'fbr_number' => null, 'share_url' => 'https://example.test/r/abc',
        'lines' => [['sr' => 1, 'sku' => 'S1', 'name' => 'Widget', 'qty' => 2.0, 'unit' => 'pc', 'price' => 50.0, 'discount' => 0.0, 'tax' => 0.0, 'amount' => 100.0]],
        'totals' => ['subtotal' => 100.0, 'discount' => 0.0, 'tax' => 0.0, 'shipping' => 0.0, 'total' => 100.0, 'paid' => 0.0, 'balance' => 100.0, 'previous_balance' => 0.0],
        'custom_fields' => [],
    ], $overrides);
}

// ---------------------------------------------------------------------------------------------------------------------
// QR code and Excel writer

function w5QrDecode(array $matrix): array
{
    $size = count($matrix);
    $version = intdiv($size - 17, 4);
    [, $reserved] = (new ReflectionMethod(QrCode::class, 'functionPatterns'))->invoke(null, $version, $size);
    $spec = (new ReflectionClassConstant(QrCode::class, 'SPEC'))->getValue()[$version];

    $bits = 0;

    for ($i = 0; $i < 15; $i++) {
        $dark = $i < 6 ? $matrix[$i][8] : ($i < 8 ? $matrix[$i + 1][8] : ($i === 8 ? $matrix[8][7] : $matrix[8][14 - $i]));
        $bits |= ($dark ? 1 : 0) << $i;
    }

    $mask = null;

    foreach (range(0, 7) as $candidate) {
        if (QrCode::formatBits($candidate) === $bits) {
            $mask = $candidate;
        }
    }

    $flip = fn (int $x, int $y): bool => match ($mask) {
        0 => ($x + $y) % 2 === 0, 1 => $y % 2 === 0, 2 => $x % 3 === 0, 3 => ($x + $y) % 3 === 0,
        4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0, 5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
        6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0, default => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
    };

    $stream = '';
    $upward = true;

    for ($x = $size - 1; $x >= 1; $x -= 2) {
        if ($x === 6) {
            $x = 5;
        }

        for ($step = 0; $step < $size; $step++) {
            $y = $upward ? $size - 1 - $step : $step;

            foreach ([$x, $x - 1] as $column) {
                if (! $reserved[$y][$column]) {
                    $stream .= ($matrix[$y][$column] xor $flip($column, $y)) ? '1' : '0';
                }
            }
        }

        $upward = ! $upward;
    }

    $words = array_map('bindec', str_split(substr($stream, 0, intdiv(strlen($stream), 8) * 8), 8));
    [$dataTotal, $eccPerBlock, $groups] = $spec;
    $sizes = [];

    foreach ($groups as [$count, $length]) {
        for ($b = 0; $b < $count; $b++) {
            $sizes[] = $length;
        }
    }

    $blocks = array_fill(0, count($sizes), []);
    $cursor = 0;

    for ($i = 0; $i < max($sizes); $i++) {
        foreach ($sizes as $b => $length) {
            if ($i < $length) {
                $blocks[$b][] = $words[$cursor++];
            }
        }
    }

    $ecc = array_fill(0, count($sizes), []);

    for ($i = 0; $i < $eccPerBlock; $i++) {
        foreach ($sizes as $b => $length) {
            $ecc[$b][] = $words[$cursor++];
        }
    }

    $data = [];

    foreach ($blocks as $b => $block) {
        expect(QrCode::errorCorrection($block, $eccPerBlock))->toBe($ecc[$b]);
        $data = array_merge($data, $block);
    }

    $text = '';
    $payload = implode('', array_map(fn (int $word): string => str_pad(decbin($word), 8, '0', STR_PAD_LEFT), $data));
    $length = bindec(substr($payload, 4, 8));

    for ($i = 0; $i < $length; $i++) {
        $text .= chr((int) bindec(substr($payload, 12 + 8 * $i, 8)));
    }

    return ['mode' => substr($payload, 0, 4), 'text' => $text, 'version' => $version, 'mask' => $mask];
}

test('the QR encoder matches the published error correction vector and decodes back to its text', function () {
    // data codewords and error correction of HELLO WORLD at version 1, level M (the standard worked example)
    expect(QrCode::errorCorrection([32, 91, 11, 120, 209, 114, 220, 77, 67, 64, 236, 17, 236, 17, 236, 17], 10))->toBe([196, 35, 39, 119, 235, 215, 231, 226, 93, 23])
        ->and(QrCode::formatBits(0))->toBe(0x5412);

    foreach (range(0, 7) as $mask) {
        // a valid BCH(15,5) word has no remainder after the generator
        $word = QrCode::formatBits($mask) ^ 0x5412;
        $rem = $word;

        for ($i = 14; $i >= 10; $i--) {
            if ((($rem >> $i) & 1) === 1) {
                $rem ^= 0x537 << ($i - 10);
            }
        }

        expect($rem)->toBe(0);
    }

    foreach (['HI', 'https://acme.example/r/'.str_repeat('a', 40), str_repeat('invoice ', 12), 'https://acme.example/r/'.str_repeat('Zq', 60)] as $text) {
        $matrix = QrCode::matrix($text);
        $decoded = w5QrDecode($matrix);

        expect($decoded['text'])->toBe($text)->and($decoded['mode'])->toBe('0100')->and(count($matrix))->toBe(17 + 4 * $decoded['version']);
    }

    expect(fn () => QrCode::matrix(str_repeat('x', 200)))->toThrow(InvalidArgumentException::class)
        ->and(QrCode::svg('HI'))->toStartWith('<svg')->and(QrCode::svg('HI'))->toContain('<path');
});

test('the Excel writer makes a workbook with the cells as text and numbers and never a formula', function () {
    $content = XlsxWriter::build([['Item', 'Amount'], ['=HYPERLINK("x")', 12.5], ['A & B <c>', 3]], 'Doc');
    $path = tempnam(sys_get_temp_dir(), 'xt');
    file_put_contents($path, $content);
    $zip = new ZipArchive;

    expect($zip->open($path))->toBeTrue()->and($zip->locateName('xl/workbook.xml'))->not->toBeFalse();
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    @unlink($path);

    expect($sheet)->toContain('t="inlineStr"')->and($sheet)->toContain('<v>12.5</v>')->and($sheet)->toContain('A &amp; B &lt;c&gt;')->and($sheet)->not->toContain('<f>');
});

// ---------------------------------------------------------------------------------------------------------------------
// blocks, branding, renderer

test('block lists are checked against the whitelist and texts are stripped of markup', function () {
    $clean = DocumentBlocks::normalise([
        ['type' => 'text', 'props' => ['text' => '<script>alert(1)</script>Pay <b>soon</b>', 'align' => 'justify', 'evil' => 'x', 'bold' => '1']],
        ['type' => 'lines', 'props' => ['columns' => ['name', 'password', 'qty']]],
        ['type' => 'spacer', 'props' => ['height_mm' => 9999]],
        ['type' => 'signature', 'props' => ['labels' => ['A', 'B', 'C', 'D', '<i>E</i>']]],
    ]);

    expect($clean[0]['props'])->toBe(['text' => 'alert(1)Pay soon', 'bold' => true])
        ->and($clean[1]['props']['columns'])->toBe(['name', 'qty'])
        ->and($clean[2]['props']['height_mm'])->toBe(60)
        ->and($clean[3]['props']['labels'])->toBe(['A', 'B', 'C']);

    expect(fn () => DocumentBlocks::normalise([['type' => 'script', 'props' => []]]))->toThrow(ValidationException::class)
        ->and(fn () => DocumentBlocks::normalise([]))->toThrow(ValidationException::class)
        ->and(fn () => DocumentBlocks::normalise(array_fill(0, 41, ['type' => 'divider'])))->toThrow(ValidationException::class);

    foreach ([['primary_color' => 'red;background:url(javascript:1)'], ['primary_color' => '#12345'], ['font_family' => 'Comic Sans; x'], ['font_size_pt' => 99], ['table_style' => 'fancy']] as $bad) {
        expect(fn () => DocumentBlocks::normaliseBranding($bad))->toThrow(ValidationException::class);
    }

    expect(DocumentBlocks::normaliseBranding(['primary_color' => '#ABCDEF', 'font_family' => 'serif', 'unknown' => 'x']))->toMatchArray(['primary_color' => '#abcdef', 'font_family' => 'serif'])
        ->and(array_keys(DocumentBlocks::normaliseBranding(['unknown' => 'x'])))->not->toContain('unknown');
});

test('the renderer escapes everything, refuses unsafe logos and lays Urdu out right to left', function () {
    $data = w5SampleData([
        'company' => ['name' => '<script>alert(1)</script>', 'logo_url' => 'javascript:alert(1)'],
        'party' => ['name' => '"><img src=x onerror=alert(1)>'],
        'lines' => [['name' => '<b onmouseover=x>Item</b>']],
        'custom_fields' => [['label' => '<i>PO</i>', 'value' => '<u>77</u>']],
    ]);
    $template = ['blocks' => DocumentBlocks::defaults('invoice') + [20 => ['type' => 'qr', 'props' => ['content' => 'link']]], 'branding' => [], 'paper' => 'a4', 'language' => 'en'];
    $html = app(DocumentRenderer::class)->render($template, $data);

    expect($html)->not->toContain('<script>alert')->and($html)->not->toContain('<img src=x')->and($html)->not->toContain('javascript:')->and($html)->not->toContain('<b onmouseover')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')->and($html)->toContain('<svg')->and($html)->toContain('dir="ltr"');

    $urdu = app(DocumentRenderer::class)->render(['blocks' => DocumentBlocks::defaults('invoice'), 'branding' => [], 'paper' => 'thermal80', 'language' => 'ur'], w5SampleData());
    expect($urdu)->toContain('dir="rtl"')->and($urdu)->toContain('انوائس')->and($urdu)->toContain('size:80mm auto')->and($urdu)->not->toContain('<th class="num">Price</th>');

    $stub = app(DocumentRenderer::class)->render($template, w5SampleData(['fbr_number' => null]));
    expect($stub)->not->toContain('FBR invoice number');
});

// ---------------------------------------------------------------------------------------------------------------------
// templates and printing

test('templates are the company own, one default per branch and type, and the branch default wins', function () {
    $scope = w5Scope();
    $other = fcoOtherCompany('w5');
    Sanctum::actingAs(w5Staff($scope));

    $company = $this->postJson('/api/document-templates', w5Template($scope, ['name' => 'Company default', 'blocks' => w5Blocks('COMPANY-WIDE')]))->assertCreated()->json('data');
    $branch = $this->postJson('/api/document-templates', w5Template($scope, ['name' => 'Branch design', 'branch_id' => $scope['branch_id'], 'blocks' => w5Blocks('BRANCH-ONLY')]))->assertCreated()->json('data');
    $second = $this->postJson('/api/document-templates', w5Template($scope, ['name' => 'Second company design', 'blocks' => w5Blocks('SECOND')]))->assertCreated()->json('data');

    // making the second the company default clears the first, and leaves the branch one alone
    $defaults = collect($this->getJson('/api/document-templates')->json('data'))->where('is_default', true)->pluck('name')->sort()->values()->all();
    expect($defaults)->toBe(['Branch design', 'Second company design']);

    $sale = createSellRecord($scope, ['invoice_no' => 'W5-1']);
    $html = $this->get("/api/documents/{$sale->id}/render")->assertOk()->getContent();
    expect($html)->toContain('BRANCH-ONLY')->and($html)->toContain('W5-1')->and($html)->not->toContain('COMPANY-WIDE');

    // a branch the document is not in gets the company default; an explicit template is used as asked
    DB::table('transactions')->where('id', $sale->id)->update(['branch_id' => trpBranch($scope['company_id'], 'Other branch')]);
    expect($this->get("/api/documents/{$sale->id}/render")->getContent())->toContain('SECOND')
        ->and($this->get("/api/documents/{$sale->id}/render?template_id={$company['id']}")->getContent())->toContain('COMPANY-WIDE');

    // another company cannot see, use or change them
    Sanctum::actingAs(w5Staff($other));
    $this->getJson('/api/document-templates')->assertOk()->assertJsonCount(0, 'data');
    $this->putJson("/api/document-templates/{$branch['id']}", w5Template($other))->assertNotFound();
    $this->deleteJson("/api/document-templates/{$branch['id']}")->assertNotFound();
    $this->postJson("/api/document-templates/{$branch['id']}/default", ['company_id' => $other['company_id']])->assertNotFound();
    expect($second['id'])->toBeInt();
});

test('printing with no template uses the built-in design and the print page is permission checked', function () {
    $scope = w5Scope();
    DB::table('companies')->where('id', $scope['company_id'])->update(['name' => '<b>Acme</b> & Sons']);
    $sale = createSellRecord($scope, ['invoice_no' => 'W5-2', 'additional_note' => 'Leave at the gate']);
    Sanctum::actingAs(w5Staff($scope));

    $response = $this->get("/api/documents/{$sale->id}/render")->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/html')->and($response->getContent())->toContain('W5-2')->and($response->getContent())->toContain('&lt;b&gt;Acme&lt;/b&gt; &amp; Sons')
        ->and($response->getContent())->toContain('Leave at the gate')->and($response->getContent())->not->toContain('window.print');
    expect($this->get("/api/documents/{$sale->id}/render?print=1")->getContent())->toContain('window.print');
    expect($this->get("/api/documents/{$sale->id}/render?language=ur&paper=thermal58")->getContent())->toContain('dir="rtl"')->toContain('58mm auto');

    Sanctum::actingAs(fcoStaff($scope, [], false));
    $this->get("/api/documents/{$sale->id}/render")->assertForbidden();
});

test('a document of another company is not found by id', function () {
    $mine = w5Scope();
    $theirs = fcoOtherCompany('w5b');
    $foreign = DB::table('transactions')->insertGetId([
        'company_id' => $theirs['company_id'], 'branch_id' => $theirs['branch_id'], 'type' => 'sell', 'status' => 'final', 'invoice_no' => 'FOREIGN-1', 'payment_status' => 'due',
        'transaction_date' => now(), 'final_amount' => 99, 'created_at' => now(), 'updated_at' => now(),
    ]);
    Sanctum::actingAs(w5Staff($mine));

    $this->get("/api/documents/{$foreign}/render")->assertNotFound();
    $this->get("/api/documents/{$foreign}/export?format=csv")->assertNotFound();
    $this->postJson("/api/documents/{$foreign}/send", ['channel' => 'link'])->assertNotFound();
    $this->getJson("/api/documents/{$foreign}/attachments")->assertNotFound();
    $this->putJson("/api/documents/{$foreign}/custom-fields", ['values' => []])->assertNotFound();
    $this->postJson('/api/document-templates/preview', ['doc_type' => 'invoice', 'blocks' => w5Blocks(), 'transaction_id' => $foreign])->assertNotFound();
});

test('a sale exports to CSV and Excel with the lines and totals and formulas neutralised', function () {
    $scope = w5Scope();
    $sale = createSellRecord($scope, ['invoice_no' => 'W5-3']);
    DB::table('products')->where('id', $scope['product_id'])->update(['name' => '=SUM(A1)']);
    Sanctum::actingAs(w5Staff($scope));

    $csv = $this->get("/api/documents/{$sale->id}/export?format=csv")->assertOk()->streamedContent();
    expect($csv)->toContain("'=SUM(A1)")->and($csv)->toContain('Total')->and($csv)->toContain('120');

    $xlsx = $this->get("/api/documents/{$sale->id}/export?format=xlsx")->assertOk()->streamedContent();
    $path = tempnam(sys_get_temp_dir(), 'xs');
    file_put_contents($path, $xlsx);
    $zip = new ZipArchive;
    $zip->open($path);
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    @unlink($path);

    expect($sheet)->toContain('&apos;=SUM(A1)')->and($sheet)->not->toContain('<f>');
    $this->get("/api/documents/{$sale->id}/export?format=pdf")->assertStatus(422);
});

// ---------------------------------------------------------------------------------------------------------------------
// messages, send, instant delivery

function w5Mails(): Collection
{
    return collect(app('mailer')->getSymfonyTransport()->messages());
}

test('message templates fill their placeholders and a default replaces the built-in wording', function () {
    $scope = w5Scope();
    $sale = createSellRecord($scope, ['invoice_no' => 'W5-4', 'final_amount' => 1500]);
    Sanctum::actingAs(w5Staff($scope));

    $this->postJson('/api/message-templates', ['name' => 'Mine', 'event' => 'invoice_created', 'channel' => 'email', 'subject' => 'Bill {invoice} <b>now</b>', 'body' => 'Hi {customer}, pay {amount} to {company}. <script>x</script> {link}', 'is_default' => true])->assertCreated();
    $this->postJson('/api/message-templates', ['name' => 'Bad event', 'event' => 'nope', 'channel' => 'email', 'body' => 'x1'])->assertUnprocessable();

    $sent = $this->postJson("/api/documents/{$sale->id}/send", ['channel' => 'email'])->assertCreated()->json('data');
    $mail = quoted_printable_decode(w5Mails()->last()->toString());

    expect($sent['status'])->toBe('sent')->and($mail)->toContain('Bill W5-4 now')->and($mail)->toContain('Hi Acme Retail, pay 1,500.00 to Acme Traders.')
        ->and($mail)->toContain($sent['url'])->and($mail)->not->toContain('<script>')->and($mail)->toContain('sara@example.com');

    // a company without its own template uses the built-in wording
    $second = w5Scope2();
    Sanctum::actingAs(w5Staff($second));
    $this->postJson("/api/documents/{$second['sale']->id}/send", ['channel' => 'email'])->assertCreated();
    expect(quoted_printable_decode(w5Mails()->last()->toString()))->toContain('is ready. View it here');
});

function w5Scope2(): array
{
    $theirs = fcoOtherCompany('w5c');
    $contact = Contact::query()->create([
        'company_id' => $theirs['company_id'], 'branch_id' => $theirs['branch_id'], 'business_name' => 'Beta Stores', 'first_name' => 'B', 'mobile' => '0300', 'email' => 'beta@example.com',
        'address' => 'x', 'code' => 'CU-B', 'user_type' => 'customer', 'type' => 'local', 'ntn_number' => '1', 'active' => true,
    ]);
    $sale = Transaction::query()->create([
        'company_id' => $theirs['company_id'], 'branch_id' => $theirs['branch_id'], 'contact_id' => $contact->id, 'type' => 'sell', 'status' => 'final', 'invoice_no' => 'BETA-1',
        'payment_status' => 'due', 'transaction_date' => now(), 'final_amount' => 10,
    ]);

    return $theirs + ['sale' => $sale];
}

test('sending by link, whatsapp without a provider and with a webhook provider is recorded truthfully', function () {
    Http::fake(['hooks.example.test/*' => Http::response(['ok' => true], 200)]);
    $scope = w5Scope();
    $sale = createSellRecord($scope, ['invoice_no' => 'W5-5']);
    Sanctum::actingAs(w5Staff($scope));

    $link = $this->postJson("/api/documents/{$sale->id}/send", ['channel' => 'link'])->assertCreated()->json('data');
    expect($link['status'])->toBe('created')->and($this->get(parse_url($link['url'], PHP_URL_PATH))->assertOk()->getContent())->toContain('W5-5');

    $none = $this->postJson("/api/documents/{$sale->id}/send", ['channel' => 'whatsapp'])->assertCreated()->json('data');
    expect($none['status'])->toBe('not_configured');

    DB::table('messaging_settings')->insert(['company_id' => $scope['company_id'], 'channel' => 'whatsapp', 'provider' => 'webhook', 'endpoint' => 'https://hooks.example.test/wa', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $ok = $this->postJson("/api/documents/{$sale->id}/send", ['channel' => 'whatsapp'])->assertCreated()->json('data');
    expect($ok['status'])->toBe('sent');
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'hooks.example.test') && str_contains(json_encode($request->data()), 'W5-5'));

    // a draft is not sent
    $draft = createSellRecord($scope, ['invoice_no' => 'W5-D', 'status' => 'draft']);
    $this->postJson("/api/documents/{$draft->id}/send", ['channel' => 'link'])->assertStatus(422);
});

test('instant delivery sends a posted sale when switched on, and a failed delivery never fails the sale', function () {
    $scope = w5Scope();
    Sanctum::actingAs(w5Staff($scope));
    $payload = validSellPayload($scope);

    $this->postJson('/api/sells', $payload)->assertSuccessful();
    expect(DB::table('receipt_shares')->count())->toBe(0);

    $this->putJson('/api/document-delivery', ['auto_send_sale' => true, 'channel' => 'email', 'language' => 'en'])->assertOk();
    $response = $this->postJson('/api/sells', array_merge($payload, ['invoice_no' => null]))->assertSuccessful();
    $share = DB::table('receipt_shares')->first();

    expect($response->json('id'))->toBeInt()->and($share->status)->toBe('sent')->and($share->channel)->toBe('email')->and($share->recipient)->toBe('sara@example.com');

    // a customer with no email: the sale is saved, the delivery is recorded as failed
    DB::table('contacts')->where('id', $scope['contact_id'])->update(['email' => null]);
    $this->postJson('/api/sells', $payload)->assertSuccessful();
    expect(DB::table('receipt_shares')->orderByDesc('id')->value('status'))->toBe('failed');
});

// ---------------------------------------------------------------------------------------------------------------------
// reminders

test('due and overdue reminders go once per rule, repeat on schedule up to the maximum and leave settled or disputed invoices alone', function () {
    $scope = w5Scope();
    $sale = createSellRecord($scope, ['invoice_no' => 'W5-R1', 'transaction_date' => '2026-09-01 10:00:00', 'final_amount' => 500, 'pay_term' => 10, 'pay_type' => 'day']);
    $paid = createSellRecord($scope, ['invoice_no' => 'W5-R2', 'transaction_date' => '2026-09-01 10:00:00', 'final_amount' => 100, 'pay_term' => 10, 'pay_type' => 'day', 'payment_status' => 'paid']);
    Sanctum::actingAs(w5Staff($scope));

    $this->postJson('/api/document-reminders', ['name' => 'Overdue', 'trigger' => 'after_due', 'days' => 7, 'repeat_every_days' => 7, 'max_sends' => 2, 'channel' => 'email'])->assertCreated();
    $this->postJson('/api/document-reminders', ['name' => 'Bad trigger', 'trigger' => 'whenever', 'days' => 1, 'channel' => 'email'])->assertUnprocessable();
    $reminders = app(DocumentReminders::class);

    // due on 2026-09-11: 5 days late is too early
    expect($reminders->runDue($scope['company_id'], Carbon::parse('2026-09-16'))['sent'])->toBe(0);
    expect($reminders->runDue($scope['company_id'], Carbon::parse('2026-09-20'))['sent'])->toBe(1);
    expect($reminders->runDue($scope['company_id'], Carbon::parse('2026-09-20'))['sent'])->toBe(0);
    expect($reminders->runDue($scope['company_id'], Carbon::parse('2026-09-25'))['sent'])->toBe(0);
    expect($reminders->runDue($scope['company_id'], Carbon::parse('2026-09-27'))['sent'])->toBe(1);
    expect($reminders->runDue($scope['company_id'], Carbon::parse('2026-10-11'))['sent'])->toBe(0);

    $log = DB::table('document_reminder_log')->get();
    expect($log)->toHaveCount(2)->and($log->pluck('transaction_id')->unique()->all())->toBe([$sale->id])->and($log->pluck('status')->unique()->all())->toBe(['sent'])
        ->and(w5Mails()->count())->toBe(2)->and($paid->id)->toBeInt();

    // a disputed case stops overdue reminders for that customer
    DB::table('document_reminder_log')->delete();
    DB::table('collection_cases')->insert(['company_id' => $scope['company_id'], 'contact_id' => $scope['contact_id'], 'status' => 'disputed', 'created_at' => now(), 'updated_at' => now()]);
    expect($reminders->runDue($scope['company_id'], Carbon::parse('2026-10-20'))['sent'])->toBe(0);

    // before-due and on-due rules
    DB::table('collection_cases')->delete();
    DB::table('document_reminder_rules')->delete();
    $this->postJson('/api/document-reminders', ['name' => 'Soon', 'trigger' => 'before_due', 'days' => 3, 'channel' => 'email'])->assertCreated();
    $this->postJson('/api/document-reminders', ['name' => 'Today', 'trigger' => 'on_due', 'days' => 0, 'channel' => 'email'])->assertCreated();
    expect($reminders->runDue($scope['company_id'], Carbon::parse('2026-09-07'))['sent'])->toBe(0)
        ->and($reminders->runDue($scope['company_id'], Carbon::parse('2026-09-09'))['sent'])->toBe(1)
        ->and($reminders->runDue($scope['company_id'], Carbon::parse('2026-09-11'))['sent'])->toBe(1);
});

test('reminders of one company never touch another and a customer with no contact detail is skipped', function () {
    $mine = w5Scope();
    $theirs = w5Scope2();
    DB::table('transactions')->where('id', $theirs['sale']->id)->update(['transaction_date' => '2026-09-01 10:00:00', 'final_amount' => 77, 'pay_term' => 1, 'pay_type' => 'day']);
    DB::table('document_reminder_rules')->insert(['company_id' => $mine['company_id'], 'name' => 'Mine only', 'trigger' => 'after_due', 'days' => 1, 'max_sends' => 1, 'channel' => 'email', 'language' => 'en', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    expect(app(DocumentReminders::class)->runDue(null, Carbon::parse('2026-10-01'))['sent'])->toBe(0)->and(DB::table('document_reminder_log')->count())->toBe(0);

    createSellRecord($mine, ['invoice_no' => 'W5-NOMAIL', 'transaction_date' => '2026-09-01 10:00:00', 'final_amount' => 10, 'pay_term' => 1, 'pay_type' => 'day']);
    DB::table('contacts')->where('id', $mine['contact_id'])->update(['email' => null]);
    $result = app(DocumentReminders::class)->runDue(null, Carbon::parse('2026-10-01'));

    expect($result['sent'])->toBe(0)->and($result['skipped'])->toBeGreaterThan(0);
});

// ---------------------------------------------------------------------------------------------------------------------
// attachments and custom fields

test('attachments are checked by their real type, counted in storage and found only through their document', function () {
    Storage::fake('local');
    $scope = w5Scope();
    $sale = createSellRecord($scope, ['invoice_no' => 'W5-A']);
    $other = w5Scope2();
    Sanctum::actingAs(w5Staff($scope));

    $id = $this->post("/api/documents/{$sale->id}/attachments", ['file' => UploadedFile::fake()->create('po.pdf', 40, 'application/pdf'), 'description' => 'Customer PO'], ['Accept' => 'application/json'])
        ->assertCreated()->json('data.id');
    $this->post("/api/documents/{$sale->id}/attachments", ['file' => UploadedFile::fake()->createWithContent('run.php', '<?php echo 1;')], ['Accept' => 'application/json'])->assertUnprocessable();
    $this->post("/api/documents/{$sale->id}/attachments", ['file' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable();

    expect($this->getJson("/api/documents/{$sale->id}/attachments")->json('data'))->toHaveCount(1)
        ->and(Company::query()->find($scope['company_id'])->storageUsedBytes())->toBeGreaterThan(0);
    $this->get("/api/documents/{$sale->id}/attachments/{$id}")->assertOk();

    Sanctum::actingAs(w5Staff($other));
    $this->get("/api/documents/{$sale->id}/attachments/{$id}")->assertNotFound();
    $this->deleteJson("/api/documents/{$sale->id}/attachments/{$id}")->assertNotFound();
    $this->get("/api/documents/{$other['sale']->id}/attachments/{$id}")->assertNotFound();

    Sanctum::actingAs(w5Staff($scope));
    $this->deleteJson("/api/documents/{$sale->id}/attachments/{$id}")->assertOk();
    expect(DB::table('document_attachments')->count())->toBe(0);

    // a viewer without edit permission can list but not upload
    Sanctum::actingAs(fcoStaff($scope, ['/sell/:id/view'], false));
    $this->getJson("/api/documents/{$sale->id}/attachments")->assertOk();
    $this->post("/api/documents/{$sale->id}/attachments", ['file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertForbidden();
});

test('custom fields are defined per company, validated by type, written for a record of the company and printed', function () {
    $scope = w5Scope();
    $sale = createSellRecord($scope, ['invoice_no' => 'W5-C']);
    $other = w5Scope2();
    Sanctum::actingAs(w5Staff($scope));

    $po = $this->postJson('/api/custom-fields', ['entity' => 'sale', 'label' => 'PO number', 'type' => 'text', 'show_on_print' => true])->assertCreated()->json('data');
    $this->postJson('/api/custom-fields', ['entity' => 'sale', 'label' => 'PO number', 'type' => 'text'])->assertUnprocessable();
    $this->postJson('/api/custom-fields', ['entity' => 'sale', 'label' => 'Priority', 'type' => 'select', 'options' => ['High', 'Low'], 'is_required' => true])->assertCreated();
    $this->postJson('/api/custom-fields', ['entity' => 'sale', 'label' => 'Empty select', 'type' => 'select'])->assertUnprocessable();
    $this->postJson('/api/custom-fields', ['entity' => 'universe', 'label' => 'Nope', 'type' => 'text'])->assertUnprocessable();

    expect($po['field_key'])->toBe('po_number');
    $this->putJson("/api/documents/{$sale->id}/custom-fields", ['values' => ['po_number' => 'PO-77', 'priority' => 'Urgent']])->assertUnprocessable();
    $this->putJson("/api/documents/{$sale->id}/custom-fields", ['values' => ['po_number' => 'PO-77', 'priority' => '']])->assertUnprocessable();
    $this->putJson("/api/documents/{$sale->id}/custom-fields", ['values' => ['ghost' => 'x']])->assertUnprocessable();
    $this->putJson("/api/documents/{$sale->id}/custom-fields", ['values' => ['po_number' => '<b>PO-77</b>', 'priority' => 'High']])->assertOk();

    $values = collect($this->getJson("/api/documents/{$sale->id}/custom-fields")->json('data'))->pluck('value', 'field_key')->all();
    expect($values)->toBe(['po_number' => 'PO-77', 'priority' => 'High']);

    $html = $this->get("/api/documents/{$sale->id}/render")->getContent();
    expect($html)->toContain('PO number')->and($html)->toContain('PO-77')->and($html)->toContain('High');

    // the other company sees none of it and cannot write to this sale
    Sanctum::actingAs(w5Staff($other));
    expect($this->getJson('/api/custom-fields')->json('data'))->toBe([]);
    $this->putJson("/api/documents/{$sale->id}/custom-fields", ['values' => ['po_number' => 'x']])->assertNotFound();

    // deleting a definition removes its values
    Sanctum::actingAs(w5Staff($scope));
    $this->deleteJson("/api/custom-fields/{$po['id']}")->assertOk();
    expect(DB::table('custom_field_values')->count())->toBe(1);
});

// ---------------------------------------------------------------------------------------------------------------------
// public receipt and the guarantee

test('the public receipt uses the default receipt template when there is one and is the old page otherwise', function () {
    $scope = w5Scope();
    $sale = createSellRecord($scope, ['invoice_no' => 'W5-P']);
    Sanctum::actingAs(w5Staff($scope));

    $url = $this->postJson("/api/documents/{$sale->id}/send", ['channel' => 'link'])->json('data.url');
    $path = parse_url($url, PHP_URL_PATH);
    $old = $this->get($path)->assertOk()->getContent();
    expect($old)->toContain('W5-P')->and($old)->not->toContain('class="doc"');

    $this->postJson('/api/document-templates', w5Template($scope, ['doc_type' => 'receipt', 'name' => 'Receipt design', 'blocks' => w5Blocks('RECEIPT-DESIGN'), 'paper' => 'thermal80']))->assertCreated();
    $new = $this->get($path)->assertOk()->getContent();
    expect($new)->toContain('RECEIPT-DESIGN')->and($new)->toContain('class="doc"')->and($new)->toContain('W5-P');
    $this->get('/r/'.str_repeat('z', 40))->assertNotFound();
});

test('printing, exporting, sending and previewing change nothing in the books or stock', function () {
    $scope = w5Scope();
    $sale = createSellRecord($scope, ['invoice_no' => 'W5-G']);
    Sanctum::actingAs(w5Staff($scope));
    $snapshot = fn (): array => collect(['t_accounts', 't_account_details', 'transactions', 'payments', 'sell_lines', 'purchase_lines', 'stock_takes'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count().':'.DB::table($table)->max('updated_at')])->all();
    $before = $snapshot();

    $this->get("/api/documents/{$sale->id}/render")->assertOk();
    $this->get("/api/documents/{$sale->id}/export?format=xlsx")->assertOk();
    $this->postJson("/api/documents/{$sale->id}/send", ['channel' => 'email'])->assertCreated();
    $this->postJson('/api/document-templates/preview', ['doc_type' => 'invoice', 'blocks' => w5Blocks(), 'transaction_id' => $sale->id])->assertOk()->assertJsonStructure(['html']);
    $this->postJson('/api/document-templates/preview', ['doc_type' => 'quotation', 'blocks' => w5Blocks()])->assertOk();
    $this->postJson('/api/document-templates/preview', ['doc_type' => 'invoice', 'blocks' => [['type' => 'evil', 'props' => []]]])->assertUnprocessable();

    expect($snapshot())->toEqual($before);
    expect(Role::query()->count())->toBeGreaterThan(0);
});

// ---------------------------------------------------------------------------------------------------------------------
// codes a company types into its own invoice design

test('codes typed into a text block, the title and the footer print the real facts of the document and never markup', function () {
    $data = w5SampleData([
        'party' => ['name' => '<script>alert(1)</script>Zeeshan & Sons', 'phone' => '0300-1234567'],
        'totals' => ['subtotal' => 1000.0, 'tax' => 170.0, 'total' => 1170.0, 'paid' => 500.0, 'balance' => 670.0],
    ]);
    $blocks = [
        ['type' => 'title', 'props' => ['text' => 'Tax Invoice {invoice}']],
        ['type' => 'text', 'props' => ['text' => "Dear {customer} ({customer_phone}),\nTotal {total} = {amount_in_words}\nBalance {balance} due {due_date} {unknown_code}", 'size' => 'large', 'bold' => true]],
        ['type' => 'footer', 'props' => ['text' => 'Thank you, {company} - {company_phone}']],
    ];

    $html = app(DocumentRenderer::class)->render(['blocks' => DocumentBlocks::normalise($blocks), 'branding' => [], 'paper' => 'a4', 'language' => 'en'], $data);

    expect($html)->toContain('Tax Invoice INV-1')
        ->and($html)->toContain('0300-1234567')
        ->and($html)->toContain('Total 1,170.00 = ')
        ->and($html)->toContain('Balance 670.00 due 2026-09-20 {unknown_code}')
        ->and($html)->toContain('Thank you, Acme Traders - 042-111')
        ->and($html)->toContain('font-size:125%')
        // the value is data: the tag is shown as text, not run
        ->and($html)->not->toContain('<script>alert(1)')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;Zeeshan &amp; Sons');
});

test('the designer is told every code it may offer, and the size of a text block is a fixed list', function () {
    $scope = w5Scope();
    Sanctum::actingAs(w5Staff($scope));

    $codes = collect($this->getJson('/api/document-center/meta')->assertOk()->json('data.codes'))->pluck('code')->all();

    expect($codes)->toContain('{invoice}', '{customer}', '{total}', '{amount_in_words}', '{company_ntn}', '{balance}')
        ->and(count($codes))->toBe(count(DocumentBlocks::CODES));

    expect(fn () => DocumentBlocks::normalise([['type' => 'text', 'props' => ['text' => 'x', 'size' => 'huge']]]))->not->toThrow(ValidationException::class);
    $clean = DocumentBlocks::normalise([['type' => 'text', 'props' => ['text' => 'x', 'size' => 'huge']]]);
    expect($clean[0]['props'])->not->toHaveKey('size');
});
