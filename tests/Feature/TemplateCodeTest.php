<?php

use App\Models\User;
use App\Services\Documents\TemplateCode\ReadyMadeTemplates;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * The template code editor: a company writes HTML + CSS with logic-less placeholders, saved inside the existing template system.
 */
function tcPayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'], 'name' => 'Coded invoice', 'doc_type' => 'invoice', 'language' => 'en', 'paper' => 'a4', 'mode' => 'code',
        'code_html' => '<h1>{{company.name}}</h1><p>No {{invoice.number}}</p>{{#lines}}<i>{{name}}</i>{{/lines}}<b>{{totals.grand}}</b>',
        'code_css' => 'h1{color:#0f766e}', 'is_default' => true,
    ], $overrides);
}

test('a coded template is saved, becomes the default and prints the real document inside a sandbox', function () {
    $scope = tcScope();
    Sanctum::actingAs(tcStaff($scope));

    $this->postJson('/api/document-templates', tcPayload($scope))->assertCreated()->assertJsonPath('data.mode', 'code');
    $sale = createSellRecord($scope, ['invoice_no' => 'TC-1']);

    $response = $this->get("/api/documents/{$sale->id}/render")->assertOk();
    $response->assertHeader('Content-Security-Policy');
    expect($response->headers->get('Content-Security-Policy'))->toContain('sandbox')->not->toContain('script-src');
    expect($response->getContent())->toContain('TC-1')->toContain('Acme Traders')->toContain('color:#0f766e')->not->toContain('<script');
});

test('active content in saved code never reaches the printed page', function () {
    $scope = tcScope();
    Sanctum::actingAs(tcStaff($scope));
    $html = '<script>alert(1)</script><img src=x onerror=alert(2)><a href="javascript:alert(3)">x</a><iframe src="https://evil.test"></iframe><p onclick="y()">{{invoice.number}}</p>';
    $css = '@import url(https://evil.test/a.css); p{position:fixed;background:url(https://evil.test/i.png)}';

    $this->postJson('/api/document-templates', tcPayload($scope, ['code_html' => $html, 'code_css' => $css]))->assertCreated();
    $sale = createSellRecord($scope, ['invoice_no' => 'TC-2']);
    $out = $this->get("/api/documents/{$sale->id}/render")->assertOk()->getContent();

    expect(strtolower($out))->not->toContain('<script')->not->toContain('onerror')->not->toContain('onclick')->not->toContain('javascript:')
        ->not->toContain('<iframe')->not->toContain('evil.test')->not->toContain('fixed')->toContain('tc-2');
});

test('invalid code and oversize code are refused with a message', function () {
    $scope = tcScope();
    Sanctum::actingAs(tcStaff($scope));

    $this->postJson('/api/document-templates', tcPayload($scope, ['code_html' => '{{#lines}}<i>{{name}}</i>']))->assertUnprocessable()->assertJsonValidationErrors('code_html');
    $this->postJson('/api/document-templates', tcPayload($scope, ['code_html' => '{{{invoice.number}}}']))->assertUnprocessable()->assertJsonValidationErrors('code_html');
    $this->postJson('/api/document-templates', tcPayload($scope, ['code_html' => str_repeat('a', 110 * 1024)]))->assertUnprocessable();

    $this->postJson('/api/document-templates/code/validate', ['code_html' => '<p>{{invoice.number}} {{nothing.here}}</p>'])->assertOk()
        ->assertJsonPath('valid', true)->assertJsonPath('unknown.0', 'nothing.here');
    $this->postJson('/api/document-templates/code/validate', ['code_html' => '{{/x}}'])->assertOk()->assertJsonPath('valid', false);
});

