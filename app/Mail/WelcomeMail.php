<?php

namespace App\Mail;

use App\Mail\Support\CompanyBranding;
use App\Mail\Support\MailMessageData;
use App\Models\Company;

class WelcomeMail extends BrandedMailable
{
    public function __construct(
        public string $userName,
        public string $userEmail,
        public string $dashboardUrl,
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
        return 'emails.welcome';
    }

    protected function messageData(): MailMessageData
    {
        $companyName = CompanyBranding::resolve($this->company())['companyName'];

        return new MailMessageData(
            title: 'Welcome to '.$companyName,
            subtitle: 'Your account has been successfully created.',
            icon: 'welcome',
            userName: $this->userName,
            paragraphs: [
                'Your account has been successfully created. You can now sign in and start managing your books, invoices, and payments.',
            ],
            buttonText: 'Login to Dashboard',
            buttonUrl: $this->dashboardUrl,
            details: [
                'Email' => $this->userEmail,
                'Company Name' => $companyName,
            ],
        );
    }
}
