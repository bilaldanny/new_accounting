<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Payment;
use App\Models\SubscriptionInvoice;
use App\Models\Transaction;
use App\Services\Integrations\ApiDocGenerator;
use App\Services\Integrations\CourierAdapter;
use App\Services\Integrations\HttpJsonBankFeed;
use App\Services\Integrations\LeopardsAdapter;
use App\Services\Integrations\PaymentGateway;
use App\Services\Integrations\PostExAdapter;
use App\Services\Integrations\StripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Integrations of a company: courier, payment gateway and bank feed accounts (credentials kept encrypted, never returned),
 * booking and tracking a parcel, a hosted checkout for an invoice, the signed gateway webhook, and the generated API reference.
 * None of these has been exercised against a live account (see docs/closeout-wave3.md).
 */
class IntegrationsController extends Controller
{
    use ScopesToCompany;

    public const PROVIDERS = ['courier' => ['postex', 'leopards'], 'gateway' => ['stripe'], 'bankfeed' => ['http_json']];

    public function __construct(private readonly ApiDocGenerator $docs) {}

    public function accounts(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/integrations');

        $rows = DB::table('integration_accounts')->where('company_id', $this->scopeCompanyId($request))->orderBy('kind')->orderBy('provider')->get()
            ->map(fn (object $r): array => [
                'id' => $r->id, 'kind' => $r->kind, 'provider' => $r->provider, 'is_active' => (bool) $r->is_active, 'options' => json_decode((string) $r->options, true),
                'has_credentials' => filled($r->credentials), 'last_used_at' => $r->last_used_at,
            ])->all();

        return response()->json(['data' => $rows, 'providers' => self::PROVIDERS]);
    }

