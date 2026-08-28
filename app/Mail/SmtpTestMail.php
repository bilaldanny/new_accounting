<?php

namespace App\Mail;

use App\Mail\Support\CompanyBranding;
use App\Mail\Support\MailMessageData;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SmtpTestMail extends Mailable
{
    use SerializesModels;

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'SMTP Test Email',
        );
    }

    public function content(): Content
    {
        $data = (new MailMessageData(
            title: 'SMTP Test Email',
            subtitle: 'Your outgoing email settings are working.',
            icon: 'notification',
            paragraphs: [
                'This is a test message sent from Software Settings. If you received it, SMTP is configured correctly.',
            ],
        ))->applyBranding(CompanyBranding::resolve());

        return new Content(
            view: 'emails.smtp-test',
            with: $data->toViewData(),
        );
    }
}
