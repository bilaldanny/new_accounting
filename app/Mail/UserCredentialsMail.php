<?php

namespace App\Mail;

use App\Mail\Support\CompanyBranding;
use App\Mail\Support\MailMessageData;
use App\Models\Company;

class UserCredentialsMail extends BrandedMailable
{
    public function __construct(
        public string $recipientName,
        public string $username,
        public string $email,
        #[\SensitiveParameter]
        public string $plainPassword,
        public string $loginUrl,
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
        return 'emails.user-credentials';
    }

    protected function messageData(): MailMessageData
    {
        $companyName = CompanyBranding::resolve($this->company())['companyName'];

        return new MailMessageData(
            title: 'Your login credentials',
            subtitle: 'An administrator has generated a new password for your account.',
            icon: 'security',
            userName: $this->recipientName,
            paragraphs: [
                'An administrator has generated a new password for your '.$companyName.' account. Your previous password will no longer work.',
            ],
            buttonText: 'Login to Dashboard',
            buttonUrl: $this->loginUrl,
            details: [
                'Username' => $this->username,
                'Email' => $this->email,
                'Password' => $this->plainPassword,
            ],
        );
    }
}
