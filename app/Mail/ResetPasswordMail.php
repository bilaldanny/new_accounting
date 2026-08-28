<?php

namespace App\Mail;

use App\Mail\Support\MailMessageData;
use App\Models\Company;

class ResetPasswordMail extends BrandedMailable
{
    public function __construct(
        public string $userName,
        public string $resetUrl,
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
        return 'emails.reset-password';
    }

    protected function messageData(): MailMessageData
    {
        return new MailMessageData(
            title: 'Reset Your Password',
            subtitle: 'We received a request to reset your password.',
            icon: 'security',
            userName: $this->userName,
            paragraphs: [
                'We received a request to reset your password. Use the button below to choose a new password.',
            ],
            buttonText: 'Reset Password',
            buttonUrl: $this->resetUrl,
            alert: [
                'type' => 'info',
                'title' => 'Did not request this?',
                'message' => 'If you did not request a password reset, you can safely ignore this email.',
            ],
        );
    }
}
