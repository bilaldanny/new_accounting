@php
    $statusStyles = [
        'paid' => ['bg' => '#d1fae5', 'fg' => '#065f46', 'label' => 'Payment Successful'],
        'pending' => ['bg' => '#fef3c7', 'fg' => '#92400e', 'label' => 'PENDING'],
        'overdue' => ['bg' => '#fee2e2', 'fg' => '#9b1c1c', 'label' => 'OVERDUE'],
        'failed' => ['bg' => '#fee2e2', 'fg' => '#9b1c1c', 'label' => 'FAILED'],
        'cancelled' => ['bg' => '#f1f5f9', 'fg' => '#475569', 'label' => 'CANCELLED'],
    ];
    $style = $statusStyles[$status] ?? ['bg' => '#e0f2fe', 'fg' => '#075985', 'label' => strtoupper((string) $status)];
@endphp

<span style="display:inline-block;padding:6px 14px;border-radius:50px;background-color:{{ $style['bg'] }};color:{{ $style['fg'] }};font-size:12px;line-height:16px;font-weight:700;letter-spacing:0.5px;text-transform:uppercase;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
    {{ $style['label'] }}
</span>
