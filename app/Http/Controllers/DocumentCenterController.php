<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Transaction;
use App\Services\Documents\CustomFields;
use App\Services\Documents\DocumentBlocks;
use App\Services\Documents\DocumentData;
use App\Services\Documents\DocumentReminders;
use App\Services\Documents\DocumentRenderer;
use App\Services\Documents\DocumentTemplates;
use App\Services\Documents\MessageTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The Document Center: print templates (block design, branding, paper, language, one default per branch and type), message templates
 * for email / SMS / WhatsApp, due and overdue reminder rules with their log, the instant-delivery setting and custom field
 * definitions. Reading needs `/documentcenter`; every change needs `/documentcenter/manage`. Company and branch always come from the
 * signed-in user (the superadmin names a company); a record is only found inside that company.
 */
class DocumentCenterController extends Controller
{
    use ScopesToCompany;

    public function meta(): JsonResponse
    {
        $this->authorizeMenuPermission('/documentcenter');

        return response()->json(['data' => [
            'doc_types' => DocumentBlocks::DOC_TYPES, 'languages' => DocumentBlocks::LANGUAGES, 'papers' => DocumentBlocks::PAPERS, 'fonts' => array_keys(DocumentBlocks::FONTS),
            'line_columns' => DocumentBlocks::LINE_COLUMNS, 'blocks' => collect(DocumentBlocks::SCHEMA)->map(fn (array $props): array => array_keys($props))->all(),
            'events' => MessageTemplates::EVENTS, 'channels' => MessageTemplates::CHANNELS, 'placeholders' => MessageTemplates::PLACEHOLDERS,
            'triggers' => DocumentReminders::TRIGGERS, 'entities' => CustomFields::ENTITIES, 'field_types' => CustomFields::TYPES,
            'defaults' => collect(DocumentBlocks::DOC_TYPES)->mapWithKeys(fn (string $type): array => [$type => DocumentBlocks::defaults($type)])->all(),
            'default_branding' => DocumentBlocks::defaultBranding(),
        ]]);
    }

    // ---------------------------------------------------------------- print templates

    public function templates(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/documentcenter');
        $companyId = $this->scopeCompanyId($request);

        return response()->json(['data' => DB::table('document_templates')->where('company_id', $companyId)->whereNull('deleted_at')->orderBy('doc_type')->orderBy('name')->get()
            ->map(fn (object $row): array => $this->presentTemplate($row))->all()]);
    }

