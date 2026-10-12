<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\System\BranchTree;
use App\Services\System\BusinessSettings;
use App\Services\System\DataRetention;
use App\Services\System\ExchangeRateSync;
use App\Services\System\NotificationTemplates;
use App\Services\System\SetupWizard;
use App\Services\System\WebPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Business Settings of a company: the number format, POS extras and default language it stores here, a read-only summary of the
 * settings that live on other pages, the branch tree, notification wording, the exchange rate feed, retention rules for its own
 * logs, the setup wizard and web push subscriptions. Reading needs `/businesssettings`; every change needs
 * `/businesssettings/manage`. The company is the signed-in user's (the superadmin names one).
 */
class BusinessSettingsController extends Controller
{
    use ScopesToCompany;

    public function show(Request $request, BusinessSettings $settings, BranchTree $tree): JsonResponse
    {
        $this->authorizeMenuPermission('/businesssettings');
        $companyId = $this->scopeCompanyId($request);
        $company = DB::table('companies')->where('id', $companyId)->first(['id', 'name', 'code', 'phone', 'email', 'address', 'ntn_no', 'strn_no']);
        $regional = DB::table('company_settings as s')->leftJoin('currencies as c', 'c.id', '=', 's.currency_id')->where('s.company_id', $companyId)
            ->first(['s.date_format', 's.time_format', 's.financial_start_month', 'c.code as currency_code', 's.currency_placement']);
        $feed = DB::table('integration_accounts')->where('company_id', $companyId)->where('kind', 'rates')->where('provider', 'http_json')->first();

        return response()->json(['data' => [
            'settings' => $settings->all($companyId),
            'sample' => ['positive' => $settings->format(1234567.891, $companyId), 'negative' => $settings->format(-1234.5, $companyId)],
            'summary' => [
                'company' => $company, 'regional' => $regional,
                'links' => ['company' => '/company/setting', 'invoice' => '/invoice/settings', 'receipt' => '/receipt/settings', 'barcode' => '/barcode/settings', 'checkout' => '/checkout/settings', 'messaging' => '/messagingsetting', 'exchange_rates' => '/currencyrate'],
            ],
            'branches' => $tree->flat($companyId),
            'notification_kinds' => collect(NotificationTemplates::KINDS)->map(fn (string $label, string $kind): array => ['kind' => $kind, 'label' => $label])->values()->all(),
            'notification_templates' => DB::table('notification_templates')->where('company_id', $companyId)->get(['kind', 'title', 'body', 'is_active']),
            'rate_feed' => $feed === null ? null : ['url' => (json_decode((string) $feed->options, true)['url'] ?? ''), 'rates_path' => (json_decode((string) $feed->options, true)['rates_path'] ?? 'rates'),
                'is_active' => (bool) $feed->is_active, 'has_token' => $feed->credentials !== null, 'last_sync_at' => json_decode((string) $feed->options, true)['last_sync_at'] ?? null, 'last_status' => json_decode((string) $feed->options, true)['last_status'] ?? null],
            'retention' => DB::table('data_retention_rules')->where('company_id', $companyId)->get(['entity', 'keep_days', 'archive', 'is_active', 'last_run_at', 'last_removed']),
            'retention_entities' => collect(DataRetention::ENTITIES)->map(fn (array $e, string $key): array => ['entity' => $key, 'label' => $e[2]])->values()->all(),
            'retention_min_days' => DataRetention::MIN_DAYS,
        ]]);
    }

    public function save(Request $request, BusinessSettings $settings): JsonResponse
    {
        $this->authorizeMenuPermission('/businesssettings/manage');
        $request->validate(['company_id' => 'nullable|integer', 'number_format' => 'nullable|array', 'pos' => 'nullable|array', 'language' => 'nullable|array']);

        $saved = $settings->save($this->scopeCompanyId($request), $request->only(['number_format', 'pos', 'language']));

        return response()->json(['message' => 'Successfully Saved', 'data' => $saved]);
    }

    public function moveBranch(Request $request, int $id, BranchTree $tree): JsonResponse
    {
        $this->authorizeMenuPermission('/businesssettings/manage');
        $data = $request->validate(['parent_id' => 'nullable|integer', 'company_id' => 'nullable|integer']);
        $companyId = $this->scopeCompanyId($request);

        $tree->move($companyId, $id, isset($data['parent_id']) ? (int) $data['parent_id'] : null);

        return response()->json(['message' => 'Successfully Saved', 'data' => $tree->flat($companyId)]);
    }

