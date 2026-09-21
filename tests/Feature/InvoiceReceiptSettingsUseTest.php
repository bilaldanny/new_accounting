<?php

use App\Models\CompanySetting;
use App\Models\DocumentSetting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The Invoice and Receipt Printer Settings are used: the digits and separator of new sales invoice numbers,
 * the terms and footer note the invoice prints, the receipt options the receipt page prints with. A company
 * that never saved them gets the numbers and the print the app always made.
 */
function irsSale(array $scope, array $overrides = []): Transaction
{
    test()->postJson('/api/sells', validSellPayload($scope, $overrides))->assertSuccessful();

    return Transaction::query()->sells()->latest('id')->firstOrFail();
}

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $values
 */
function irsInvoiceSettings(array $scope, array $values): void
{
    DocumentSetting::saveFor((int) $scope['company_id'], 'invoice', $values);
}

// --- numbering -----------------------------------------------------------------------------------

test('a company that never saved Invoice Settings gets the numbers the app always made', function () {
    $scope = seedSellScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    expect(Transaction::generateSellInvoiceNo(null))->toBe('INV-00001')
        ->and(DocumentSetting::invoiceNumberFormat(null))->toBe(['digits' => 5, 'separator' => '-'])
        ->and(irsSale($scope)->invoice_no)->toMatch('/^INV-\d{5}$/');
});

test('a new sales invoice follows the saved digits and separator', function (int $digits, string $separator, string $pattern) {
    $scope = seedSellScope();
    Sanctum::actingAs(User::query()->findOrFail(1));
    irsInvoiceSettings($scope, ['number_digits' => $digits, 'number_separator' => $separator]);

    expect(irsSale($scope)->invoice_no)->toMatch($pattern);
})->with([
    'slash and 8 digits' => [8, '/', '/^INV\/\d{8}$/'],
    'no separator and 3 digits' => [3, '', '/^INV\d{3,}$/'],
    'dash and 10 digits' => [10, '-', '/^INV-\d{10}$/'],
]);

test('the prefix still comes from Company Settings', function () {
    $scope = seedSellScope();
    Sanctum::actingAs(User::query()->findOrFail(1));
    $setting = CompanySetting::query()->where('company_id', $scope['company_id'])->first()
        ?? CompanySetting::createCompanySettings((int) $scope['company_id'], 'Prefix Company');
    $setting->update(['invoice' => 'SI']);
    irsInvoiceSettings($scope, ['number_digits' => 6, 'number_separator' => '/']);

    expect(irsSale($scope)->invoice_no)->toMatch('/^SI\/\d{6}$/');
});

test('invoices already issued keep their number when the settings change or the sale is edited', function () {
    $scope = seedSellScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $old = irsSale($scope);
    $oldNumber = $old->invoice_no;
    expect($oldNumber)->toMatch('/^INV-\d{5}$/');

    irsInvoiceSettings($scope, ['number_digits' => 8, 'number_separator' => '/']);

    $this->putJson('/api/sells/'.$old->id, validSellPayload($scope, ['shipping_charges' => 55]))->assertSuccessful();
    $new = irsSale($scope);

    expect($old->refresh()->invoice_no)->toBe($oldNumber)
        ->and($this->getJson('/api/sells/'.$old->id)->json('invoice_no'))->toBe($oldNumber)
        ->and($new->invoice_no)->toMatch('/^INV\/\d{8}$/')
        ->and($new->invoice_no)->not->toBe($oldNumber);
});

test('a number typed by hand is kept as typed', function () {
    $scope = seedSellScope();
    Sanctum::actingAs(User::query()->findOrFail(1));
    irsInvoiceSettings($scope, ['number_digits' => 8, 'number_separator' => '/']);

    expect(irsSale($scope, ['invoice_no' => 'MANUAL-7'])->invoice_no)->toBe('MANUAL-7');
});

