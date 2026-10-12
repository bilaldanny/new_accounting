<?php

namespace App\Services\Service;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductDetail;
use App\Services\SaleDocumentBuilder;
use App\Support\Base64Upload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Service job cards: a repair, a field job or an installation for a customer, from the call to the invoice.
 *
 *   new -> scheduled -> dispatched -> in_progress <-> waiting_parts -> completed -> invoiced -> closed        (cancelled from any open state)
 *
 * A technician moves the job through their own steps and adds the parts used; a manager assigns, schedules, edits and invoices. The
 * invoice is a draft sale made through SaleDocumentBuilder (the parts and one service line), so numbering, tax, stock and the ledger
 * follow when the sale is finalised the normal way; a warranty job is invoiced at zero. A signature (PNG, content-checked) is kept
 * on the job and printed on its service report, which is escaped HTML.
 */
class ServiceJobs
{
    public const TYPES = ['repair', 'field', 'installation'];

    public const STATUSES = ['new', 'scheduled', 'dispatched', 'in_progress', 'waiting_parts', 'completed', 'invoiced', 'closed', 'cancelled'];

    /**
     * @var array<string, list<string>>
     */
    public const FLOW = [
        'new' => ['scheduled', 'in_progress', 'cancelled'],
        'scheduled' => ['new', 'dispatched', 'in_progress', 'cancelled'],
        'dispatched' => ['scheduled', 'in_progress', 'cancelled'],
        'in_progress' => ['waiting_parts', 'completed', 'cancelled'],
        'waiting_parts' => ['in_progress', 'cancelled'],
        'completed' => ['in_progress', 'closed'],
        'invoiced' => ['closed'],
        'closed' => [],
        'cancelled' => [],
    ];

    private const SIGNATURE_DISK = 'local';

