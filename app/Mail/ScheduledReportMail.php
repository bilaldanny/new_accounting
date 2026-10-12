<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A scheduled report as a CSV attachment with a one-line body.
 */
class ScheduledReportMail extends Mailable
{
    public function __construct(
        public readonly string $reportName,
        public readonly string $csv,
        public readonly string $fileName,
        public readonly int $rowCount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->reportName);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>The report <strong>'.e($this->reportName).'</strong> is attached ('.$this->rowCount.' rows, '.now()->toDateString().').</p>');
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [Attachment::fromData(fn (): string => $this->csv, $this->fileName)->withMime('text/csv')];
    }
}