    public function saveTemplate(Request $request, ?int $id = null): JsonResponse
    {
        $this->authorizeMenuPermission('/documentcenter/manage');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate([
            'name' => 'required|string|min:2|max:120', 'doc_type' => ['required', Rule::in(DocumentBlocks::DOC_TYPES)], 'language' => ['nullable', Rule::in(DocumentBlocks::LANGUAGES)],
            'paper' => ['nullable', Rule::in(DocumentBlocks::PAPERS)], 'branch_id' => 'nullable|integer', 'blocks' => 'required|array|min:1|max:'.DocumentBlocks::MAX_BLOCKS,
            'branding' => 'nullable|array', 'is_default' => 'nullable|boolean', 'is_active' => 'nullable|boolean', 'company_id' => 'nullable|integer',
        ]);

        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;

        if ($branchId !== null && ! DB::table('branches')->where('company_id', $companyId)->where('id', $branchId)->exists()) {
            throw ValidationException::withMessages(['branch_id' => ['Choose a branch of this company.']]);
        }

        $row = app(DocumentTemplates::class)->prepare($data);

        if ($id === null) {
            $id = (int) DB::table('document_templates')->insertGetId($row + [
                'company_id' => $companyId, 'branch_id' => $branchId, 'is_default' => false, 'is_active' => (bool) ($data['is_active'] ?? true),
                'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            $exists = DB::table('document_templates')->where('company_id', $companyId)->whereNull('deleted_at')->where('id', $id)->exists();
            abort_unless($exists, 404);
            DB::table('document_templates')->where('id', $id)->update($row + ['branch_id' => $branchId, 'is_active' => (bool) ($data['is_active'] ?? true), 'updated_at' => now()]);
        }

        if ((bool) ($data['is_default'] ?? false)) {
            app(DocumentTemplates::class)->makeDefault($id);
        }

        return response()->json(['message' => 'Successfully Saved', 'data' => $this->presentTemplate(DB::table('document_templates')->find($id))], 201);
    }

    public function makeDefault(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/documentcenter/manage');
        $exists = DB::table('document_templates')->where('company_id', $this->scopeCompanyId($request))->whereNull('deleted_at')->where('id', $id)->exists();
        abort_unless($exists, 404);

        app(DocumentTemplates::class)->makeDefault($id);

        return response()->json(['message' => 'Successfully Updated']);
    }

    public function deleteTemplate(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/documentcenter/manage');
        $affected = DB::table('document_templates')->where('company_id', $this->scopeCompanyId($request))->whereNull('deleted_at')->where('id', $id)->update(['deleted_at' => now(), 'is_default' => false]);
        abort_if($affected === 0, 404);

        return response()->json(['message' => 'Successfully Deleted']);
    }

    /**
     * Renders a design on a real document of the company (or a sample) without saving anything.
     */
    public function preview(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/documentcenter');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate([
            'doc_type' => ['required', Rule::in(DocumentBlocks::DOC_TYPES)], 'language' => ['nullable', Rule::in(DocumentBlocks::LANGUAGES)], 'paper' => ['nullable', Rule::in(DocumentBlocks::PAPERS)],
            'blocks' => 'required|array|min:1|max:'.DocumentBlocks::MAX_BLOCKS, 'branding' => 'nullable|array', 'transaction_id' => 'nullable|integer', 'company_id' => 'nullable|integer',
        ]);

        $transaction = null;

        if (! empty($data['transaction_id'])) {
            $transaction = Transaction::query()->visibleToCurrentUser()->whereIn('type', [Transaction::TYPE_SELL, Transaction::TYPE_PURCHASE, Transaction::TYPE_SELL_RETURN])
                ->where('company_id', $companyId)->find($data['transaction_id']);
            abort_if($transaction === null, 404);
        }

        $documentData = $transaction !== null ? app(DocumentData::class)->forTransaction($transaction) : $this->sample($data['doc_type']);
        $documentData['doc']['type'] = $data['doc_type'];
        $html = app(DocumentRenderer::class)->render([
            'blocks' => DocumentBlocks::normalise($data['blocks']), 'branding' => DocumentBlocks::normaliseBranding($data['branding'] ?? []),
            'paper' => $data['paper'] ?? 'a4', 'language' => $data['language'] ?? 'en',
        ], $documentData);

        return response()->json(['html' => $html]);
    }

    // ---------------------------------------------------------------- message templates

    public function messages(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/documentcenter');

        return response()->json(['data' => DB::table('message_templates')->where('company_id', $this->scopeCompanyId($request))->orderBy('event')->orderBy('channel')->orderBy('name')->get()]);
    }

    public function saveMessage(Request $request, ?int $id = null): JsonResponse
    {
        $this->authorizeMenuPermission('/documentcenter/manage');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate([
            'name' => 'required|string|min:2|max:120', 'event' => ['required', Rule::in(MessageTemplates::EVENTS)], 'channel' => ['required', Rule::in(MessageTemplates::CHANNELS)],
            'language' => ['nullable', Rule::in(DocumentBlocks::LANGUAGES)], 'subject' => 'nullable|string|max:200', 'body' => 'required|string|min:2|max:2000',
            'is_default' => 'nullable|boolean', 'is_active' => 'nullable|boolean', 'company_id' => 'nullable|integer',
        ]);

        $row = [
            'event' => $data['event'], 'channel' => $data['channel'], 'language' => $data['language'] ?? 'en', 'name' => DocumentBlocks::text($data['name'], 120),
            'subject' => $data['channel'] === 'email' ? DocumentBlocks::text($data['subject'] ?? '', 200) : null, 'body' => strip_tags($data['body']),
            'is_default' => (bool) ($data['is_default'] ?? false), 'is_active' => (bool) ($data['is_active'] ?? true), 'updated_at' => now(),
        ];

        if ($id === null) {
            $id = (int) DB::table('message_templates')->insertGetId($row + ['company_id' => $companyId, 'created_at' => now()]);
        } else {
            abort_unless(DB::table('message_templates')->where('company_id', $companyId)->where('id', $id)->exists(), 404);
            DB::table('message_templates')->where('id', $id)->update($row);
        }

        if ($row['is_default']) {
            DB::table('message_templates')->where('company_id', $companyId)->where('event', $row['event'])->where('channel', $row['channel'])->where('language', $row['language'])->where('id', '!=', $id)->update(['is_default' => false]);
        }

        return response()->json(['message' => 'Successfully Saved', 'data' => DB::table('message_templates')->find($id)], 201);
    }