test('the settings of one company do not change the numbers of another', function () {
    $scope = seedSellScope();
    $other = DB::table('companies')->insertGetId(['code' => 'IRS02', 'name' => 'Other Co', 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    irsInvoiceSettings($scope, ['number_digits' => 9, 'number_separator' => '/']);

    expect(Transaction::generateSellInvoiceNo($other))->toBe('INV-00001')
        ->and(Transaction::generateSellInvoiceNo((int) $scope['company_id']))->toBe('INV/000000001');
});

test('only sales invoice numbers change, other documents keep theirs', function () {
    $scope = seedSellScope();
    irsInvoiceSettings($scope, ['number_digits' => 9, 'number_separator' => '/']);

    expect(Transaction::generateInvoiceNo((int) $scope['company_id']))->toBe('PO-00001')
        ->and(Transaction::generateSellReturnNo((int) $scope['company_id']))->toMatch('/^SR-\d{5}$/');
});

test('a bad saved value falls back to a safe format', function () {
    $scope = seedSellScope();
    DocumentSetting::query()->create(['company_id' => $scope['company_id'], 'group' => 'invoice', 'settings' => ['number_digits' => 99, 'number_separator' => '*']]);

    expect(DocumentSetting::invoiceNumberFormat((int) $scope['company_id']))->toBe(['digits' => 10, 'separator' => '-']);

    DocumentSetting::query()->where('company_id', $scope['company_id'])->update(['settings' => ['number_digits' => 1, 'number_separator' => '/']]);

    expect(DocumentSetting::invoiceNumberFormat((int) $scope['company_id']))->toBe(['digits' => 3, 'separator' => '/']);
});

// --- what the invoice prints ---------------------------------------------------------------------

test('the terms and the footer note reach the invoice when they are set and switched on', function () {
    $scope = seedSellScope();
    Sanctum::actingAs(User::query()->findOrFail(1));
    $sale = irsSale($scope);
    irsInvoiceSettings($scope, [
        'show_terms_and_conditions' => true, 'terms_and_conditions' => "Goods once sold are not returnable.\nPay within 10 days.",
        'show_footer_note' => true, 'footer_note' => 'Thank you, see you again',
    ]);

    $print = $this->getJson('/api/sells/'.$sale->id)->assertSuccessful()->json('print_settings');

    expect($print['terms_and_conditions'])->toBe("Goods once sold are not returnable.\nPay within 10 days.")
        ->and($print['footer_note'])->toBe('Thank you, see you again');
});

test('a section that is empty or switched off is not sent, so the invoice does not print it', function () {
    $scope = seedSellScope();
    Sanctum::actingAs(User::query()->findOrFail(1));
    $sale = irsSale($scope);

    // never saved: the defaults are on but empty
    $print = $this->getJson('/api/sells/'.$sale->id)->json('print_settings');
    expect($print['terms_and_conditions'])->toBeNull()
        ->and($print['footer_note'])->toBeNull();

    // text present but the switch is off, and text that is only spaces
    irsInvoiceSettings($scope, ['show_terms_and_conditions' => false, 'terms_and_conditions' => 'Hidden terms', 'show_footer_note' => true, 'footer_note' => '   ']);
    $print = $this->getJson('/api/sells/'.$sale->id)->json('print_settings');

    expect($print['terms_and_conditions'])->toBeNull()
        ->and($print['footer_note'])->toBeNull();
});

test('the invoice prints the settings of the sale\'s own company, not the viewer\'s', function () {
    $scope = seedSellScope();
    Sanctum::actingAs(User::query()->findOrFail(1));
    $sale = irsSale($scope);
    $other = DB::table('companies')->insertGetId(['code' => 'IRS03', 'name' => 'Third Co', 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DocumentSetting::saveFor($other, 'invoice', ['terms_and_conditions' => 'Third company terms']);
    irsInvoiceSettings($scope, ['terms_and_conditions' => 'Own company terms']);

    expect($this->getJson('/api/sells/'.$sale->id)->json('print_settings.terms_and_conditions'))->toBe('Own company terms');
});

// --- the receipt ---------------------------------------------------------------------------------

test('the receipt options a company saved reach the receipt page, defaults otherwise', function () {
    $scope = seedSellScope();
    Sanctum::actingAs(User::query()->findOrFail(1));
    $sale = irsSale($scope);

    $default = $this->getJson('/api/sells/'.$sale->id)->json('print_settings.receipt');
    expect($default)->toMatchArray(['printer_type' => 'thermal', 'paper_width_mm' => 80, 'copies' => 1, 'show_barcode' => false, 'auto_print' => false, 'footer_text' => 'Thank you for your business']);

    DocumentSetting::saveFor((int) $scope['company_id'], 'receipt', [
        'printer_type' => 'a5', 'paper_width_mm' => 58, 'copies' => 2, 'show_logo' => false, 'show_barcode' => true,
        'auto_print' => true, 'header_text' => 'Welcome', 'footer_text' => 'Come again',
    ]);

    expect($this->getJson('/api/sells/'.$sale->id)->json('print_settings.receipt'))->toMatchArray([
        'printer_type' => 'a5', 'paper_width_mm' => 58, 'copies' => 2, 'show_logo' => false, 'show_barcode' => true,
        'auto_print' => true, 'header_text' => 'Welcome', 'footer_text' => 'Come again',
    ]);
});

test('the receipt page opens for a signed in user and not for a guest, and the invoice page is unchanged', function () {
    $this->get(route('sell.receipt', 5))->assertRedirect();

    $this->actingAs(User::query()->findOrFail(1))
        ->get(route('sell.receipt', 5))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('sell/receipt')->where('id', '5'));

    $this->actingAs(User::query()->findOrFail(1))
        ->get(route('sell.invoice', 5))
        ->assertInertia(fn ($page) => $page->component('sell/invoice')->where('id', '5'));
});
