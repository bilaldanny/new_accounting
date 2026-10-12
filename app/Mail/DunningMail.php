<?php

namespace App\Mail;

use App\Mail\Support\MailMessageData;
use App\Models\Company;

/**
 * An invoice reminder at a dunning level: the subject and body come from the company's level template.
 */
class DunningMail extends BrandedMailable
{
    /**
     * @param  array<string, string|int|float|null>  $details
     */
    public function __construct(
        public string $userName,
        public string $subjectLine,
        public string $bodyText,
        public array $details = [],
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
            title: $this->subjectLine,
            subject: $this->subjectLine,
            subtitle: 'Payment reminder',
            icon: 'warning',
            userName: $this->userName,
            paragraphs: array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', $this->bodyText) ?: []))),
            details: $this->details,
            status: 'overdue',
        );
    }
}
