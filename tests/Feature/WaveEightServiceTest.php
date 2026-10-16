<?php

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Wave 8, repair and field service: job cards with technician assignment and scheduling, the technician's own steps, parts, the draft
 * invoice (warranty at zero), the customer signature and the service report. Nothing here moves stock or posts to the ledger by
 * itself: the invoice is a draft sale.
 */
const W8S_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

function w8sManager(array $scope): User
{
    return fcoStaff($scope, ['/service', '/service/manage', '/service/work'], false);
}

function w8sTech(array $scope): User
{
    return fcoStaff($scope, ['/service/work'], false);
}

/**
 * @return array<string, mixed>
 */
function w8sWorld(): array
{
    $scope = fcoScope();

    return $scope + [
        'part' => prdProduct($scope, 'Spare belt', [], ['default_purchase_price' => 5, 'default_sell_price' => 12]),
        'labor' => prdProduct($scope, 'Labour charge', [], ['default_purchase_price' => 0, 'default_sell_price' => 0]),
    ];
}

function w8sJob(array $scope, array $extra = []): int
{
    return (int) test()->postJson('/api/service/jobs', $extra + ['title' => 'Machine will not start', 'contact_id' => $scope['contact_id'], 'type' => 'repair', 'service_charge' => 50, 'service_product_id' => $scope['labor']['product_id']])
        ->assertCreated()->json('data.id');
}

test('a job card goes from the call to the invoice through its steps, and a technician only reaches their own jobs', function () {
    $scope = w8sWorld();
    $manager = w8sManager($scope);
    $tech = w8sTech($scope);
    $other = w8sTech($scope);
    Sanctum::actingAs($manager);

    $id = w8sJob($scope, ['site_address' => 'Plot 4, Gate 2']);
    expect(DB::table('service_jobs')->where('id', $id)->value('status'))->toBe('new')->and(DB::table('service_jobs')->where('id', $id)->value('job_no'))->toBe('JOB-00001');

    $this->postJson("/api/service/jobs/{$id}/status", ['status' => 'completed'])->assertUnprocessable();
    $this->postJson("/api/service/jobs/{$id}/status", ['status' => 'scheduled'])->assertUnprocessable();

    $this->postJson("/api/service/jobs/{$id}/assign", ['technician_id' => $tech->id, 'scheduled_at' => '2026-10-20 10:00:00'])->assertOk();
    expect(DB::table('service_jobs')->where('id', $id)->value('status'))->toBe('scheduled');
    $this->postJson("/api/service/jobs/{$id}/status", ['status' => 'dispatched'])->assertOk();

    // the technician: sees it, moves it, adds a part; another technician cannot
    Sanctum::actingAs($tech);
    expect(collect($this->getJson('/api/service/my-jobs')->assertOk()->json('data'))->pluck('id')->all())->toBe([$id]);
    Sanctum::actingAs($other);
    expect($this->getJson('/api/service/my-jobs')->json('data'))->toBe([]);
    $this->postJson("/api/service/jobs/{$id}/status", ['status' => 'in_progress'])->assertNotFound();
    $this->postJson("/api/service/jobs/{$id}/parts", ['product_id' => $scope['part']['product_id'], 'quantity' => 1])->assertNotFound();
    $this->getJson("/api/service/jobs/{$id}")->assertNotFound();

    Sanctum::actingAs($tech);
    $this->postJson("/api/service/jobs/{$id}/status", ['status' => 'in_progress'])->assertOk();
    $this->postJson("/api/service/jobs/{$id}/parts", ['product_id' => $scope['part']['product_id'], 'quantity' => 2, 'unit_price' => 12])->assertCreated();
    $this->postJson("/api/service/jobs/{$id}/status", ['status' => 'completed', 'note' => 'Belt replaced'])->assertOk();
    $this->postJson("/api/service/jobs/{$id}/invoice")->assertForbidden();
    $this->postJson('/api/service/jobs', ['title' => 'x'])->assertForbidden();

    // the manager invoices: a draft sale with the part and the service line, nothing moves in stock or the books
    Sanctum::actingAs($manager);
    $books = [DB::table('t_accounts')->where('company_id', $scope['company_id'])->count(), DB::table('transactions')->where('company_id', $scope['company_id'])->where('type', 'sell')->count()];
    $saleId = (int) $this->postJson("/api/service/jobs/{$id}/invoice")->assertCreated()->json('data.sale_id');
    $sale = DB::table('transactions')->where('id', $saleId)->first();

    expect($sale->status)->toBe('draft')->and((int) $sale->company_id)->toBe($scope['company_id'])->and((float) $sale->final_amount)->toBe(74.0)
        ->and(DB::table('sell_lines')->where('transaction_id', $saleId)->count())->toBe(2)
        ->and(DB::table('t_accounts')->where('company_id', $scope['company_id'])->count())->toBe($books[0])
        ->and(DB::table('service_jobs')->where('id', $id)->value('status'))->toBe('invoiced')
        ->and(DB::table('service_job_events')->where('service_job_id', $id)->pluck('event')->all())->toContain('created', 'assigned', 'status', 'part', 'invoiced');

    $this->postJson("/api/service/jobs/{$id}/invoice")->assertUnprocessable();
    $this->postJson("/api/service/jobs/{$id}/status", ['status' => 'closed'])->assertOk();
    $this->putJson("/api/service/jobs/{$id}", ['title' => 'Edit after close'])->assertUnprocessable();
});

