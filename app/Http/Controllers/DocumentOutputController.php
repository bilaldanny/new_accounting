<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Transaction;
use App\Services\Documents\CustomFields;
use App\Services\Documents\DocumentBlocks;
use App\Services\Documents\DocumentData;
use App\Services\Documents\DocumentDelivery;
use App\Services\Documents\DocumentRenderer;
use App\Services\Documents\DocumentTemplates;
use App\Services\Documents\MessageTemplates;
use App\Services\Documents\TemplateCode\TemplateCodeRenderer;
use App\Services\Documents\XlsxWriter;
use App\Support\Base64Upload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What a user does with one document: print it with a template (HTML that prints on A4, A5 or a thermal roll, also what a browser
 * saves as PDF), export its lines as CSV or Excel, send it by email / WhatsApp / SMS / link, attach files to it and fill its custom
 * fields. The document is found through the same company and branch visibility as its own pages, and the permission is the one of
 * its view (or edit, for changes) page: sales `/sell/:id/...`, purchases `/purchase/:id/...`, sales returns `/sell/return/:id/...`.
 */
class DocumentOutputController extends Controller
{
    private const PERMISSION_BASE = [
        Transaction::TYPE_SELL => '/sell',
        Transaction::TYPE_PURCHASE => '/purchase',
        Transaction::TYPE_SELL_RETURN => '/sell/return',
    ];

    private const ATTACHMENT_DISK = 'local';

    private const MAX_KILOBYTES = 5120;

    public function render(Request $request, int $id): Response
    {
        $request->validate(['template_id' => 'nullable|integer', 'language' => ['nullable', Rule::in(DocumentBlocks::LANGUAGES)], 'paper' => ['nullable', Rule::in(DocumentBlocks::PAPERS)], 'doc_type' => ['nullable', Rule::in(DocumentBlocks::DOC_TYPES)]]);
        $transaction = $this->document($id, 'view');
        $docType = $request->input('doc_type', DocumentData::typeOf($transaction));
        $template = app(DocumentTemplates::class)->resolve((int) $transaction->getRawOriginal('company_id'), $transaction->branch_id === null ? null : (int) $transaction->branch_id, $docType, $request->input('language'), $request->filled('template_id') ? $request->integer('template_id') : null);

        abort_if($request->filled('template_id') && $template['id'] === null, 404);

        if ($request->filled('paper')) {
            $template['paper'] = $request->input('paper');
        }

        $data = app(DocumentData::class)->forTransaction($transaction);
        $data['doc']['type'] = $docType;
        $html = app(DocumentRenderer::class)->render($template, $data, $request->boolean('print'));

        if (($template['mode'] ?? 'blocks') === 'code') {
            return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8'] + TemplateCodeRenderer::securityHeaders());
        }

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; img-src * data:; style-src 'unsafe-inline'; script-src 'unsafe-inline'"]);
    }

    public function export(Request $request, int $id): StreamedResponse
    {
        $request->validate(['format' => ['required', Rule::in(['csv', 'xlsx'])]]);
        $transaction = $this->document($id, 'view');
        $data = app(DocumentData::class)->forTransaction($transaction);
        $rows = [['#', 'SKU', 'Item', 'Qty', 'Unit', 'Price', 'Discount %', 'Tax', 'Amount']];

        foreach ($data['lines'] as $line) {
            $rows[] = [$line['sr'], $line['sku'], $line['name'], (float) $line['qty'], $line['unit'], (float) $line['price'], (float) $line['discount'], (float) $line['tax'], (float) $line['amount']];
        }

        $rows[] = [];

        foreach (['subtotal' => 'Subtotal', 'discount' => 'Discount', 'tax' => 'Tax', 'shipping' => 'Shipping', 'total' => 'Total', 'paid' => 'Paid', 'balance' => 'Balance'] as $key => $label) {
            $rows[] = ['', '', '', '', '', '', '', $label, (float) $data['totals'][$key]];
        }

        $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $data['doc']['number']) ?: 'document';

