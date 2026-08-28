<?php

namespace App\Mail;

use App\Mail\Support\CompanyBranding;
use App\Mail\Support\MailMessageData;
use App\Models\Company;

class UserInvitationMail extends BrandedMailable
{
    public function __construct(
        public string $userName,
        public string $roleName,
        public string $invitedBy,
        public string $acceptUrl,
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
        return 'emails.invitation';
    }

    protected function messageData(): MailMessageData
    {
        $companyName = CompanyBranding::resolve($this->company())['companyName'];

        return new MailMessageData(
            title: 'You Have Been Invited',
            subtitle: 'You have been invited to join '.$companyName.'.',
            icon: 'invitation',
            userName: $this->userName,
            paragraphs: [
                'You have been invited to join '.$companyName.'. Accept the invitation to create your access and start working with the team.',
            ],
            buttonText: 'Accept Invitation',
            buttonUrl: $this->acceptUrl,
            details: [
                'Company Name' => $companyName,
                'Role' => $this->roleName,
                'Invited By' => $this->invitedBy,
            ],
        );
    }
}
