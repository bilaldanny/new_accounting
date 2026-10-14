<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Contact;
use App\Models\ReceiptShare;
use App\Models\Transaction;
use App\Services\Documents\DocumentData;
use App\Services\Documents\DocumentRenderer;
use App\Services\Documents\DocumentTemplates;
use App\Services\Documents\TemplateCode\TemplateCodeRenderer;
use App\Services\MessageDispatcher;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Digital receipts: a share link of a sale's receipt (a random token, valid 30 days, no sign-in), sent by email (through the app's
 * mail settings) or by WhatsApp / SMS (through the company's configured provider; with none configured the share is recorded as
 * `not_configured` and nothing is sent). The public page shows only the receipt of that one sale.
 */
class ReceiptShareController extends Controller
{
    public function store(Request $request, int $id, MessageDispatcher $messages): JsonResponse
    {
        $this->authorizeMenuPermission('/sell/:id/view');

        $data = $request->validate(['channel' => 'required|in:'.implode(',', ReceiptShare::CHANNELS), 'recipient' => 'nullable|string|max:190']);

        $sale = Transaction::query()->sells()->visibleToCurrentUser()->whereNotIn('status', Transaction::UNPOSTED_SELL_STATUSES)->findOrFail($id);
        $contact = $sale->contact_id === null ? null : Contact::query()->find($sale->contact_id);
        $recipient = trim((string) ($data['recipient'] ?? ''));

        if ($recipient === '' && $data['channel'] !== 'link') {
            $recipient = (string) ($data['channel'] === 'email' ? $contact?->email : $contact?->mobile);
        }

        if ($data['channel'] === 'email' && ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['recipient' => ['Enter a valid email address.']]);
        }

        if (in_array($data['channel'], ['whatsapp', 'sms'], true) && $recipient === '') {
            throw ValidationException::withMessages(['recipient' => ['Enter the phone number to send to.']]);
        }

        $share = ReceiptShare::query()->create([
            'company_id' => $sale->company_id, 'transaction_id' => $sale->id, 'token' => Str::random(40), 'channel' => $data['channel'],
            'recipient' => $recipient !== '' ? $recipient : null, 'status' => 'created', 'expires_at' => now()->addDays(30), 'created_by' => Auth::id(),
        ]);

        $url = route('receipt.public', ['token' => $share->token]);
        $company = Company::query()->where('id', $sale->company_id)->value('name');
        $text = "Your receipt from {$company}: invoice {$sale->invoice_no}, ".number_format((float) $sale->final_amount, 2).". View it here: {$url}";

        if ($data['channel'] === 'email') {
            try {
                Mail::raw($text, fn ($message) => $message->to($recipient)->subject("Receipt {$sale->invoice_no} from {$company}"));
                $share->update(['status' => 'sent', 'sent_at' => now()]);
            } catch (Throwable $e) {
                $share->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 400)]);
            }
        } elseif ($data['channel'] !== 'link') {
            $result = $messages->send((int) $sale->company_id, $data['channel'], $recipient, $text);
            $share->update(['status' => $result['status'], 'error' => $result['error'], 'sent_at' => $result['status'] === 'sent' ? now() : null]);
        } else {
            $share->update(['status' => 'created']);
        }

        return response()->json(['message' => match ($share->status) {
            'sent' => 'Receipt sent.',
            'not_configured' => (string) $share->error,
            'failed' => 'The receipt could not be sent: '.$share->error,
            default => 'Link created.',
        }, 'data' => $share->fresh()->only(['id', 'channel', 'recipient', 'status', 'error', 'expires_at']) + ['url' => $url]], $share->status === 'failed' ? 502 : 201);
    }

    public function index(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/sell/:id/view');

        $sale = Transaction::query()->sells()->visibleToCurrentUser()->findOrFail($id);

        return response()->json(['data' => ReceiptShare::query()->where('transaction_id', $sale->id)->orderByDesc('id')->get(['id', 'channel', 'recipient', 'status', 'error', 'sent_at', 'expires_at', 'created_at'])]);
    }

    /**
     * The public receipt page behind a share link.
     */
    public function show(string $token): View|Response
    {
        $share = ReceiptShare::query()->where('token', $token)->first();

        abort_if($share === null || ($share->expires_at !== null && $share->expires_at->isPast()), 404);

        $sale = Transaction::query()->withTrashed()->find($share->transaction_id);
        abort_if($sale === null || $sale->trashed(), 404);

        // A company that made a default receipt template in the Document Center prints its public receipt with it;
        // without one the page below is exactly what it always was.
        if (DocumentTemplates::available() && DB::table('document_templates')->where('company_id', $sale->company_id)->where('doc_type', 'receipt')->where('is_default', true)->where('is_active', true)->whereNull('deleted_at')->exists()) {
            $template = app(DocumentTemplates::class)->resolve((int) $sale->getRawOriginal('company_id'), $sale->branch_id === null ? null : (int) $sale->branch_id, 'receipt');
            $data = app(DocumentData::class)->forTransaction($sale, route('receipt.public', ['token' => $token]));
            $data['doc']['type'] = 'receipt';

            return response(app(DocumentRenderer::class)->render($template, $data), 200, ['Content-Type' => 'text/html; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff'] + (($template['mode'] ?? 'blocks') === 'code' ? TemplateCodeRenderer::securityHeaders() : []));
        }

        $lines = DB::table('sell_lines')
            ->leftJoin('products', 'products.id', '=', 'sell_lines.product_id')
            ->where('sell_lines.transaction_id', $sale->id)
            ->get(['products.name', 'sell_lines.quantity', 'sell_lines.unit_price', 'sell_lines.discount_percent', 'sell_lines.subtotal']);

        return view('receipt-public', [
            'company' => Company::query()->find($sale->company_id),
            'sale' => $sale,
            'customer' => $sale->contact_id === null ? null : Contact::query()->find($sale->contact_id),
            'lines' => $lines,
            'paid' => (float) DB::table('payments')->where('transaction_id', $sale->id)->where('is_return', false)->sum('amount'),
        ]);
    }
}
