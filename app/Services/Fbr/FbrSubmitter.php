<?php

namespace App\Services\Fbr;

use App\Models\FbrSetting;
use App\Models\FbrSubmission;
use App\Models\Transaction;
use Throwable;

/**
 * Hands a saved sale to the FBR gateway when the company has switched FBR e-invoicing on, and keeps one FbrSubmission row per
 * sale. A company that has not switched it on is not touched at all. With the stub gateway the row ends as `stub`.
 */
class FbrSubmitter
{
    public function __construct(private FbrInvoiceGateway $gateway, private FbrInvoiceBuilder $builder) {}

    /**
     * Called after a sale is saved: records it for FBR when it is a posted sale of a company that switched FBR on.
     */
    public function afterSellSaved(Transaction $sell): ?FbrSubmission
    {
        if (in_array($sell->status, Transaction::UNPOSTED_SELL_STATUSES, true) || $sell->company_id === null) {
            return null;
        }

        $settings = FbrSetting::query()->where('company_id', $sell->company_id)->where('enabled', true)->first();

        return $settings === null ? null : $this->submit($sell, $settings);
    }

    public function submit(Transaction $sell, FbrSetting $settings): FbrSubmission
    {
        $submission = FbrSubmission::query()->firstOrNew(['transaction_id' => $sell->id]);

        if ($submission->status === FbrSubmission::STATUS_SUBMITTED) {
            return $submission;
        }

        $payload = $this->builder->build($sell, $settings);

        try {
            $result = $this->gateway->submit($payload, $settings);
        } catch (Throwable $exception) {
            $result = new FbrResult(FbrSubmission::STATUS_FAILED, null, null, substr($exception->getMessage(), 0, 1000));
        }

        $submission->fill([
            'company_id' => $sell->company_id,
            'status' => $result->status,
            'fbr_invoice_number' => $result->invoiceNumber,
            'payload' => $payload,
            'response' => $result->response,
            'error' => $result->error,
            'attempts' => (int) $submission->attempts + 1,
            'submitted_at' => $result->status === FbrSubmission::STATUS_SUBMITTED ? now() : null,
        ])->save();

        return $submission;
    }
}