        if ($request->input('format') === 'xlsx') {
            $content = XlsxWriter::build($this->neutralise($rows), 'Document');

            return response()->streamDownload(function () use ($content): void {
                echo $content;
            }, $name.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            foreach ($this->neutralise($rows) as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $name.'.csv', ['Content-Type' => 'text/csv']);
    }

    public function send(Request $request, int $id, DocumentDelivery $delivery): JsonResponse
    {
        $transaction = $this->document($id, 'view');
        abort_unless($transaction->type === Transaction::TYPE_SELL && ! in_array($transaction->status, Transaction::UNPOSTED_SELL_STATUSES, true), 422, 'Only a posted sale can be sent.');

        $data = $request->validate([
            'channel' => ['required', Rule::in(['link', ...MessageTemplates::CHANNELS])], 'recipient' => 'nullable|string|max:190', 'language' => ['nullable', Rule::in(DocumentBlocks::LANGUAGES)],
            'message_template_id' => 'nullable|integer', 'event' => ['nullable', Rule::in(MessageTemplates::EVENTS)],
        ]);

        if ($data['channel'] === 'email' && ! empty($data['recipient']) && ! filter_var($data['recipient'], FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['recipient' => ['Enter a valid email address.']]);
        }

        $result = $delivery->send($transaction, $data['channel'], $data['recipient'] ?? null, $data['event'] ?? 'invoice_created', $data['language'] ?? 'en', $data['message_template_id'] ?? null, Auth::id());

        return response()->json(['message' => match ($result['status']) {
            'sent' => 'Sent.', 'not_configured' => (string) $result['error'], 'failed' => 'It could not be sent: '.$result['error'], default => 'Link created.',
        }, 'data' => $result], $result['status'] === 'failed' ? 502 : 201);
    }

    // ---------------------------------------------------------------- attachments

    public function attachments(int $id): JsonResponse
    {
        $transaction = $this->document($id, 'view');

        return response()->json(['data' => DB::table('document_attachments')->where('company_id', $transaction->getRawOriginal('company_id'))->where('transaction_id', $transaction->id)->orderByDesc('id')->get()
            ->map(fn (object $row): array => $this->presentAttachment($row))->all(), 'max_kilobytes' => self::MAX_KILOBYTES]);
    }

    public function attach(Request $request, int $id): JsonResponse
    {
        $transaction = $this->document($id, 'edit');
        $request->validate(['file' => 'bail|required|file|max:'.self::MAX_KILOBYTES, 'description' => 'nullable|string|max:255']);
        $file = $request->file('file');

        $mime = (string) $file->getMimeType();
        $extra = ['text/plain' => ['txt', 'csv'], 'text/csv' => ['csv'], 'application/msword' => ['doc'], 'application/vnd.ms-excel' => ['xls', 'csv'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'], 'application/zip' => ['docx', 'xlsx']];
        $client = strtolower($file->getClientOriginalExtension());
        $extension = Base64Upload::extensionForMime($mime) ?? (in_array($client, $extra[$mime] ?? [], true) ? $client : null);

        if ($extension === null) {
            throw ValidationException::withMessages(['file' => ['This file type is not allowed. Upload a PDF, image, Word, Excel, CSV or text file.']]);
        }

        $companyId = (int) $transaction->getRawOriginal('company_id');
        Company::query()->find($companyId)?->assertCanAddStorageBytes((int) $file->getSize());
        $path = $file->storeAs('document-attachments/'.$companyId.'/'.$transaction->id, Str::ulid().'.'.$extension, self::ATTACHMENT_DISK);

        $rowId = DB::table('document_attachments')->insertGetId([
            'company_id' => $companyId, 'branch_id' => $transaction->branch_id, 'transaction_id' => $transaction->id, 'file_name' => mb_substr(basename($file->getClientOriginalName()), 0, 180),
            'path' => (string) $path, 'ext' => $extension, 'mime' => $mime, 'size' => (int) $file->getSize(), 'description' => $request->filled('description') ? mb_substr((string) $request->input('description'), 0, 255) : null,
            'uploaded_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Document uploaded', 'data' => $this->presentAttachment(DB::table('document_attachments')->find($rowId))], 201);
    }

    public function downloadAttachment(int $id, int $attachmentId): StreamedResponse
    {
        $transaction = $this->document($id, 'view');
        $row = DB::table('document_attachments')->where('company_id', $transaction->getRawOriginal('company_id'))->where('transaction_id', $transaction->id)->where('id', $attachmentId)->first();
        abort_if($row === null || ! Storage::disk(self::ATTACHMENT_DISK)->exists($row->path), 404);

        return Storage::disk(self::ATTACHMENT_DISK)->download($row->path, $row->file_name);
    }

    public function deleteAttachment(int $id, int $attachmentId): JsonResponse
    {
        $transaction = $this->document($id, 'edit');
        $row = DB::table('document_attachments')->where('company_id', $transaction->getRawOriginal('company_id'))->where('transaction_id', $transaction->id)->where('id', $attachmentId)->first();
        abort_if($row === null, 404);

        Storage::disk(self::ATTACHMENT_DISK)->delete($row->path);
        DB::table('document_attachments')->where('id', $row->id)->delete();

        return response()->json(['message' => 'Document deleted']);
    }

    // ---------------------------------------------------------------- custom field values

    public function customValues(int $id, CustomFields $fields): JsonResponse
    {
        $transaction = $this->document($id, 'view');

        return response()->json(['data' => $fields->valuesFor((int) $transaction->getRawOriginal('company_id'), $this->entityOf($transaction), (int) $transaction->id)]);
    }

    public function setCustomValues(Request $request, int $id, CustomFields $fields): JsonResponse
    {
        $transaction = $this->document($id, 'edit');
        $data = $request->validate(['values' => 'required|array|max:100']);

        $fields->setValues((int) $transaction->getRawOriginal('company_id'), $this->entityOf($transaction), (int) $transaction->id, $data['values']);

        return response()->json(['message' => 'Successfully Saved', 'data' => $fields->valuesFor((int) $transaction->getRawOriginal('company_id'), $this->entityOf($transaction), (int) $transaction->id)]);
    }

    private function document(int $id, string $action): Transaction
    {
        $transaction = Transaction::query()->visibleToCurrentUser()->whereIn('type', array_keys(self::PERMISSION_BASE))->find($id);
        abort_if($transaction === null, 404);

        $this->authorizeMenuPermission(self::PERMISSION_BASE[$transaction->type].'/:id/'.$action);

        return $transaction;
    }

    private function entityOf(Transaction $transaction): string
    {
        abort_unless(in_array($transaction->type, [Transaction::TYPE_SELL, Transaction::TYPE_PURCHASE], true), 422, 'Custom fields are available on sales and purchases.');

        return $transaction->type === Transaction::TYPE_SELL ? 'sale' : 'purchase';
    }

    /**
     * A text that starts like a spreadsheet formula is prefixed so a spreadsheet shows it as text.
     *
     * @param  list<list<scalar|null>>  $rows
     * @return list<list<scalar|null>>
     */
    private function neutralise(array $rows): array
    {
        return array_map(fn (array $row): array => array_map(
            fn ($cell) => is_string($cell) && $cell !== '' && in_array($cell[0], ['=', '+', '-', '@'], true) && ! is_numeric($cell) ? "'".$cell : $cell,
            $row,
        ), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAttachment(object $row): array
    {
        return ['id' => (int) $row->id, 'file_name' => $row->file_name, 'ext' => $row->ext, 'size' => (int) $row->size, 'description' => $row->description, 'created_at' => $row->created_at];
    }
}