    public function saveAccount(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/integrations/manage');

        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(self::PROVIDERS))], 'provider' => 'required|string|max:30', 'credentials' => 'nullable|array',
            'options' => 'nullable|array', 'is_active' => 'nullable|boolean',
        ]);

        if (! in_array($data['provider'], self::PROVIDERS[$data['kind']], true)) {
            throw ValidationException::withMessages(['provider' => ['This provider is not available for '.$data['kind'].'.']]);
        }

        $companyId = $this->scopeCompanyId($request);
        $existing = DB::table('integration_accounts')->where('company_id', $companyId)->where('kind', $data['kind'])->where('provider', $data['provider'])->first();
        $row = ['options' => json_encode($data['options'] ?? []), 'is_active' => (bool) ($data['is_active'] ?? false), 'updated_at' => now()];

        if (! empty($data['credentials'])) {
            $row['credentials'] = Crypt::encryptString(json_encode($data['credentials']));
        }

        if ($existing === null) {
            DB::table('integration_accounts')->insert($row + ['company_id' => $companyId, 'kind' => $data['kind'], 'provider' => $data['provider'], 'created_at' => now()]);
        } else {
            DB::table('integration_accounts')->where('id', $existing->id)->update($row);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function courierBook(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/dispatch');

        $data = $request->validate([
            'provider' => ['required', Rule::in(self::PROVIDERS['courier'])], 'transaction_id' => 'nullable|integer',
            'consignee_name' => 'required|string|max:120', 'consignee_phone' => 'required|string|max:30', 'consignee_address' => 'required|string|max:300', 'city' => 'required|string|max:80',
            'cod_amount' => 'nullable|numeric|min:0', 'pieces' => 'nullable|integer|min:1|max:99', 'weight_grams' => 'nullable|integer|min:1', 'description' => 'nullable|string|max:200',
        ]);
        $companyId = $this->scopeCompanyId($request);

        if (! empty($data['transaction_id']) && ! Transaction::query()->where('company_id', $companyId)->whereKey($data['transaction_id'])->exists()) {
            throw ValidationException::withMessages(['transaction_id' => ['Choose a sale of this company.']]);
        }

        $account = $this->account($companyId, 'courier', $data['provider']);
        $reference = ! empty($data['transaction_id']) ? (string) Transaction::query()->whereKey($data['transaction_id'])->value('invoice_no') : 'ORD-'.now()->format('ymdHis');
        $result = $this->courier($data['provider'])->book($account['credentials'], $data + ['reference' => $reference]);

        if (! $result['ok']) {
            return response()->json(['message' => $result['error'], 'errors' => ['courier' => [$result['error']]]], 422);
        }

        $id = DB::table('courier_shipments')->insertGetId([
            'company_id' => $companyId, 'transaction_id' => $data['transaction_id'] ?? null, 'provider' => $data['provider'], 'tracking_no' => $result['tracking_no'],
            'status' => $result['status'], 'payload' => json_encode($data), 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('integration_accounts')->where('id', $account['id'])->update(['last_used_at' => now()]);

        return response()->json(['message' => 'Parcel booked', 'data' => ['id' => $id, 'tracking_no' => $result['tracking_no'], 'status' => $result['status']]], 201);
    }

    public function courierTrack(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/dispatch');

        $data = $request->validate(['tracking_no' => 'required|string|max:60']);
        $companyId = $this->scopeCompanyId($request);
        $shipment = DB::table('courier_shipments')->where('company_id', $companyId)->where('tracking_no', $data['tracking_no'])->first();
        abort_if($shipment === null, 404);

        $account = $this->account($companyId, 'courier', $shipment->provider);
        $result = $this->courier($shipment->provider)->track($account['credentials'], $data['tracking_no']);

        if ($result['ok']) {
            DB::table('courier_shipments')->where('id', $shipment->id)->update(['status' => mb_substr((string) $result['status'], 0, 20), 'updated_at' => now()]);
        }

        return response()->json(['data' => $result], $result['ok'] ? 200 : 422);
    }

    public function gatewayCheckout(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/integrations');

        $data = $request->validate([
            'provider' => ['required', Rule::in(self::PROVIDERS['gateway'])], 'subscription_invoice_id' => 'nullable|integer', 'transaction_id' => 'nullable|integer',
            'success_url' => 'required|url|max:300', 'cancel_url' => 'required|url|max:300', 'currency' => 'nullable|string|size:3',
        ]);
        $companyId = $this->scopeCompanyId($request);

        if (empty($data['subscription_invoice_id']) === empty($data['transaction_id'])) {
            throw ValidationException::withMessages(['transaction_id' => ['Name either a subscription invoice or a sale.']]);
        }

        if (! empty($data['subscription_invoice_id'])) {
            $invoice = SubscriptionInvoice::query()->where('company_id', $companyId)->whereIn('status', ['unpaid', 'overdue'])->find($data['subscription_invoice_id']);
            abort_if($invoice === null, 404);
            [$amount, $reference, $description] = [(float) $invoice->total_amount, 'sub-'.$invoice->id, 'Subscription invoice '.$invoice->invoice_no];
        } else {
            $sale = Transaction::query()->sells()->where('company_id', $companyId)->find($data['transaction_id']);
            abort_if($sale === null, 404);
            $amount = Payment::remainingAmountForTransaction($sale);

            if ($amount <= 0) {
                throw ValidationException::withMessages(['transaction_id' => ['This invoice is already paid.']]);
            }

            [$reference, $description] = ['sale-'.$sale->id, 'Invoice '.$sale->invoice_no];
        }

        $account = $this->account($companyId, 'gateway', $data['provider']);
        $result = $this->gateway($data['provider'])->createCheckout($account['credentials'], $amount, $data['currency'] ?? 'PKR', $reference, $description, $data['success_url'], $data['cancel_url']);

        if (! $result['ok']) {
            return response()->json(['message' => $result['error'], 'errors' => ['gateway' => [$result['error']]]], 422);
        }

        $id = DB::table('gateway_payments')->insertGetId([
            'company_id' => $companyId, 'subscription_invoice_id' => $data['subscription_invoice_id'] ?? null, 'transaction_id' => $data['transaction_id'] ?? null,
            'provider' => $data['provider'], 'external_id' => $result['external_id'], 'amount' => $amount, 'currency' => strtoupper($data['currency'] ?? 'PKR'),
            'status' => 'created', 'checkout_url' => $result['checkout_url'], 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Checkout created', 'data' => ['id' => $id, 'checkout_url' => $result['checkout_url']]], 201);
    }

    /**
     * Public endpoint: the gateway tells us a checkout was paid. Only a correctly signed notification is acted on.
     */
    public function stripeWebhook(Request $request, int $company): JsonResponse
    {
        $account = DB::table('integration_accounts')->where('company_id', $company)->where('kind', 'gateway')->where('provider', 'stripe')->where('is_active', true)->first();

        if ($account === null || blank($account->credentials)) {
            return response()->json(['message' => 'Not configured.'], 404);
        }

        $credentials = (array) json_decode(Crypt::decryptString($account->credentials), true);
        $event = $this->gateway('stripe')->readWebhook($credentials, $request->getContent(), (string) $request->header('Stripe-Signature'));

        if ($event === null) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        if ($event['paid']) {
            $payment = DB::table('gateway_payments')->where('company_id', $company)->where('external_id', $event['external_id'])->where('status', '!=', 'paid')->first();

            if ($payment !== null) {
                DB::transaction(function () use ($payment, $event): void {
                    DB::table('gateway_payments')->where('id', $payment->id)->update(['status' => 'paid', 'updated_at' => now()]);

                    if ($payment->subscription_invoice_id !== null) {
                        SubscriptionInvoice::query()->find($payment->subscription_invoice_id)?->markPaid('gateway', $event['external_id'], (float) $payment->amount, null, null);
                    }
                });
            }
        }

        return response()->json(['received' => true]);
    }

    public function bankFeedFetch(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/bankreconciliation');

        $data = $request->validate(['provider' => ['nullable', Rule::in(self::PROVIDERS['bankfeed'])], 'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        $account = $this->account($this->scopeCompanyId($request), 'bankfeed', $data['provider'] ?? 'http_json');
        $result = app(HttpJsonBankFeed::class)->fetch((string) ($account['options']['endpoint'] ?? ''), $account['credentials'], $data['from'], $data['to']);

        if (! $result['ok']) {
            return response()->json(['message' => $result['error'], 'errors' => ['feed' => [$result['error']]]], 422);
        }

        return response()->json(['data' => ['lines' => $result['lines'], 'count' => count($result['lines'])]]);
    }

    public function openApi(Request $request): JsonResponse
    {
        $this->docsAllowed($request);

        return response()->json($this->docs->openApi(config('app.name').' API', '1.0.0', url('/')));
    }

    public function postman(Request $request): JsonResponse
    {
        $this->docsAllowed($request);

        return response()->json($this->docs->postman(config('app.name').' API', url('/')));
    }

    private function docsAllowed(Request $request): void
    {
        abort_unless($request->user()?->hasRole('superadmin') || hasMenuPermission('/apikeys'), 403);
    }

    /**
     * @return array{id: int, credentials: array<string, mixed>, options: array<string, mixed>}
     */
    private function account(int $companyId, string $kind, string $provider): array
    {
        $row = DB::table('integration_accounts')->where('company_id', $companyId)->where('kind', $kind)->where('provider', $provider)->where('is_active', true)->first();

        if ($row === null || blank($row->credentials)) {
            throw ValidationException::withMessages(['provider' => ["Set up and switch on the {$provider} account first (Integrations)."]]);
        }

        return ['id' => (int) $row->id, 'credentials' => (array) json_decode(Crypt::decryptString($row->credentials), true), 'options' => (array) json_decode((string) $row->options, true)];
    }

    private function courier(string $provider): CourierAdapter
    {
        return $provider === 'leopards' ? new LeopardsAdapter : new PostExAdapter;
    }

    private function gateway(string $provider): PaymentGateway
    {
        return new StripeGateway;
    }
}
