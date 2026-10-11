<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\MessagingSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Where WhatsApp / SMS receipts go: one provider setting per channel (see App\Services\MessageDispatcher). The credentials are
 * kept encrypted and are never returned; the page only learns whether they are set.
 */
class MessagingSettingController extends Controller
{
    use ScopesToCompany;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/messagingsetting');

        $companyId = $this->scopeCompanyId($request);

        return response()->json(['data' => collect(MessagingSetting::CHANNELS)->map(function (string $channel) use ($companyId): array {
            $setting = MessagingSetting::query()->where('company_id', $companyId)->where('channel', $channel)->first();

            return [
                'channel' => $channel, 'provider' => $setting?->provider ?? 'none', 'endpoint' => $setting?->endpoint, 'sender' => $setting?->sender,
                'is_active' => (bool) $setting?->is_active, 'has_credentials' => filled($setting?->credentials),
                'configured' => $setting !== null && $setting->is_active && $setting->provider !== 'none' && filled($setting->endpoint),
            ];
        })->values()]);
    }

    public function update(Request $request, string $channel): JsonResponse
    {
        $this->authorizeMenuPermission('/messagingsetting/edit');

        abort_unless(in_array($channel, MessagingSetting::CHANNELS, true), 404);

        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'provider' => ['required', Rule::in(MessagingSetting::PROVIDERS)],
            'endpoint' => 'nullable|url|max:255',
            'credentials' => 'nullable|string|max:500',
            'sender' => 'nullable|string|max:60',
            'is_active' => 'nullable|boolean',
        ]);

        $companyId = $this->scopeCompanyId($request);
        $setting = MessagingSetting::query()->firstOrNew(['company_id' => $companyId, 'channel' => $channel]);
        $setting->fill([
            'provider' => $data['provider'], 'endpoint' => $data['endpoint'] ?? null, 'sender' => $data['sender'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        if (filled($data['credentials'] ?? null)) {
            $setting->credentials = $data['credentials'];
        }

        $setting->save();

        return response()->json(['message' => 'Successfully Saved']);
    }
}
