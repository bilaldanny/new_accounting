<?php

namespace App\Mail;

use App\Mail\Support\MailMessageData;
use App\Models\Company;

class InvoiceCreatedMail extends BrandedMailable
{
    /**
     * @param  array<string, string|int|float|null>  $invoice
     */
    public function __construct(
        public string $userName,
        public array $invoice,
        public string $invoiceUrl,
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
        return 'emails.invoice-created';
    }

    protected function messageData(): MailMessageData
    {
        return new MailMessageData(
            title: 'Your Invoice is Ready',
            subtitle: 'A new invoice has been created.',
            icon: 'invoice',
            userName: $this->userName,
            paragraphs: [
                'A new invoice has been created and is ready for your review.',
            ],
            buttonText: 'View Invoice',
            buttonUrl: $this->invoiceUrl,
            details: [
                'Invoice Number' => $this->invoice['number'] ?? null,
                'Invoice Date' => $this->invoice['issue_date'] ?? null,
                'Due Date' => $this->invoice['due_date'] ?? null,
                'Customer' => $this->invoice['customer'] ?? null,
                'Total Amount' => $this->invoice['total'] ?? null,
            ],
            invoiceSummary: $this->invoice,
        );
    }
}
