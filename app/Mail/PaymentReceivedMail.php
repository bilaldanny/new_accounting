<?php

namespace App\Mail;

use App\Mail\Support\MailMessageData;
use App\Models\Company;

class PaymentReceivedMail extends BrandedMailable
{
    /**
     * @param  array<string, string|int|float|null>  $payment
     */
    public function __construct(
        public string $userName,
        public array $payment,
        public string $transactionUrl,
        public ?Company $company = null,
    ) {
        parent::__construct();
    }

    protected function company(): ?Company
    {
        return $this->company;
    }

    protected function emailView(): string
    {
        return 'emails.payment-received';
    }

    protected function messageData(): MailMessageData
    {
        return new MailMessageData(
            title: 'Payment Received',
            subtitle: 'We have successfully received your payment.',
            icon: 'payment',
            userName: $this->userName,
            paragraphs: [
                'We have successfully received your payment. Thank you.',
            ],
            buttonText: 'View Transaction',
            buttonUrl: $this->transactionUrl,
            details: [
                'Payment Reference' => $this->payment['reference'] ?? null,
                'Invoice Number' => $this->payment['invoice_number'] ?? null,
                'Payment Date' => $this->payment['date'] ?? null,
                'Payment Method' => $this->payment['method'] ?? null,
                'Amount' => $this->payment['amount'] ?? null,
            ],
            status: 'paid',
            alert: [
                'type' => 'success',
                'title' => 'Payment confirmed',
                'message' => 'Payment successfully received.',
            ],
        );
    }
}