    public function __construct(private readonly SaleDocumentBuilder $builder) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(int $companyId, ?int $branchId, array $data, ?int $userId): int
    {
        $row = $this->clean($companyId, $data);

        return (int) DB::transaction(function () use ($companyId, $branchId, $row, $userId): int {
            $next = (int) DB::table('service_jobs')->where('company_id', $companyId)->count() + 1;

            do {
                $number = 'JOB-'.str_pad((string) $next++, 5, '0', STR_PAD_LEFT);
            } while (DB::table('service_jobs')->where('company_id', $companyId)->where('job_no', $number)->exists());

            $id = (int) DB::table('service_jobs')->insertGetId($row + [
                'company_id' => $companyId, 'branch_id' => $branchId, 'job_no' => $number, 'status' => $row['technician_id'] !== null && $row['scheduled_at'] !== null ? 'scheduled' : 'new',
                'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($companyId, $id, 'created', null, $row['technician_id'] !== null && $row['scheduled_at'] !== null ? 'scheduled' : 'new', null, $userId);

            return $id;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(int $companyId, int $id, array $data, ?int $userId): void
    {
        $job = $this->find($companyId, $id);

        if (in_array($job->status, ['invoiced', 'closed', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => ['An invoiced, closed or cancelled job cannot be edited.']]);
        }

        $row = $this->clean($companyId, $data + (array) $job);
        DB::table('service_jobs')->where('id', $id)->update($row + ['updated_at' => now()]);
        $this->event($companyId, $id, 'edited', null, null, null, $userId);
    }

    public function assign(int $companyId, int $id, ?int $technicianId, ?string $scheduledAt, ?int $userId): void
    {
        $job = $this->find($companyId, $id);

        if (in_array($job->status, ['completed', 'invoiced', 'closed', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => ['This job is already done.']]);
        }

        if ($technicianId !== null && ! DB::table('users')->where('company_id', $companyId)->where('id', $technicianId)->exists()) {
            throw ValidationException::withMessages(['technician_id' => ['Choose a user of this company.']]);
        }

        $status = $job->status;

        if ($technicianId !== null && $scheduledAt !== null && in_array($job->status, ['new', 'scheduled'], true)) {
            $status = 'scheduled';
        }

        DB::table('service_jobs')->where('id', $id)->update(['technician_id' => $technicianId, 'scheduled_at' => $scheduledAt, 'status' => $status, 'updated_at' => now()]);
        $this->event($companyId, $id, 'assigned', $job->status, $status, $technicianId === null ? 'Unassigned' : null, $userId);
    }

    /**
     * Moves the job one step. `$onlyTechnician` limits a technician to their own jobs.
     *
     * @throws ValidationException
     */
    public function move(int $companyId, int $id, string $to, ?string $note, ?int $userId, ?int $onlyTechnician = null): void
    {
        $job = $this->find($companyId, $id);

        if ($onlyTechnician !== null && (int) $job->technician_id !== $onlyTechnician) {
            abort(404);
        }

        if (! in_array($to, self::FLOW[$job->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => ['A job that is '.str_replace('_', ' ', $job->status).' cannot move to '.str_replace('_', ' ', $to).'.']]);
        }

        if ($to === 'scheduled' && ($job->technician_id === null || $job->scheduled_at === null)) {
            throw ValidationException::withMessages(['status' => ['Assign a technician and a time before scheduling.']]);
        }

        $changes = ['status' => $to, 'updated_at' => now()];

        if ($to === 'in_progress' && $job->started_at === null) {
            $changes['started_at'] = now();
        }

        if ($to === 'completed') {
            $changes['completed_at'] = now();
        }

        DB::table('service_jobs')->where('id', $id)->update($changes);
        $this->event($companyId, $id, 'status', $job->status, $to, $note, $userId);
    }

    /**
     * @throws ValidationException
     */
    public function addPart(int $companyId, int $id, int $productId, ?int $variationId, float $quantity, ?float $unitPrice, ?string $note, ?int $userId, ?int $onlyTechnician = null): int
    {
        $job = $this->find($companyId, $id);

        if ($onlyTechnician !== null && (int) $job->technician_id !== $onlyTechnician) {
            abort(404);
        }

        if (in_array($job->status, ['invoiced', 'closed', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => ['Parts cannot be added to an invoiced, closed or cancelled job.']]);
        }

        $product = Product::query()->where('company_id', $companyId)->where('active', 1)->find($productId);

        if ($product === null || $quantity <= 0) {
            throw ValidationException::withMessages(['product_id' => ['Choose an active product of this company and a quantity above zero.']]);
        }

        $variation = $variationId !== null
            ? ProductDetail::query()->where('product_id', $product->id)->find($variationId)
            : ProductDetail::query()->where('product_id', $product->id)->orderBy('id')->first();

        if ($variation === null) {
            throw ValidationException::withMessages(['variation_id' => ['That variation does not belong to the product.']]);
        }

        $partId = (int) DB::table('service_job_parts')->insertGetId([
            'company_id' => $companyId, 'service_job_id' => $id, 'product_id' => $product->id, 'variation_id' => $variation->id, 'quantity' => round($quantity, 4),
            'unit_price' => $unitPrice === null ? null : round(max(0.0, $unitPrice), 2), 'note' => $note !== null ? mb_substr(strip_tags($note), 0, 200) : null, 'added_by' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->event($companyId, $id, 'part', null, null, $product->name.' x '.round($quantity, 4), $userId);

        return $partId;
    }

    public function removePart(int $companyId, int $id, int $partId, ?int $onlyTechnician = null): void
    {
        $job = $this->find($companyId, $id);

        if ($onlyTechnician !== null && (int) $job->technician_id !== $onlyTechnician) {
            abort(404);
        }

        if (in_array($job->status, ['invoiced', 'closed', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => ['Parts cannot be removed from an invoiced, closed or cancelled job.']]);
        }

        $affected = DB::table('service_job_parts')->where('company_id', $companyId)->where('service_job_id', $id)->where('id', $partId)->delete();
        abort_if($affected === 0, 404);
    }

    /**
     * Makes the draft invoice: the parts at their price (the sale price list when a part has none) and one service line for the
     * charge. A warranty job is invoiced at zero so the parts still go out of stock when the sale is finalised.
     *
     * @return int the sale id
     *
     * @throws ValidationException
     */
    public function invoice(int $companyId, int $id, ?int $userId): int
    {
        $job = $this->find($companyId, $id);

        if ($job->status !== 'completed') {
            throw ValidationException::withMessages(['status' => ['Complete the job before invoicing it.']]);
        }

        if ($job->contact_id === null) {
            throw ValidationException::withMessages(['contact_id' => ['Choose the customer of the job before invoicing it.']]);
        }

        $rows = [];

        foreach (DB::table('service_job_parts')->where('service_job_id', $id)->orderBy('id')->get() as $part) {
            $rows[] = ['product_id' => (int) $part->product_id, 'variation_id' => (int) $part->variation_id, 'quantity' => (float) $part->quantity]
                + ($job->is_warranty ? ['unit_price' => 0] : ($part->unit_price !== null ? ['unit_price' => (float) $part->unit_price] : []));
        }

        $charge = $job->is_warranty ? 0.0 : (float) $job->service_charge;

        if ($charge > 0) {
            if ($job->service_product_id === null) {
                throw ValidationException::withMessages(['service_product_id' => ['Choose the service item the charge is invoiced under.']]);
            }

            $rows[] = ['product_id' => (int) $job->service_product_id, 'quantity' => 1, 'unit_price' => $charge];
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['lines' => ['There is nothing to invoice: add parts or a service charge.']]);
        }

        return (int) DB::transaction(function () use ($companyId, $job, $id, $rows, $userId): int {
            $built = $this->builder->lines($companyId, $job->branch_id === null ? null : (int) $job->branch_id, (int) $job->contact_id, $rows);
            $sale = $this->builder->create([
                'company_id' => $companyId, 'branch_id' => $job->branch_id, 'contact_id' => $job->contact_id, 'transaction_date' => now()->toDateString(),
                'note' => 'Service job '.$job->job_no.($job->is_warranty ? ' (warranty)' : ''),
            ], $built['lines'], $built['total'], 'draft');

            DB::table('service_jobs')->where('id', $id)->update(['sale_id' => $sale->id, 'status' => 'invoiced', 'updated_at' => now()]);
            $this->event($companyId, $id, 'invoiced', 'completed', 'invoiced', $sale->invoice_no, $userId);

            return (int) $sale->id;
        });
    }

    /**
     * Keeps the customer's signature (a PNG drawn on the screen) on the job.
     *
     * @throws ValidationException
     */
    public function sign(int $companyId, int $id, string $name, string $dataUrl, ?int $userId, ?int $onlyTechnician = null): void
    {
        $job = $this->find($companyId, $id);

        if ($onlyTechnician !== null && (int) $job->technician_id !== $onlyTechnician) {
            abort(404);
        }

        if (strlen($dataUrl) > 400000) {
            throw ValidationException::withMessages(['image' => ['The signature is too large.']]);
        }

        $decoded = Base64Upload::decode($dataUrl);

        if ($decoded === null || $decoded['mime'] !== 'image/png') {
            throw ValidationException::withMessages(['image' => ['The signature must be a PNG image.']]);
        }

        Company::query()->find($companyId)?->assertCanAddStorageBytes(strlen($decoded['binary']));
        $path = 'service-signatures/'.$companyId.'/'.$id.'.png';
        Storage::disk(self::SIGNATURE_DISK)->put($path, $decoded['binary']);
        DB::table('service_jobs')->where('id', $id)->update(['signature_path' => $path, 'signed_by' => mb_substr(strip_tags($name), 0, 100), 'signed_at' => now(), 'updated_at' => now()]);
        $this->event($companyId, $id, 'signed', null, null, $name, $userId);
    }

    /**
     * The service report of a job as one standalone HTML page (prints on A4, the browser saves it as PDF): customer, work done, parts
     * used, times and the signature. Everything is escaped and the page has no script.
     */
    public function report(int $companyId, int $id): string
    {
        $job = $this->find($companyId, $id);
        $e = fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $company = Company::query()->with('companySetting')->find($companyId);
        $customer = $job->contact_id === null ? null : DB::table('contacts')->where('company_id', $companyId)->where('id', $job->contact_id)->first();
        $customerName = $customer === null ? '' : trim((string) ($customer->business_name ?: $customer->first_name.' '.$customer->last_name));
        $user = $job->technician_id === null ? null : DB::table('users')->where('company_id', $companyId)->where('id', $job->technician_id)->first(['first_name', 'last_name']);
        $technician = $user === null ? '' : trim($user->first_name.' '.$user->last_name);
        $parts = DB::table('service_job_parts as p')->join('products as pr', 'pr.id', '=', 'p.product_id')->where('p.service_job_id', $id)->orderBy('p.id')->get(['pr.name', 'p.quantity', 'p.note']);
        $signature = '';

        if ($job->signature_path !== null && Storage::disk(self::SIGNATURE_DISK)->exists($job->signature_path)) {
            $signature = '<img alt="Customer signature" style="max-height:30mm" src="data:image/png;base64,'.base64_encode((string) Storage::disk(self::SIGNATURE_DISK)->get($job->signature_path)).'">';
        }

        $rows = '';

        foreach ($parts as $part) {
            $rows .= '<tr><td>'.$e($part->name).'</td><td style="text-align:end">'.$e(rtrim(rtrim(number_format((float) $part->quantity, 4, '.', ''), '0'), '.')).'</td><td>'.$e($part->note).'</td></tr>';
        }

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Service report '.$e($job->job_no).'</title><style>'
            .'@page{size:A4;margin:12mm}body{font-family:Arial,Helvetica,sans-serif;font-size:10pt;margin:0}table{width:100%;border-collapse:collapse;margin:3mm 0}td,th{padding:1.5mm;border-bottom:1px solid #ccc;text-align:start}h1,h2{margin:0}.muted{opacity:.7}'
            .'</style></head><body><h2>'.$e($company?->companySetting?->business_name ?: $company?->name).'</h2><h1>Service report '.$e($job->job_no).'</h1>'
            .'<table><tr><td>Customer</td><td>'.$e($customerName).'</td><td>Type</td><td>'.$e($job->type).'</td></tr>'
            .'<tr><td>Item</td><td>'.$e($job->item_description).' '.$e($job->serial_no).'</td><td>Technician</td><td>'.$e($technician).'</td></tr>'
            .'<tr><td>Started</td><td>'.$e($job->started_at).'</td><td>Completed</td><td>'.$e($job->completed_at).'</td></tr>'
            .'<tr><td>Site</td><td colspan="3">'.$e($job->site_address).'</td></tr></table>'
            .'<h2 style="font-size:11pt">Problem</h2><p>'.nl2br($e($job->title."\n".$job->description)).'</p>'
            .'<h2 style="font-size:11pt">Work done</h2><p>'.nl2br($e($job->report_notes)).'</p>'
            .'<h2 style="font-size:11pt">Parts used</h2><table><thead><tr><th>Part</th><th style="text-align:end">Quantity</th><th>Note</th></tr></thead><tbody>'.($rows === '' ? '<tr><td colspan="3" class="muted">None</td></tr>' : $rows).'</tbody></table>'
            .'<h2 style="font-size:11pt">Customer signature</h2><p>'.($signature !== '' ? $signature.'<br>'.$e($job->signed_by).' - '.$e($job->signed_at) : '<span class="muted">Not signed</span>').'</p>'
            .'</body></html>';
    }

    public function find(int $companyId, int $id): object
    {
        $job = DB::table('service_jobs')->where('company_id', $companyId)->where('id', $id)->first();
        abort_if($job === null, 404);

        return $job;
    }

    // ------------------------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function clean(int $companyId, array $data): array
    {
        $type = (string) ($data['type'] ?? 'repair');

        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['type' => ['Choose repair, field or installation.']]);
        }

        $own = fn (string $table, mixed $key, string $field, bool $soft = false): ?int => $key === null || $key === '' ? null : (
            DB::table($table)->where('company_id', $companyId)->where('id', (int) $key)->when($soft, fn ($q) => $q->whereNull('deleted_at'))->exists()
                ? (int) $key : throw ValidationException::withMessages([$field => ['Choose a record of this company.']])
        );
        $title = trim(strip_tags((string) ($data['title'] ?? '')));

        if ($title === '') {
            throw ValidationException::withMessages(['title' => ['Enter what the job is about.']]);
        }

        $claimId = $own('warranty_claims', $data['warranty_claim_id'] ?? null, 'warranty_claim_id');

        return [
            'type' => $type, 'contact_id' => $own('contacts', $data['contact_id'] ?? null, 'contact_id', true), 'title' => mb_substr($title, 0, 150),
            'description' => isset($data['description']) ? mb_substr(strip_tags((string) $data['description']), 0, 2000) : null,
            'item_description' => isset($data['item_description']) ? mb_substr(strip_tags((string) $data['item_description']), 0, 200) : null,
            'serial_no' => isset($data['serial_no']) ? mb_substr(strip_tags((string) $data['serial_no']), 0, 100) : null, 'warranty_claim_id' => $claimId,
            'is_warranty' => (bool) ($data['is_warranty'] ?? false) || $claimId !== null && (bool) DB::table('warranty_claims')->where('id', $claimId)->value('in_warranty'),
            'technician_id' => $own('users', $data['technician_id'] ?? null, 'technician_id'), 'priority' => in_array($data['priority'] ?? 'normal', ['low', 'normal', 'high', 'urgent'], true) ? (string) ($data['priority'] ?? 'normal') : 'normal',
            'scheduled_at' => ! empty($data['scheduled_at']) ? date('Y-m-d H:i:s', (int) strtotime((string) $data['scheduled_at'])) : null,
            'site_address' => isset($data['site_address']) ? mb_substr(strip_tags((string) $data['site_address']), 0, 255) : null,
            'service_charge' => max(0.0, round((float) ($data['service_charge'] ?? 0), 2)),
            'service_product_id' => $own('products', $data['service_product_id'] ?? null, 'service_product_id', true),
            'report_notes' => isset($data['report_notes']) ? mb_substr(strip_tags((string) $data['report_notes']), 0, 4000) : null,
        ];
    }

    private function event(int $companyId, int $id, string $event, ?string $from, ?string $to, ?string $note, ?int $userId): void
    {
        DB::table('service_job_events')->insert([
            'company_id' => $companyId, 'service_job_id' => $id, 'event' => $event, 'from_status' => $from, 'to_status' => $to, 'note' => $note !== null ? mb_substr($note, 0, 300) : null,
            'user_id' => $userId, 'created_at' => now(),
        ]);
    }
}
