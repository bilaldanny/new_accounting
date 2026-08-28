<?php

namespace App\Mail;

use App\Mail\Support\MailMessageData;
use App\Models\Company;

class PaymentReminderMail extends BrandedMailable
{
    /**
     * @param  array<string, string|int|float|null>  $invoice
     */
    public function __construct(
        public string $userName,
        public array $invoice,
        public string $paymentUrl,
        public bool $overdue = false,
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
        return 'emails.payment-reminder';
    }

    protected function messageData(): MailMessageData
    {
        return new MailMessageData(
            title: $this->overdue ? 'Invoice Overdue' : 'Payment Reminder',
            subtitle: $this->overdue
                ? 'Your invoice payment is now overdue.'
                : 'This is a friendly reminder that your invoice payment is due soon.',
            icon: 'warning',
            userName: $this->userName,
            paragraphs: [
                $this->overdue
                    ? 'Your invoice is overdue. Please make a payment as soon as possible to avoid interruption of service.'
                    : 'This is a friendly reminder that your invoice payment is due soon.',
            ],
            buttonText: 'Pay Now',
            buttonUrl: $this->paymentUrl,
            details: [
                'Invoice Number' => $this->invoice['number'] ?? null,
                'Due Date' => $this->invoice['due_date'] ?? null,
                'Amount Due' => $this->invoice['amount_due'] ?? $this->invoice['total'] ?? null,
            ],
            status: $this->overdue ? 'overdue' : 'pending',
            alert: [
                'type' => $this->overdue ? 'danger' : 'warning',
                'title' => $this->overdue ? 'Payment overdue' : 'Payment due soon',
                'message' => $this->overdue
                    ? 'Your invoice is overdue.'
                    : 'Please complete payment before the due date.',
            ],
        );
    }
}