test('a template and its versions belong to one company and only the last ten versions are kept', function () {
    $scope = tcScope();
    $other = fcoOtherCompany('tc');
    Sanctum::actingAs(tcStaff($scope));
    $id = $this->postJson('/api/document-templates', tcPayload($scope))->assertCreated()->json('data.id');

    for ($i = 1; $i <= 12; $i++) {
        $this->putJson("/api/document-templates/{$id}", tcPayload($scope, ['code_html' => "<p>version {$i} {{invoice.number}}</p>"]))->assertCreated();
    }

    $versions = $this->getJson("/api/document-templates/{$id}/versions")->assertOk()->json('data');
    expect($versions)->toHaveCount(10);
    $this->getJson("/api/document-templates/{$id}/versions/{$versions[0]['id']}")->assertOk()->assertJsonPath('data.code_html', '<p>version 12 {{invoice.number}}</p>');
    expect(DB::table('document_template_versions')->where('template_id', $id)->count())->toBe(10);

    Sanctum::actingAs(tcStaff($other));
    $this->getJson("/api/document-templates/{$id}/versions")->assertNotFound();
    $this->getJson("/api/document-templates/{$id}/versions/{$versions[0]['id']}")->assertNotFound();
    $this->putJson("/api/document-templates/{$id}", tcPayload($other))->assertNotFound();
});

test('another company data and server values cannot be reached from a template', function () {
    $scope = tcScope();
    Sanctum::actingAs(tcStaff($scope));
    $this->postJson('/api/document-templates', tcPayload($scope, ['code_html' => '<p>{{company.id}}|{{app.key}}|{{env.APP_KEY}}|{{user.email}}|{{invoice.number}}</p>']))->assertCreated();
    $sale = createSellRecord($scope, ['invoice_no' => 'TC-3']);
    $out = $this->get("/api/documents/{$sale->id}/render")->assertOk()->getContent();

    expect($out)->toContain('||||TC-3')->not->toContain('base64:');
});

test('the preview of code uses sample data or a real document and needs no save', function () {
    $scope = tcScope();
    Sanctum::actingAs(tcStaff($scope));

    $this->postJson('/api/document-templates/preview', ['doc_type' => 'invoice', 'mode' => 'code', 'code_html' => '<p>{{customer.name}} {{totals.grand}}</p>', 'code_css' => ''])
        ->assertOk()->assertJsonPath('html', fn (string $html): bool => str_contains($html, 'Sample Customer') && str_contains($html, '1,200.00'));
    $this->postJson('/api/document-templates/preview', ['doc_type' => 'invoice', 'mode' => 'code', 'code_html' => '<script>x</script><p>ok</p>'])
        ->assertOk()->assertJsonPath('html', fn (string $html): bool => ! str_contains($html, '<script'));
});

test('the editor is offered the placeholder list and the five ready-made templates, and each one renders', function () {
    $scope = tcScope();
    Sanctum::actingAs(tcStaff($scope));

    $meta = $this->getJson('/api/document-center/meta')->assertOk()->json('data');
    expect($meta['code_placeholders'])->not->toBeEmpty()->and($meta['code_templates'])->toHaveCount(5);

    foreach (ReadyMadeTemplates::all() as $template) {
        $this->postJson('/api/document-templates/code/validate', ['code_html' => $template['html'], 'code_css' => $template['css']])
            ->assertOk()->assertJsonPath('valid', true)->assertJsonPath('unknown', []);
        $this->postJson('/api/document-templates/preview', ['doc_type' => $template['doc_type'], 'mode' => 'code', 'code_html' => $template['html'], 'code_css' => $template['css']])
            ->assertOk()->assertJsonPath('html', fn (string $html): bool => str_contains($html, 'INV-00001') || str_contains($html, 'Sample Customer'));
    }
});

test('a user without manage permission cannot save template code', function () {
    $scope = tcScope();
    Sanctum::actingAs(fcoStaff($scope, ['/documentcenter'], false));

    $this->postJson('/api/document-templates', tcPayload($scope))->assertForbidden();
    $this->getJson('/api/document-center/meta')->assertOk();
});

function tcScope(): array
{
    $scope = fcoScope();
    DB::table('companies')->where('id', $scope['company_id'])->update(['name' => 'Acme Traders']);

    return $scope;
}

function tcStaff(array $scope): User
{
    return fcoStaff($scope, ['/documentcenter', '/documentcenter/manage', '/sell/:id/view', '/sell/:id/edit', '/sell/add'], false);
}

test('docs/invoice-template-codes.md carries every ready-made template', function () {
    $doc = str_replace('
', '
', file_get_contents(base_path('docs/invoice-template-codes.md')));

    foreach (ReadyMadeTemplates::all() as $template) {
        expect($doc)->toContain(str_replace("\r\n", "\n", $template['html']));
    }
});
