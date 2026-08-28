<?php

namespace App\Mail\Support;

final class MailMessageData
{
    /**
     * @param  list<string>  $paragraphs
     * @param  array<string, string|int|float|null>  $details
     * @param  array<string, string|int|float|null>|null  $invoiceSummary
     * @param  array{type?: string, title?: string, message?: string}|null  $alert
     */
    public function __construct(
        public string $title,
        public ?string $subject = null,
        public ?string $subtitle = null,
        public ?string $preheader = null,
        public string $icon = 'notification',
        public ?string $iconUrl = null,
        public ?string $userName = null,
        public array $paragraphs = [],
        public ?string $buttonText = null,
        public ?string $buttonUrl = null,
        public array $details = [],
        public ?array $invoiceSummary = null,
        public ?string $status = null,
        public ?array $alert = null,
        public ?string $companyName = null,
        public ?string $companyLogo = null,
        public ?string $supportEmail = null,
        public ?string $companyEmail = null,
        public ?string $privacyUrl = null,
        public ?string $termsUrl = null,
        public string $accentColor = '#199683',
    ) {
        $this->subject ??= $this->title;
        $this->preheader ??= $this->subtitle ?: $this->title;
        $this->details = $this->filterAssoc($this->details);
        $this->invoiceSummary = $this->invoiceSummary === null
            ? null
            : $this->filterAssoc($this->invoiceSummary);
        $this->status = $this->status !== null && trim($this->status) !== ''
            ? strtolower(trim($this->status))
            : null;
        $this->buttonText = $this->nullable($this->buttonText);
        $this->buttonUrl = $this->nullable($this->buttonUrl);
        $this->userName = $this->nullable($this->userName);
        $this->companyLogo = $this->nullable($this->companyLogo);
        $this->iconUrl = $this->nullable($this->iconUrl);
    }

    /**
     * @param  array{
     *     companyName: string,
     *     companyLogo: string|null,
     *     supportEmail: string,
     *     companyEmail: string|null,
     *     privacyUrl: string|null,
     *     termsUrl: string|null,
     *     accentColor: string
     * }  $branding
     */
    public function applyBranding(array $branding): self
    {
        $this->companyName = $this->nullable($this->companyName) ?? $branding['companyName'];
        $this->companyLogo = $this->nullable($this->companyLogo) ?? $branding['companyLogo'];
        $this->supportEmail = $this->nullable($this->supportEmail) ?? $branding['supportEmail'];
        $this->companyEmail = $this->nullable($this->companyEmail) ?? $branding['companyEmail'];
        $this->privacyUrl = $this->nullable($this->privacyUrl) ?? $branding['privacyUrl'];
        $this->termsUrl = $this->nullable($this->termsUrl) ?? $branding['termsUrl'];
        $this->accentColor = $branding['accentColor'] !== ''
            ? $branding['accentColor']
            : $this->accentColor;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toViewData(): array
    {
        return [
            'title' => $this->title,
            'subject' => $this->subject,
            'subtitle' => $this->subtitle,
            'preheader' => $this->preheader,
            'icon' => $this->icon,
            'iconUrl' => $this->iconUrl,
            'iconMeta' => $this->iconMeta(),
            'userName' => $this->userName,
            'paragraphs' => $this->paragraphs,
            'buttonText' => $this->buttonText,
            'buttonUrl' => $this->buttonUrl,
            'details' => $this->details,
            'invoiceSummary' => $this->invoiceSummary,
            'status' => $this->status,
            'alert' => $this->normalizedAlert(),
            'companyName' => $this->companyName ?? (string) config('app.name'),
            'companyLogo' => $this->companyLogo,
            'supportEmail' => $this->supportEmail ?? (string) config('mail.from.address'),
            'companyEmail' => $this->companyEmail,
            'privacyUrl' => $this->privacyUrl,
            'termsUrl' => $this->termsUrl,
            'accentColor' => $this->accentColor,
        ];
    }

    /**
     * @return array{letters: string, bg: string, fg: string, label: string}
     */
    public function iconMeta(): array
    {
        return match ($this->icon) {
            'welcome' => ['letters' => 'ID', 'bg' => '#E7F4F1', 'fg' => '#0F766E', 'label' => 'Account'],
            'invoice' => ['letters' => '#', 'bg' => '#EEF2FF', 'fg' => '#3730A3', 'label' => 'Invoice'],
            'payment' => ['letters' => 'OK', 'bg' => '#ECFDF3', 'fg' => '#166534', 'label' => 'Payment'],
            'warning' => ['letters' => '!', 'bg' => '#FFFBEB', 'fg' => '#92400E', 'label' => 'Reminder'],
            'security' => ['letters' => 'SEC', 'bg' => '#F1F5F9', 'fg' => '#334155', 'label' => 'Security'],
            'invitation' => ['letters' => '+', 'bg' => '#E7F4F1', 'fg' => '#0F766E', 'label' => 'Invitation'],
            default => ['letters' => 'i', 'bg' => '#EFF6FF', 'fg' => '#1E3A5F', 'label' => 'Notice'],
        };
    }

    /**
     * @return array{type: string, title: string|null, message: string}|null
     */
    private function normalizedAlert(): ?array
    {
        if ($this->alert === null) {
            return null;
        }

        $message = trim((string) ($this->alert['message'] ?? ''));

        if ($message === '') {
            return null;
        }

        $type = strtolower((string) ($this->alert['type'] ?? 'info'));

        if (! in_array($type, ['success', 'warning', 'danger', 'info'], true)) {
            $type = 'info';
        }

        $title = $this->nullable($this->alert['title'] ?? null);

        return [
            'type' => $type,
            'title' => $title,
            'message' => $message,
        ];
    }

    /**
     * @param  array<string, string|int|float|null>  $rows
     * @return array<string, string>
     */
    private function filterAssoc(array $rows): array
    {
        $filtered = [];

        foreach ($rows as $label => $value) {
            if ($value === null) {
                continue;
            }

            $text = trim((string) $value);

            if ($text === '') {
                continue;
            }

            $filtered[(string) $label] = $text;
        }

        return $filtered;
    }

    private function nullable(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
