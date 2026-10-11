<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ContactAttachment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(ContactAttachment::DISK);
});

/**
 * @return array{company_id: int, branch_id: int, customer: Contact, supplier: Contact}
 */
function catScope(string $suffix): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'CAT'.$suffix, 'name' => 'Docs Co '.$suffix, 'is_active' => 1, 'max_storage_mb' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $branchId = DB::table('branches')->insertGetId([
        'code' => 'CATB'.$suffix, 'company_id' => $companyId, 'name' => 'Branch '.$suffix, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $make = fn (string $type, string $name): Contact => Contact::query()->create([
        'company_id' => $companyId, 'branch_id' => $branchId, 'business_name' => $name, 'first_name' => $name, 'mobile' => '0300'.random_int(1000000, 9999999),
        'address' => 'x', 'code' => strtoupper(substr($type, 0, 2)).'-'.$suffix, 'user_type' => $type, 'type' => 'local', 'ntn_number' => '1', 'active' => true,
    ]);

    return ['company_id' => $companyId, 'branch_id' => $branchId, 'customer' => $make('customer', 'Docs Customer '.$suffix), 'supplier' => $make('supplier', 'Docs Supplier '.$suffix)];
}

/**
 * @param  list<string>  $paths
 */
function catStaff(array $scope, array $paths): User
{
    $role = Role::query()->create(['name' => 'docs_'.uniqid(), 'company_id' => $scope['company_id'], 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path);
    }

    return createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'email_verified_at' => now()]);
}

function catPdf(string $name = 'agreement.pdf', int $kilobytes = 1): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n".str_repeat('x', max($kilobytes - 1, 0) * 1024));
}

/**
 * A real file on disk, so the server sniffs its content (the fake upload helper reports a type from the name alone).
 */
function catRealFile(string $name, string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'cat');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

test('a customer document can be uploaded, listed, downloaded and deleted', function () {
    $scope = catScope('A');
    Sanctum::actingAs(catStaff($scope, ['/customer/documents', '/customer/:id/view']));
    $id = $scope['customer']->id;

    $created = $this->postJson("/api/customers/{$id}/attachments", ['file' => catPdf(), 'description' => 'Signed agreement'])->assertCreated();

    $attachmentId = $created->json('data.id');
    expect($created->json('data.file_name'))->toBe('agreement.pdf')
        ->and($created->json('data.ext'))->toBe('pdf')
        ->and(AuditLog::query()->where('auditable_type', ContactAttachment::class)->where('event', 'created')->count())->toBe(1);

    $stored = ContactAttachment::query()->findOrFail($attachmentId);
    Storage::disk(ContactAttachment::DISK)->assertExists($stored->path);

    $this->getJson("/api/customers/{$id}/attachments")->assertOk()
        ->assertJsonPath('data.0.description', 'Signed agreement')
        ->assertJsonPath('max_kilobytes', ContactAttachment::MAX_KILOBYTES);

    $this->get("/api/customers/{$id}/attachments/{$attachmentId}/download")->assertOk()->assertDownload('agreement.pdf');

    $this->deleteJson("/api/customers/{$id}/attachments/{$attachmentId}")->assertOk();

    Storage::disk(ContactAttachment::DISK)->assertMissing($stored->path);
    expect(ContactAttachment::query()->count())->toBe(0);
});

test('a supplier has its own documents, on the supplier permission', function () {
    $scope = catScope('B');
    $id = $scope['supplier']->id;

    Sanctum::actingAs(catStaff($scope, ['/customer/documents']));
    $this->postJson("/api/suppliers/{$id}/attachments", ['file' => catPdf()])->assertForbidden();

    Sanctum::actingAs(catStaff($scope, ['/supplier/documents', '/supplier/:id/view']));
    $this->postJson("/api/suppliers/{$id}/attachments", ['file' => catPdf('bank-letter.pdf')])->assertCreated();
    $this->getJson("/api/suppliers/{$id}/attachments")->assertOk()->assertJsonCount(1, 'data');

    // a supplier is not reachable through the customer routes
    $this->getJson("/api/customers/{$id}/attachments")->assertNotFound();
});

test('a file of a type that is not allowed, or too large, is refused and nothing is stored', function () {
    $scope = catScope('C');
    Sanctum::actingAs(catStaff($scope, ['/customer/documents']));
    $id = $scope['customer']->id;

    $this->postJson("/api/customers/{$id}/attachments", ['file' => catRealFile('run.exe', 'MZ'.str_repeat("\0", 200))])
        ->assertUnprocessable()->assertJsonValidationErrors(['file']);

    // a script renamed to .pdf is judged by its content, not its name
    $this->postJson("/api/customers/{$id}/attachments", ['file' => catRealFile('invoice.pdf', '<?php echo 1;')])
        ->assertUnprocessable()->assertJsonValidationErrors(['file']);

    $this->postJson("/api/customers/{$id}/attachments", ['file' => catPdf('big.pdf', ContactAttachment::MAX_KILOBYTES + 10)])
        ->assertUnprocessable()->assertJsonValidationErrors(['file']);

    expect(ContactAttachment::query()->count())->toBe(0);
});

test('documents count in the company storage and stop at its limit', function () {
    $scope = catScope('D');
    Sanctum::actingAs(catStaff($scope, ['/customer/documents']));
    $id = $scope['customer']->id;

    $this->postJson("/api/customers/{$id}/attachments", ['file' => catPdf('one.pdf', 100)])->assertCreated();

    $company = Company::query()->findOrFail($scope['company_id']);
    expect($company->storageUsedBytes())->toBeGreaterThan(90 * 1024);

    $company->update(['max_storage_mb' => 0]);

    $this->postJson("/api/customers/{$id}/attachments", ['file' => catPdf('two.pdf')])
        ->assertUnprocessable()->assertJsonValidationErrors(['document']);

    expect(ContactAttachment::query()->count())->toBe(1);
});

test('another company cannot list, download or delete a document, even with the right ids', function () {
    $mine = catScope('E');
    $theirs = catScope('F');

    Sanctum::actingAs(catStaff($theirs, ['/customer/documents', '/customer/:id/view']));
    $attachmentId = $this->postJson("/api/customers/{$theirs['customer']->id}/attachments", ['file' => catPdf('secret.pdf')])->assertCreated()->json('data.id');

    Sanctum::actingAs(catStaff($mine, ['/customer/documents', '/customer/:id/view']));

    // their contact id
    $this->getJson("/api/customers/{$theirs['customer']->id}/attachments")->assertNotFound();
    $this->get("/api/customers/{$theirs['customer']->id}/attachments/{$attachmentId}/download")->assertNotFound();
    $this->deleteJson("/api/customers/{$theirs['customer']->id}/attachments/{$attachmentId}")->assertNotFound();

    // my contact id with their attachment id
    $this->get("/api/customers/{$mine['customer']->id}/attachments/{$attachmentId}/download")->assertNotFound();
    $this->deleteJson("/api/customers/{$mine['customer']->id}/attachments/{$attachmentId}")->assertNotFound();

    expect(ContactAttachment::query()->count())->toBe(1);
});

test('viewing a document needs the view permission and a guest is turned away', function () {
    $scope = catScope('G');
    $this->getJson("/api/customers/{$scope['customer']->id}/attachments")->assertUnauthorized();

    Sanctum::actingAs(catStaff($scope, ['/customer/documents']));
    $this->getJson("/api/customers/{$scope['customer']->id}/attachments")->assertForbidden();
});
