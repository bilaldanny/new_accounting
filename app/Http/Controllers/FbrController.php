<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\FbrSetting;
use App\Models\FbrSubmission;
use App\Models\Transaction;
use App\Services\Fbr\FbrSubmitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * FBR e-invoicing, structure only: the company's switch and (encrypted, empty) credentials, and the log of what was handed to
 * the gateway. There is no live integration, so every submission ends as `stub` and nothing is sent to FBR.
 */
class FbrController extends Controller
{
    public const STUB_WARNING = 'FBR is switched on in STUB mode: no credentials are saved, and even with credentials nothing is sent to FBR. '
        .'Sales are only recorded here with a FBR-STUB number, which is not an FBR invoice number.';

    public function show(Request $request): JsonResponse
    {
        $this->authorizeCompanySettingMenuPermission();

        $companyId = $this->companyId($request);

        return response()->json(['data' => $this->payload(FbrSetting::forCompany($companyId), $companyId)]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeCompanySettingMenuPermission();

        $companyId = $this->companyId($request);
        $data = $request->validate([
            'tax_inclusive_pricing' => 'nullable|boolean',
            'enabled' => 'required|boolean',
            'environment' => ['required', Rule::in(FbrSetting::ENVIRONMENTS)],
            'pos_id' => 'nullable|string|max:100',
            'api_url' => 'nullable|string|max:255',
            'username' => 'nullable|string|max:150',
            'password' => 'nullable|string|max:255',
            'api_token' => 'nullable|string|max:2000',
            'clear_credentials' => 'nullable|boolean',
        ]);

        if ($data['enabled'] && blank(Company::query()->whereKey($companyId)->value('ntn_no'))) {
            throw ValidationException::withMessages(['enabled' => ['Enter the company NTN (Company > Tax ID / NTN) before switching FBR on.']]);
        }

        $setting = FbrSetting::forCompany($companyId);
        $setting->fill([
            'company_id' => $companyId,
            'enabled' => $data['enabled'],
            'environment' => $data['environment'],
            'pos_id' => $data['pos_id'] ?? null,
            'api_url' => $data['api_url'] ?? null,
            'username' => $data['username'] ?? null,
        ]);

        if ($request->boolean('clear_credentials')) {
            $setting->password = null;
            $setting->api_token = null;
        } else {
            foreach (['password', 'api_token'] as $secret) {
                if (filled($data[$secret] ?? null)) {
                    $setting->{$secret} = $data[$secret];
                }
            }
        }

        $setting->save();

        if (array_key_exists('tax_inclusive_pricing', $data)) {
            CompanySetting::query()->where('company_id', $companyId)->update(['tax_inclusive_pricing' => (bool) $data['tax_inclusive_pricing']]);
        }

        $response = ['message' => 'FBR settings saved', 'data' => $this->payload($setting, $companyId)];

        if ($setting->enabled && ! $setting->hasCredentials()) {
            $response['warning'] = self::STUB_WARNING;
        }

        return response()->json($response);
    }

    public function submissions(Request $request): JsonResponse
    {
        $this->authorizeCompanySettingMenuPermission();

        $rows = FbrSubmission::query()
            ->where('company_id', $this->companyId($request))
            ->with('transaction:id,invoice_no,final_amount')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest('id')
            ->paginate(min((int) ($request->input('show_record') ?: 10), 100));

        $rows->getCollection()->transform(fn (FbrSubmission $row): array => [
            'id' => $row->id,
            'transaction_id' => $row->transaction_id,
            'invoice_no' => $row->transaction?->invoice_no,
            'amount' => $row->transaction?->final_amount,
            'status' => $row->status,
            'fbr_invoice_number' => $row->fbr_invoice_number,
            'attempts' => $row->attempts,
            'error' => $row->error,
            'updated_at' => $row->updated_at?->toDateTimeString(),
        ]);

        return response()->json(['data' => $rows]);
    }

    /**
     * Hands one sale to the gateway again (or for the first time).
     */
    public function submit(Request $request, int $transactionId, FbrSubmitter $submitter): JsonResponse
    {
        $this->authorizeCompanySettingMenuPermission();

        $sell = Transaction::findVisibleSell($transactionId);

        if ($sell === null) {
            abort(404);
        }

        $settings = FbrSetting::query()->where('company_id', $sell->company_id)->where('enabled', true)->first();

        if ($settings === null) {
            throw ValidationException::withMessages(['fbr' => ['FBR e-invoicing is not switched on for this company.']]);
        }

        if (in_array($sell->status, Transaction::UNPOSTED_SELL_STATUSES, true)) {
            throw ValidationException::withMessages(['fbr' => ['Only a posted sale can be handed to FBR.']]);
        }

        $submission = $submitter->submit($sell, $settings);

        return response()->json(['message' => 'Recorded as '.$submission->status, 'data' => ['status' => $submission->status, 'fbr_invoice_number' => $submission->fbr_invoice_number]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(FbrSetting $setting, int $companyId): array
    {
        $company = Company::query()->find($companyId, ['id', 'ntn_no', 'strn_no']);

        return $setting->present() + [
            'live_integration' => false,
            'stub_mode' => true,
            'credentials_complete' => $setting->hasCredentials(),
            'ntn_no' => $company?->ntn_no,
            'strn_no' => $company?->strn_no,
            'tax_inclusive_pricing' => (bool) CompanySetting::query()->where('company_id', $companyId)->value('tax_inclusive_pricing'),
        ];
    }

    private function companyId(Request $request): int
    {
        $user = $request->user();
        $companyId = (int) ($user?->hasRole('superadmin') ? $request->integer('company_id') : $user?->company_id);

        if ($companyId === 0) {
            throw ValidationException::withMessages(['company_id' => ['Choose a company.']]);
        }

        return $companyId;
    }
}