    public function saveNotificationTemplate(Request $request, string $kind): JsonResponse
    {
        $this->authorizeMenuPermission('/businesssettings/manage');
        abort_unless(array_key_exists($kind, NotificationTemplates::KINDS), 404);
        $data = $request->validate(['title' => 'nullable|string|max:160', 'body' => 'nullable|string|max:1000', 'is_active' => 'nullable|boolean', 'company_id' => 'nullable|integer']);

        DB::table('notification_templates')->updateOrInsert(
            ['company_id' => $this->scopeCompanyId($request), 'kind' => $kind],
            ['title' => isset($data['title']) ? strip_tags($data['title']) : null, 'body' => isset($data['body']) ? strip_tags($data['body']) : null, 'is_active' => (bool) ($data['is_active'] ?? true), 'updated_at' => now(), 'created_at' => now()],
        );

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function deleteNotificationTemplate(Request $request, string $kind): JsonResponse
    {
        $this->authorizeMenuPermission('/businesssettings/manage');
        DB::table('notification_templates')->where('company_id', $this->scopeCompanyId($request))->where('kind', $kind)->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    public function saveRetention(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/businesssettings/manage');
        $data = $request->validate([
            'entity' => ['required', Rule::in(array_keys(DataRetention::ENTITIES))], 'keep_days' => 'required|integer|min:'.DataRetention::MIN_DAYS.'|max:3650',
            'archive' => 'nullable|boolean', 'is_active' => 'nullable|boolean', 'company_id' => 'nullable|integer',
        ]);

        DB::table('data_retention_rules')->updateOrInsert(
            ['company_id' => $this->scopeCompanyId($request), 'entity' => $data['entity']],
            ['keep_days' => $data['keep_days'], 'archive' => (bool) ($data['archive'] ?? true), 'is_active' => (bool) ($data['is_active'] ?? true), 'updated_at' => now(), 'created_at' => now()],
        );

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function deleteRetention(Request $request, string $entity): JsonResponse
    {
        $this->authorizeMenuPermission('/businesssettings/manage');
        DB::table('data_retention_rules')->where('company_id', $this->scopeCompanyId($request))->where('entity', $entity)->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    public function saveRateFeed(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/businesssettings/manage');
        $data = $request->validate([
            'url' => ['required', 'string', 'max:300', 'regex:#^https://#i'], 'rates_path' => 'nullable|string|max:60|regex:/^[A-Za-z0-9_.]+$/', 'token' => 'nullable|string|max:300',
            'is_active' => 'nullable|boolean', 'company_id' => 'nullable|integer',
        ]);
        $companyId = $this->scopeCompanyId($request);
        $existing = DB::table('integration_accounts')->where('company_id', $companyId)->where('kind', 'rates')->where('provider', 'http_json')->first();
        $options = array_merge((array) ($existing ? json_decode((string) $existing->options, true) : []), ['url' => $data['url'], 'rates_path' => $data['rates_path'] ?? 'rates']);
        $credentials = isset($data['token']) && $data['token'] !== '' ? Crypt::encryptString(json_encode(['token' => $data['token']])) : ($existing->credentials ?? null);

        $row = ['options' => json_encode($options), 'credentials' => $credentials, 'is_active' => (bool) ($data['is_active'] ?? true), 'updated_at' => now()];

        if ($existing === null) {
            DB::table('integration_accounts')->insert($row + ['company_id' => $companyId, 'kind' => 'rates', 'provider' => 'http_json', 'created_at' => now()]);
        } else {
            DB::table('integration_accounts')->where('id', $existing->id)->update($row);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function syncRates(Request $request, ExchangeRateSync $sync): JsonResponse
    {
        $this->authorizeMenuPermission('/businesssettings/manage');
        $result = $sync->run($this->scopeCompanyId($request));

        return response()->json(['message' => $result['ok'] ? "Updated {$result['updated']} rates." : 'The rates were not updated: '.$result['error'].' The rates already entered are unchanged.', 'data' => $result], $result['ok'] ? 200 : 422);
    }

    public function wizard(Request $request, SetupWizard $wizard): JsonResponse
    {
        $this->authorizeMenuPermission('/setup');

        return response()->json(['data' => $wizard->steps($this->scopeCompanyId($request))]);
    }

    public function skipStep(Request $request, string $key, SetupWizard $wizard): JsonResponse
    {
        $this->authorizeMenuPermission('/setup');
        $data = $request->validate(['skip' => 'required|boolean', 'company_id' => 'nullable|integer']);
        $companyId = $this->scopeCompanyId($request);

        $wizard->skip($companyId, $key, (bool) $data['skip']);

        return response()->json(['data' => $wizard->steps($companyId)]);
    }

    // ---------------------------------------------------------------- web push (every signed-in user, for themselves)

    public function pushKey(WebPush $push): JsonResponse
    {
        $keys = $push->keys();

        return response()->json(['data' => ['public_key' => $keys['public'] ?? null]]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000', 'regex:#^https://#i'], 'keys' => 'required|array', 'keys.p256dh' => 'required|string|max:200', 'keys.auth' => 'required|string|max:100',
        ]);
        $user = Auth::user();

        if (strlen(WebPush::unb64($data['keys']['p256dh'])) !== 65 || strlen(WebPush::unb64($data['keys']['auth'])) !== 16) {
            throw ValidationException::withMessages(['keys' => ['The subscription keys are not valid.']]);
        }

        DB::table('push_subscriptions')->updateOrInsert(['endpoint_hash' => hash('sha256', $data['endpoint'])], [
            'company_id' => $user->company_id, 'user_id' => $user->id, 'endpoint' => $data['endpoint'], 'p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth'],
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 250), 'updated_at' => now(), 'created_at' => now(),
        ]);

        return response()->json(['message' => 'Notifications are on for this browser.'], 201);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => 'required|string|max:1000']);
        DB::table('push_subscriptions')->where('user_id', Auth::id())->where('endpoint_hash', hash('sha256', $data['endpoint']))->delete();

        return response()->json(['message' => 'Notifications are off for this browser.']);
    }

    public function pushTest(WebPush $push): JsonResponse
    {
        $subscriptions = DB::table('push_subscriptions')->where('user_id', Auth::id())->get();
        abort_if($subscriptions->isEmpty(), 422, 'This browser is not subscribed.');

        $results = $subscriptions->map(fn (object $s): array => $push->send($s, ['title' => 'Test notification', 'body' => 'Push notifications work on this browser.', 'url' => '/dashboard']))->all();

        return response()->json(['data' => $results]);
    }
}
