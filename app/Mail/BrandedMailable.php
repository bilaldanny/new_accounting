<?php

namespace App\Mail;

use App\Mail\Support\CompanyBranding;
use App\Mail\Support\MailMessageData;
use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

abstract class BrandedMailable extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [1, 5, 10];

    private ?MailMessageData $resolvedMessage = null;

    public function __construct()
    {
        if (! app()->runningUnitTests()) {
            $this->afterCommit();
        }
    }

    abstract protected function messageData(): MailMessageData;

    abstract protected function emailView(): string;

    protected function company(): ?Company
    {
        return null;
    }

    public function envelope(): Envelope
    {
        $data = $this->resolvedMessageData();

        return new Envelope(
            subject: (string) ($data->subject ?? $data->title),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: $this->emailView(),
            with: $this->resolvedMessageData()->toViewData(),
        );
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Failed to send branded email', [
            'mailable' => static::class,
            'error' => $exception?->getMessage(),
        ]);
    }

    protected function resolvedMessageData(): MailMessageData
    {
        return $this->resolvedMessage ??= $this->messageData()->applyBranding(
            $this->branding(),
        );
    }

    /**
     * @return array{
     *     companyName: string,
     *     companyLogo: string|null,
     *     supportEmail: string,
     *     companyEmail: string|null,
     *     privacyUrl: string|null,
     *     termsUrl: string|null,
     *     accentColor: string
     * }
     */
    protected function branding(): array
    {
        return CompanyBranding::resolve($this->company());
    }
}
