<?php

namespace App\Services\Fbr;

use App\Models\FbrSetting;
use App\Models\FbrSubmission;

/**
 * Pretends to be FBR so the rest of the flow (settings, payload, submission log) can be built and tried. It makes NO network
 * call and the number it hands back (`FBR-STUB-...`) is NOT an FBR invoice number: it must never be printed on an invoice or
 * used for compliance.
 */
class StubFbrGateway implements FbrInvoiceGateway
{
    public function submit(array $payload, FbrSetting $settings): FbrResult
    {
        $invoice = (string) ($payload['invoice']['number'] ?? '0');
        $number = 'FBR-STUB-'.$settings->company_id.'-'.preg_replace('/[^A-Za-z0-9]+/', '', $invoice).'-'.now()->format('Ymd');

        return new FbrResult(
            FbrSubmission::STATUS_STUB,
            $number,
            ['note' => 'Stub gateway: nothing was sent to FBR.'],
        );
    }
}