    public function deleteMessage(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/documentcenter/manage');
        $companyId = $this->scopeCompanyId($request);
        abort_unless(DB::table('message_templates')->where('company_id', $companyId)->where('id', $id)->exists(), 404);

        DB::table('document_reminder_rules')->where('company_id', $companyId)->where('message_template_id', $id)->update(['message_template_id' => null]);
        DB::table('message_templates')->where('id', $id)->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    // ---------------------------------------------------------------- reminders

    public function reminders(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/documentcenter');
        $companyId = $this->scopeCompanyId($request);

        return response()->json([
            'data' => DB::table('document_reminder_rules')->where('company_id', $companyId)->orderBy('id')->get(),
            'log' => DB::table('document_reminder_log as l')->join('transactions as t', 't.id', '=', 'l.transaction_id')->where('l.company_id', $companyId)
                ->orderByDesc('l.id')->limit(50)->get(['l.id', 'l.rule_id', 'l.sent_on', 'l.channel', 'l.recipient', 'l.status', 'l.error', 't.invoice_no']),
        ]);
    }

    public function saveReminder(Request $request, ?int $id = null): JsonResponse
    {
        $this->authorizeMenuPermission('/documentcenter/manage');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate([
            'name' => 'required|string|min:2|max:120', 'trigger' => ['required', Rule::in(DocumentReminders::TRIGGERS)], 'days' => 'required|integer|min:0|max:365',
            'repeat_every_days' => 'nullable|integer|min:1|max:90', 'max_sends' => 'nullable|integer|min:1|max:12', 'channel' => ['required', Rule::in(MessageTemplates::CHANNELS)],
            'language' => ['nullable', Rule::in(DocumentBlocks::LANGUAGES)], 'message_template_id' => 'nullable|integer', 'is_active' => 'nullable|boolean', 'company_id' => 'nullable|integer',
        ]);

        if (! empty($data['message_template_id']) && ! DB::table('message_templates')->where('company_id', $companyId)->where('id', $data['message_template_id'])->exists()) {
            throw ValidationException::withMessages(['message_template_id' => ['Choose a message template of this company.']]);
        }

        $row = [
            'name' => DocumentBlocks::text($data['name'], 120), 'trigger' => $data['trigger'], 'days' => $data['trigger'] === 'on_due' ? 0 : (int) $data['days'],
            'repeat_every_days' => $data['trigger'] === 'after_due' ? ($data['repeat_every_days'] ?? null) : null, 'max_sends' => (int) ($data['max_sends'] ?? 1), 'channel' => $data['channel'],
            'language' => $data['language'] ?? 'en', 'message_template_id' => $data['message_template_id'] ?? null, 'is_active' => (bool) ($data['is_active'] ?? true), 'updated_at' => now(),
        ];

        if ($id === null) {
            $id = (int) DB::table('document_reminder_rules')->insertGetId($row + ['company_id' => $companyId, 'created_at' => now()]);
        } else {
            abort_unless(DB::table('document_reminder_rules')->where('company_id', $companyId)->where('id', $id)->exists(), 404);
            DB::table('document_reminder_rules')->where('id', $id)->update($row);
        }

        return response()->json(['message' => 'Successfully Saved', 'data' => DB::table('document_reminder_rules')->find($id)], 201);
    }

    public function deleteReminder(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/documentcenter/manage');
        $companyId = $this->scopeCompanyId($request);
        abort_unless(DB::table('document_reminder_rules')->where('company_id', $companyId)->where('id', $id)->exists(), 404);

        DB::table('document_reminder_rules')->where('id', $id)->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    public function runReminders(Request $request, DocumentReminders $reminders): JsonResponse
    {
        $this->authorizeMenuPermission('/documentcenter/manage');

        return response()->json(['data' => $reminders->runDue($this->scopeCompanyId($request))]);
    }

    // ---------------------------------------------------------------- instant delivery

    public function delivery(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/documentcenter');
        $row = DB::table('document_delivery_settings')->where('company_id', $this->scopeCompanyId($request))->first();

        return response()->json(['data' => ['auto_send_sale' => (bool) ($row->auto_send_sale ?? false), 'channel' => $row->channel ?? 'email', 'language' => $row->language ?? 'en']]);
    }

    public function saveDelivery(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/documentcenter/manage');
        $data = $request->validate([
            'auto_send_sale' => 'required|boolean', 'channel' => ['required', Rule::in(MessageTemplates::CHANNELS)], 'language' => ['nullable', Rule::in(DocumentBlocks::LANGUAGES)], 'company_id' => 'nullable|integer',
        ]);

        DB::table('document_delivery_settings')->updateOrInsert(
            ['company_id' => $this->scopeCompanyId($request)],
            ['auto_send_sale' => $data['auto_send_sale'], 'channel' => $data['channel'], 'language' => $data['language'] ?? 'en', 'updated_at' => now(), 'created_at' => now()],
        );

        return response()->json(['message' => 'Successfully Saved']);
    }

    // ---------------------------------------------------------------- custom field definitions

    public function fields(Request $request, CustomFields $fields): JsonResponse
    {
        $this->authorizeMenuPermission('/documentcenter');

        return response()->json(['data' => $fields->definitions($this->scopeCompanyId($request), $request->input('entity'))]);
    }

    public function saveField(Request $request, CustomFields $fields, ?int $id = null): JsonResponse
    {
        $this->authorizeMenuPermission('/documentcenter/manage');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate([
            'entity' => ['required', Rule::in(CustomFields::ENTITIES)], 'label' => 'required|string|min:2|max:120', 'type' => ['required', Rule::in(CustomFields::TYPES)],
            'field_key' => 'nullable|string|max:60', 'options' => 'nullable|array|max:50', 'is_required' => 'nullable|boolean', 'show_on_print' => 'nullable|boolean',
            'is_active' => 'nullable|boolean', 'sort_order' => 'nullable|integer|min:0|max:1000', 'company_id' => 'nullable|integer',
        ]);

        if ($id !== null) {
            abort_unless(DB::table('custom_field_definitions')->where('company_id', $companyId)->where('id', $id)->exists(), 404);
        }

        return response()->json(['message' => 'Successfully Saved', 'data' => $fields->save($companyId, $data, $id)], 201);
    }

    public function deleteField(Request $request, CustomFields $fields, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/documentcenter/manage');
        $companyId = $this->scopeCompanyId($request);
        abort_unless(DB::table('custom_field_definitions')->where('company_id', $companyId)->where('id', $id)->exists(), 404);

        $fields->delete($companyId, $id);

        return response()->json(['message' => 'Successfully Deleted']);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentTemplate(object $row): array
    {
        return [
            'id' => (int) $row->id, 'name' => $row->name, 'doc_type' => $row->doc_type, 'language' => $row->language, 'paper' => $row->paper, 'branch_id' => $row->branch_id,
            'is_default' => (bool) $row->is_default, 'is_active' => (bool) $row->is_active, 'blocks' => json_decode((string) $row->blocks, true),
            'branding' => json_decode((string) $row->branding, true) ?: DocumentBlocks::defaultBranding(),
        ];
    }

    /**
     * Sample data for a preview with no document picked.
     *
     * @return array<string, mixed>
     */
    private function sample(string $docType): array
    {
        return [
            'company' => ['name' => 'Your Company', 'address' => '12 Market Road, Lahore', 'phone' => '042-1234567', 'email' => 'accounts@example.com', 'logo_url' => null, 'ntn' => '1234567-8', 'strn' => '17-00-0000-001-37'],
            'doc' => ['id' => 0, 'type' => $docType, 'number' => 'INV-00001', 'date' => now()->toDateString(), 'due_date' => now()->addDays(15)->toDateString(), 'status' => 'final', 'note' => 'Sample note', 'reference' => ''],
            'party' => ['name' => 'Sample Customer', 'address' => '5 Garden Town', 'phone' => '0300-1234567', 'email' => 'customer@example.com', 'ntn' => '7654321-0', 'strn' => ''],
            'ship_to' => '', 'show_tax_numbers' => true, 'terms' => 'Goods once sold are not returnable.', 'footer_note' => 'Thank you for your business', 'fbr_number' => null, 'share_url' => url('/'),
            'lines' => [
                ['sr' => 1, 'sku' => 'A-100', 'name' => 'Sample item one', 'qty' => 2.0, 'unit' => 'pc', 'price' => 500.0, 'discount' => 0.0, 'tax' => 0.0, 'amount' => 1000.0],
                ['sr' => 2, 'sku' => 'B-200', 'name' => 'Sample item two', 'qty' => 1.0, 'unit' => 'pc', 'price' => 250.0, 'discount' => 10.0, 'tax' => 0.0, 'amount' => 225.0],
            ],
            'totals' => ['subtotal' => 1225.0, 'discount' => 25.0, 'tax' => 0.0, 'shipping' => 0.0, 'total' => 1200.0, 'paid' => 500.0, 'balance' => 700.0, 'previous_balance' => 0.0],
            'custom_fields' => [['label' => 'PO number', 'value' => 'PO-77']],
        ];
    }
}
