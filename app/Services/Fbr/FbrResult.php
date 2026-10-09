<?php

namespace App\Services\Fbr;

/**
 * What a gateway answered for one invoice: `status` is one of the FbrSubmission statuses.
 */
final readonly class FbrResult
{
    /**
     * @param  array<string, mixed>|null  $response
     */
    public function __construct(
        public string $status,
        public ?string $invoiceNumber = null,
        public ?array $response = null,
        public ?string $error = null,
    ) {}
}
