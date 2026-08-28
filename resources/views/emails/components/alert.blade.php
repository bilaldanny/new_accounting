@php
    $alertStyles = [
        'success' => ['bg' => '#f0fdf4', 'border' => '#bbf7d0', 'title' => '#166534', 'fg' => '#15803d'],
        'warning' => ['bg' => '#fffbeb', 'border' => '#fde68a', 'title' => '#92400e', 'fg' => '#a16207'],
        'danger' => ['bg' => '#fef2f2', 'border' => '#fecaca', 'title' => '#9b1c1c', 'fg' => '#b91c1c'],
        'info' => ['bg' => '#eff6ff', 'border' => '#bfdbfe', 'title' => '#1e3a5f', 'fg' => '#1d4ed8'],
    ];
    $style = $alertStyles[$alert['type']] ?? $alertStyles['info'];
@endphp

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 24px;background-color:{{ $style['bg'] }};border:1px solid {{ $style['border'] }};border-radius:10px;">
    <tr>
        <td style="padding:16px 20px;">
            @if (! empty($alert['title']))
                <p style="margin:0 0 4px;font-size:13px;line-height:18px;font-weight:700;color:{{ $style['title'] }};">
                    {{ $alert['title'] }}
                </p>
            @endif
            <p style="margin:0;font-size:13px;line-height:1.5;color:{{ $style['fg'] }};">
                {{ $alert['message'] }}
            </p>
        </td>
    </tr>
</table>
