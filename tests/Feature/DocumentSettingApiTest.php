<?php

use App\Models\DocumentSetting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function dstCompany(string $code = 'DST001'): int
{
    return DB::table('companies')->insertGetId([
        'code' => $code, 'name' => 'Company '.$code, 'address' => '1 Test Street', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * @param  list<string>  $paths  the menu permissions the user's role is given
 */
function dstStaff(int $companyId, array $paths = []): User
{
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => $companyId, 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path);
    }

    return createStaffUserForRole($role, ['company_id' => $companyId]);
}

dataset('setting groups', ['barcode', 'invoice', 'receipt', 'checkout']);

// --- the registry ------------------------------------------------------------------------------

test('every default of every group passes that group\'s own rules', function (string $group) {
    $defaults = collect(DocumentSetting::GROUPS[$group]['fields'])->map(fn (array $field) => $field['default'])->all();

    $validator = Validator::make($defaults, DocumentSetting::rulesFor($group));

    expect($validator->errors()->all())->toBe([]);
})->with('setting groups');

test('every group has a page path and every field a label and a known type', function (string $group) {
    expect(DocumentSetting::GROUPS[$group]['path'])->toBe('/'.$group.'/settings');

    foreach (DocumentSetting::GROUPS[$group]['fields'] as $key => $field) {
        expect($field['label'])->not->toBe('')
            ->and($field['type'])->toBeIn(['select', 'number', 'switch', 'text', 'textarea'])
            ->and($field)->toHaveKeys(['default', 'rules']);

        if ($field['type'] === 'select') {
            expect($field['options'])->toBeArray()->not->toBeEmpty();
        }
    }
})->with('setting groups');

// --- reading -----------------------------------------------------------------------------------

test('a company with nothing saved gets the defaults and the form description', function (string $group) {
    $companyId = dstCompany();
    Sanctum::actingAs(dstStaff($companyId));

    $response = $this->getJson('/api/document-settings/'.$group)->assertSuccessful();

    expect($response->json('group'))->toBe($group)
        ->and($response->json('label'))->toBe(DocumentSetting::GROUPS[$group]['label'])
        ->and($response->json('company_id'))->toBe($companyId)
        ->and($response->json('values'))->toBe(collect(DocumentSetting::GROUPS[$group]['fields'])->map(fn (array $field) => $field['default'])->all())
        ->and(collect($response->json('fields'))->pluck('key')->all())->toBe(array_keys(DocumentSetting::GROUPS[$group]['fields']))
        ->and(DocumentSetting::query()->count())->toBe(0);
})->with('setting groups');

test('reading needs no menu permission but a signed-in user of a company', function () {
    $companyId = dstCompany();

    $this->getJson('/api/document-settings/barcode')->assertUnauthorized();

    Sanctum::actingAs(dstStaff($companyId));
    $this->getJson('/api/document-settings/barcode')->assertSuccessful();
});

test('an unknown group is a 404', function () {
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->getJson('/api/document-settings/bogus')->assertNotFound();
    $this->putJson('/api/document-settings/bogus', [])->assertNotFound();
});

test('the superadmin has to name the company', function () {
    $companyId = dstCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->getJson('/api/document-settings/invoice')->assertUnprocessable();
    $this->putJson('/api/document-settings/invoice', ['footer_note' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['company_id']);
    $this->putJson('/api/document-settings/invoice', ['company_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors(['company_id']);

    $this->putJson('/api/document-settings/invoice', ['company_id' => $companyId, 'footer_note' => 'Thanks'])->assertSuccessful();
    $this->getJson('/api/document-settings/invoice?company_id='.$companyId)->assertJsonPath('values.footer_note', 'Thanks');
});

// --- saving ------------------------------------------------------------------------------------

test('saved values are read back and the rest stay at their defaults', function () {
    $companyId = dstCompany();
    Sanctum::actingAs(dstStaff($companyId, ['/invoice/settings/update']));

    $this->putJson('/api/document-settings/invoice', [
        'number_digits' => 7,
        'terms_and_conditions' => "Goods once sold\nare not returnable",
        'footer_note' => 'Visit again',
        'show_footer_note' => false,
    ])->assertSuccessful()->assertJsonPath('message', 'Successfully Saved');

    $values = $this->getJson('/api/document-settings/invoice')->json('values');

    expect($values['number_digits'])->toBe(7)
        ->and($values['terms_and_conditions'])->toBe("Goods once sold\nare not returnable")
        ->and($values['footer_note'])->toBe('Visit again')
        ->and($values['show_footer_note'])->toBeFalse()
        ->and($values['number_separator'])->toBe('-')
        ->and($values['show_terms_and_conditions'])->toBeTrue();
});

test('a partial save keeps the values it does not mention, and saving twice keeps one row', function () {
    $companyId = dstCompany();
    Sanctum::actingAs(dstStaff($companyId, ['/receipt/settings/update']));

    $this->putJson('/api/document-settings/receipt', ['copies' => 3, 'printer_type' => 'a4'])->assertSuccessful();
    $this->putJson('/api/document-settings/receipt', ['header_text' => 'Main Street Store'])->assertSuccessful();

    $values = $this->getJson('/api/document-settings/receipt')->json('values');

    expect($values['copies'])->toBe(3)
        ->and($values['printer_type'])->toBe('a4')
        ->and($values['header_text'])->toBe('Main Street Store')
        ->and(DocumentSetting::query()->where('company_id', $companyId)->count())->toBe(1);
});

test('the values are typed: switches are booleans, numbers and selects that are numbers stay integers', function () {
    $companyId = dstCompany();
    Sanctum::actingAs(dstStaff($companyId, ['/receipt/settings/update']));

    $this->putJson('/api/document-settings/receipt', ['show_logo' => '0', 'auto_print' => 1, 'paper_width_mm' => '58', 'copies' => '2'])->assertSuccessful();

    $values = $this->getJson('/api/document-settings/receipt')->json('values');

    expect($values['show_logo'])->toBeFalse()
        ->and($values['auto_print'])->toBeTrue()
        ->and($values['paper_width_mm'])->toBe(58)
        ->and($values['copies'])->toBe(2);
});

test('an empty separator is a valid choice and text is trimmed', function () {
    $companyId = dstCompany();
    Sanctum::actingAs(dstStaff($companyId, ['/invoice/settings/update']));

    $this->putJson('/api/document-settings/invoice', ['number_separator' => '', 'footer_note' => '  padded  '])->assertSuccessful();

    $values = $this->getJson('/api/document-settings/invoice')->json('values');

    expect($values['number_separator'])->toBe('')
        ->and($values['footer_note'])->toBe('padded');

    $this->putJson('/api/document-settings/invoice', ['number_separator' => '/'])->assertSuccessful();
    expect($this->getJson('/api/document-settings/invoice')->json('values.number_separator'))->toBe('/');
});

test('unknown keys are ignored and never stored', function () {
    $companyId = dstCompany();
    Sanctum::actingAs(dstStaff($companyId, ['/barcode/settings/update']));

    $this->putJson('/api/document-settings/barcode', ['barcode_type' => 'EAN13', 'evil' => '<script>', 'company_id' => 5])->assertSuccessful();

    $stored = DocumentSetting::query()->where('company_id', $companyId)->firstOrFail()->settings;

    expect($stored)->not->toHaveKey('evil')
        ->and($stored)->not->toHaveKey('company_id')
        ->and($stored['barcode_type'])->toBe('EAN13')
        ->and(array_keys($stored))->toBe(array_keys(DocumentSetting::GROUPS['barcode']['fields']));
});

test('saving validates every field', function (string $group, array $body, string $field) {
    $companyId = dstCompany();
    Sanctum::actingAs(dstStaff($companyId, ["/{$group}/settings/update"]));

    $this->putJson('/api/document-settings/'.$group, $body)->assertUnprocessable()->assertJsonValidationErrors([$field]);

    expect(DocumentSetting::query()->count())->toBe(0);
})->with([
    'barcode: unknown format' => ['barcode', ['barcode_type' => 'QR'], 'barcode_type'],
    'barcode: unknown layout' => ['barcode', ['layout' => 'huge'], 'layout'],
    'barcode: width too small' => ['barcode', ['label_width_mm' => 9], 'label_width_mm'],
    'barcode: height too large' => ['barcode', ['label_height_mm' => 301], 'label_height_mm'],
    'barcode: width not a whole number' => ['barcode', ['label_width_mm' => 50.5], 'label_width_mm'],
    'barcode: bad price mode' => ['barcode', ['show_price' => 'both'], 'show_price'],
    'barcode: switch not a boolean' => ['barcode', ['show_variation' => 'maybe'], 'show_variation'],
    'barcode: switch cannot be empty' => ['barcode', ['show_product_name' => null], 'show_product_name'],
    'invoice: digits too few' => ['invoice', ['number_digits' => 2], 'number_digits'],
    'invoice: digits too many' => ['invoice', ['number_digits' => 11], 'number_digits'],
    'invoice: unknown separator' => ['invoice', ['number_separator' => '.'], 'number_separator'],
    'invoice: terms too long' => ['invoice', ['terms_and_conditions' => str_repeat('t', 2001)], 'terms_and_conditions'],
    'invoice: footer too long' => ['invoice', ['footer_note' => str_repeat('f', 501)], 'footer_note'],
    'receipt: unknown printer' => ['receipt', ['printer_type' => 'laser'], 'printer_type'],
    'receipt: unknown paper width' => ['receipt', ['paper_width_mm' => 70], 'paper_width_mm'],
    'receipt: no copies' => ['receipt', ['copies' => 0], 'copies'],
    'receipt: too many copies' => ['receipt', ['copies' => 6], 'copies'],
    'receipt: header too long' => ['receipt', ['header_text' => str_repeat('h', 201)], 'header_text'],
    'receipt: footer too long' => ['receipt', ['footer_text' => str_repeat('f', 301)], 'footer_text'],
]);

test('the boundary values are accepted', function () {
    $companyId = dstCompany();
    Sanctum::actingAs(dstStaff($companyId, ['/barcode/settings/update', '/invoice/settings/update', '/receipt/settings/update']));

    $this->putJson('/api/document-settings/barcode', ['label_width_mm' => 10, 'label_height_mm' => 300])->assertSuccessful();
    $this->putJson('/api/document-settings/invoice', ['number_digits' => 3, 'terms_and_conditions' => str_repeat('t', 2000), 'footer_note' => str_repeat('f', 500)])->assertSuccessful();
    $this->putJson('/api/document-settings/invoice', ['number_digits' => 10])->assertSuccessful();
    $this->putJson('/api/document-settings/receipt', ['copies' => 1, 'header_text' => str_repeat('h', 200)])->assertSuccessful();
    $this->putJson('/api/document-settings/receipt', ['copies' => 5, 'footer_text' => ''])->assertSuccessful();

    expect($this->getJson('/api/document-settings/receipt')->json('values.footer_text'))->toBe('');
});

test('a group is isolated from the other groups and from other companies', function () {
    $own = dstCompany('DST001');
    $other = dstCompany('DST002');
    Sanctum::actingAs(dstStaff($own, ['/invoice/settings/update']));

    $this->putJson('/api/document-settings/invoice', ['footer_note' => 'Mine'])->assertSuccessful();

    expect($this->getJson('/api/document-settings/receipt')->json('values.footer_text'))->toBe('Thank you for your business');

    Sanctum::actingAs(dstStaff($other));

    expect($this->getJson('/api/document-settings/invoice')->json('values.footer_note'))->toBe('');
});

test('a company user always saves their own company, whatever company the request names', function () {
    $own = dstCompany('DST001');
    $other = dstCompany('DST002');
    Sanctum::actingAs(dstStaff($own, ['/invoice/settings/update']));

    $this->putJson('/api/document-settings/invoice', ['company_id' => $other, 'footer_note' => 'Mine'])->assertSuccessful();

    expect(DocumentSetting::query()->where('company_id', $own)->count())->toBe(1)
        ->and(DocumentSetting::query()->where('company_id', $other)->count())->toBe(0);

    $this->getJson('/api/document-settings/invoice?company_id='.$other)->assertJsonPath('values.footer_note', 'Mine');
});

test('saving is forbidden without the group\'s update permission, and another group\'s permission does not help', function () {
    $companyId = dstCompany();
    Sanctum::actingAs(dstStaff($companyId, ['/barcode/settings/update']));

    $this->putJson('/api/document-settings/invoice', ['footer_note' => 'Nope'])->assertForbidden();
    $this->putJson('/api/document-settings/receipt', ['copies' => 2])->assertForbidden();
    $this->putJson('/api/document-settings/barcode', ['barcode_type' => 'UPC'])->assertSuccessful();

    expect(DocumentSetting::query()->count())->toBe(1);
});

test('a stored value of the wrong type, or for a key that is gone, does not break reading', function () {
    $companyId = dstCompany();
    DocumentSetting::query()->create(['company_id' => $companyId, 'group' => 'receipt', 'settings' => [
        'copies' => 'lots', 'show_logo' => 'no', 'paper_width_mm' => 58, 'retired_setting' => 'x',
    ]]);
    Sanctum::actingAs(dstStaff($companyId));

    $values = $this->getJson('/api/document-settings/receipt')->assertSuccessful()->json('values');

    expect($values['copies'])->toBe(1)
        ->and($values['show_logo'])->toBeFalse()
        ->and($values['paper_width_mm'])->toBe(58)
        ->and($values)->not->toHaveKey('retired_setting');
});

test('a company and group can only have one row', function () {
    $companyId = dstCompany();
    DocumentSetting::query()->create(['company_id' => $companyId, 'group' => 'barcode', 'settings' => []]);

    expect(fn () => DocumentSetting::query()->create(['company_id' => $companyId, 'group' => 'barcode', 'settings' => []]))->toThrow(QueryException::class);
});

// --- pages -------------------------------------------------------------------------------------

test('guests are sent away from the settings pages', function (string $group) {
    $this->get(route($group.'.settings'))->assertRedirect();
})->with('setting groups');

test('the superadmin can open every settings page and the group is passed to it', function (string $group) {
    $this->actingAs(User::query()->findOrFail(1))
        ->get(route($group.'.settings'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('documentsetting/index')->where('group', $group));
})->with('setting groups');

test('a user is let into exactly the settings pages their menu permissions name', function () {
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true]);
    grantMenuPermission($role->id, '/invoice/settings');
    $user = createStaffUserForRole($role);

    $this->actingAs($user)->get(route('invoice.settings'))->assertSuccessful();
    $this->actingAs($user)->get(route('barcode.settings'))->assertForbidden();
    $this->actingAs($user)->get(route('receipt.settings'))->assertForbidden();
});
