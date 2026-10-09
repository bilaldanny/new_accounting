<?php

namespace App\Services\Fbr;

use App\Models\FbrSetting;

/**
 * The one place a sale invoice would leave for FBR. Only a stub implements it today (see StubFbrGateway): no verified FBR
 * specification, sandbox or credentials exist for this business, so nothing is ever sent. A live gateway would be a second
 * implementation of this interface, bound in place of the stub.
 */
interface FbrInvoiceGateway
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function submit(array $payload, FbrSetting $settings): FbrResult;
}