test('a warranty job is invoiced at zero so its parts still leave stock when the sale is finalised', function () {
    $scope = w8sWorld();
    Sanctum::actingAs(w8sManager($scope));
    $claim = DB::table('warranty_claims')->insertGetId([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'claim_no' => 'WC-1', 'product_id' => $scope['part']['product_id'], 'contact_id' => $scope['contact_id'],
        'issue' => 'Dead on arrival', 'claimed_on' => '2026-10-01', 'in_warranty' => 1, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $id = w8sJob($scope, ['warranty_claim_id' => $claim, 'service_charge' => 80]);

    expect((bool) DB::table('service_jobs')->where('id', $id)->value('is_warranty'))->toBeTrue();

    $this->postJson("/api/service/jobs/{$id}/status", ['status' => 'in_progress'])->assertOk();
    $this->postJson("/api/service/jobs/{$id}/parts", ['product_id' => $scope['part']['product_id'], 'quantity' => 3, 'unit_price' => 12])->assertCreated();
    $this->postJson("/api/service/jobs/{$id}/status", ['status' => 'completed'])->assertOk();
    $saleId = (int) $this->postJson("/api/service/jobs/{$id}/invoice")->assertCreated()->json('data.sale_id');

    expect((float) DB::table('transactions')->where('id', $saleId)->value('final_amount'))->toBe(0.0)
        ->and((float) DB::table('sell_lines')->where('transaction_id', $saleId)->sum('quantity'))->toBe(3.0)
        ->and($this->getJson("/api/service/jobs/{$id}")->json('data.warranty_claim.claim_no'))->toBe('WC-1');
});

test('jobs, customers, technicians, products and claims of another company are not reachable and the body company is ignored', function () {
    $scope = w8sWorld();
    $other = fcoOtherCompany();
    $theirContact = Contact::query()->create(['company_id' => $other['company_id'], 'branch_id' => $other['branch_id'], 'business_name' => 'Them Ltd', 'first_name' => 'Them', 'mobile' => '0300', 'address' => 'Their street', 'code' => 'CU-X1', 'user_type' => 'customer', 'type' => 'local', 'ntn_number' => '1', 'pay_term' => 5, 'pay_type' => 'day', 'credit_limit' => 100, 'active' => true])->id;
    $theirTech = w8sTech($other);
    $theirProduct = prdProduct($other, 'Their part');
    Sanctum::actingAs(w8sManager($other));
    $theirJob = w8sJob($other + ['contact_id' => $theirContact, 'labor' => $theirProduct], ['contact_id' => $theirContact]);

    Sanctum::actingAs(w8sManager($scope));
    $this->getJson("/api/service/jobs/{$theirJob}")->assertNotFound();
    $this->putJson("/api/service/jobs/{$theirJob}", ['title' => 'Hijack'])->assertNotFound();
    $this->postJson("/api/service/jobs/{$theirJob}/assign", ['technician_id' => 1])->assertNotFound();
    $this->postJson("/api/service/jobs/{$theirJob}/invoice")->assertNotFound();
    $this->get("/api/service/jobs/{$theirJob}/report")->assertNotFound();

    $this->postJson('/api/service/jobs', ['title' => 'x', 'contact_id' => $theirContact])->assertUnprocessable();
    $this->postJson('/api/service/jobs', ['title' => 'x', 'technician_id' => $theirTech->id])->assertUnprocessable();
    $this->postJson('/api/service/jobs', ['title' => 'x', 'service_product_id' => $theirProduct['product_id']])->assertUnprocessable();
    $this->postJson('/api/service/jobs', ['title' => 'x', 'warranty_claim_id' => 99999])->assertUnprocessable();

    $this->postJson('/api/service/jobs', ['title' => 'Mine', 'branch_id' => $other['branch_id']])->assertUnprocessable();
    $mine = (int) $this->postJson('/api/service/jobs', ['title' => 'Mine', 'company_id' => $other['company_id']])->assertCreated()->json('data.id');
    expect((int) DB::table('service_jobs')->where('id', $mine)->value('company_id'))->toBe($scope['company_id'])
        ->and(collect($this->getJson('/api/service/jobs')->json('data'))->pluck('id')->all())->toBe([$mine]);
});

test('the customer signature must be a real PNG, is kept on the job and printed on an escaped service report', function () {
    Storage::fake('local');
    $scope = w8sWorld();
    $tech = w8sTech($scope);
    Sanctum::actingAs(w8sManager($scope));
    $id = w8sJob($scope, ['title' => '<script>alert(1)</script> Pump', 'technician_id' => $tech->id, 'scheduled_at' => '2026-10-21 09:00:00', 'report_notes' => 'Seal replaced <b>ok</b>']);

    Sanctum::actingAs(w8sTech($scope));
    $this->postJson("/api/service/jobs/{$id}/signature", ['name' => 'Bilal', 'image' => W8S_PNG])->assertNotFound();

    Sanctum::actingAs($tech);
    $this->postJson("/api/service/jobs/{$id}/signature", ['name' => 'Bilal', 'image' => 'data:image/png;base64,AAAA'])->assertUnprocessable();
    $this->postJson("/api/service/jobs/{$id}/signature", ['name' => 'Bilal', 'image' => 'data:image/jpeg;base64,'.base64_encode('<?php echo 1;')])->assertUnprocessable();
    $this->postJson("/api/service/jobs/{$id}/signature", ['name' => '<i>Bilal</i>', 'image' => W8S_PNG])->assertOk();

    $job = DB::table('service_jobs')->where('id', $id)->first();
    expect($job->signature_path)->toBe("service-signatures/{$scope['company_id']}/{$id}.png")->and($job->signed_by)->toBe('Bilal');
    Storage::disk('local')->assertExists($job->signature_path);

    Sanctum::actingAs(w8sManager($scope));
    $html = $this->get("/api/service/jobs/{$id}/report")->assertOk()->getContent();

    // markup is removed when the text is saved and what is printed is escaped again
    expect($html)->not->toContain('<script')->not->toContain('<b>ok')->toContain('alert(1) Pump')->toContain('data:image/png;base64,')->toContain('Seal replaced ok');

    DB::table('service_jobs')->where('id', $id)->update(['report_notes' => 'raw <script>x</script> & "quoted"']);
    expect($this->get("/api/service/jobs/{$id}/report")->getContent())->toContain('raw &lt;script&gt;x&lt;/script&gt; &amp; &quot;quoted&quot;');
});

test('the board filters jobs by technician, type and day, and a job without a customer cannot be invoiced', function () {
    $scope = w8sWorld();
    $tech = w8sTech($scope);
    Sanctum::actingAs(w8sManager($scope));
    $field = w8sJob($scope, ['type' => 'field', 'technician_id' => $tech->id, 'scheduled_at' => '2026-10-22 14:00:00']);
    w8sJob($scope, ['type' => 'installation']);
    $noCustomer = (int) $this->postJson('/api/service/jobs', ['title' => 'Walk-in repair', 'service_charge' => 10, 'service_product_id' => $scope['labor']['product_id']])->assertCreated()->json('data.id');

    expect(collect($this->getJson('/api/service/jobs?type=field')->json('data'))->pluck('id')->all())->toBe([$field])
        ->and(collect($this->getJson('/api/service/jobs?date=2026-10-22')->json('data'))->pluck('id')->all())->toBe([$field])
        ->and(collect($this->getJson("/api/service/jobs?technician_id={$tech->id}")->json('data'))->pluck('id')->all())->toBe([$field]);

    $this->postJson("/api/service/jobs/{$noCustomer}/status", ['status' => 'in_progress'])->assertOk();
    $this->postJson("/api/service/jobs/{$noCustomer}/status", ['status' => 'completed'])->assertOk();
    $this->postJson("/api/service/jobs/{$noCustomer}/invoice")->assertUnprocessable()->assertJsonValidationErrors('contact_id');
    $this->postJson("/api/service/jobs/{$noCustomer}/parts", ['product_id' => $scope['part']['product_id'], 'quantity' => 1])->assertCreated();

    // cancelled jobs are over
    $cancelled = w8sJob($scope);
    $this->postJson("/api/service/jobs/{$cancelled}/status", ['status' => 'cancelled'])->assertOk();
    $this->postJson("/api/service/jobs/{$cancelled}/status", ['status' => 'in_progress'])->assertUnprocessable();
});

test('the field app manifest names its start page and icons, and its service worker never answers API calls from a cache', function () {
    $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);

    expect($manifest['start_url'])->toBe('/service/work')->and($manifest['display'])->toBe('standalone')->and($manifest['scope'])->toBe('/service/');

    foreach ($manifest['icons'] as $icon) {
        expect(file_exists(public_path(ltrim($icon['src'], '/'))))->toBeTrue()->and(getimagesize(public_path(ltrim($icon['src'], '/')))[0])->toBe((int) explode('x', $icon['sizes'])[0]);
    }

    $worker = (string) file_get_contents(public_path('field-sw.js'));
    expect($worker)->toContain("url.pathname.startsWith('/api/')")->and($worker)->toContain("request.method !== 'GET'");
});

test('assigning a job tells the technician once and reassigning the same technician and time does not repeat it', function () {
    $scope = w8sWorld();
    $tech = w8sTech($scope);
    Sanctum::actingAs(w8sManager($scope));
    $id = w8sJob($scope);

    $this->postJson("/api/service/jobs/{$id}/assign", ['technician_id' => $tech->id, 'scheduled_at' => '2026-10-25 11:00:00'])->assertOk();
    $this->postJson("/api/service/jobs/{$id}/assign", ['technician_id' => $tech->id, 'scheduled_at' => '2026-10-25 11:00:00'])->assertOk();

    expect(DB::table('app_notifications')->where('user_id', $tech->id)->where('kind', 'service_job')->count())->toBe(1);
});

test('crud matrix: a service job is added, validated, viewed, edited and cancelled', function () {
    $scope = w8sWorld();
    Sanctum::actingAs(w8sManager($scope));

    $this->postJson('/api/service/jobs', ['title' => ''])->assertUnprocessable();
    $id = w8sJob($scope);
    $this->getJson("/api/service/jobs/{$id}")->assertOk();
    $this->putJson("/api/service/jobs/{$id}", ['title' => 'Edited job', 'contact_id' => $scope['contact_id'], 'type' => 'repair'])->assertSuccessful();
    expect(DB::table('service_jobs')->where('id', $id)->value('title'))->toBe('Edited job');
    $this->postJson("/api/service/jobs/{$id}/status", ['status' => 'cancelled'])->assertOk();
    expect(DB::table('service_jobs')->where('id', $id)->value('status'))->toBe('cancelled');
});
