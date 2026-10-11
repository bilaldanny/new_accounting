<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Receipt {{ $sale->invoice_no }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f5f5f5; margin: 0; padding: 1rem; color: #222; }
        .receipt { max-width: 42rem; margin: 0 auto; background: #fff; padding: 1.5rem; border-radius: .5rem; }
        h1 { font-size: 1.25rem; margin: 0 0 .25rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { padding: .35rem .25rem; border-bottom: 1px solid #e5e5e5; text-align: left; font-size: .9rem; }
        th.n, td.n { text-align: right; }
        .totals { margin-top: 1rem; text-align: right; }
        .muted { color: #666; font-size: .85rem; }
        @media print { body { background: #fff; } .receipt { padding: 0; } }
    </style>
</head>
<body>
<div class="receipt">
    <h1>{{ $company?->name }}</h1>
    <div class="muted">{{ $company?->address }}</div>
    <p><strong>Receipt {{ $sale->invoice_no }}</strong><br>
        {{ $sale->transaction_date?->format('Y-m-d') }}
        @if ($customer) <br>{{ $customer->business_name ?: trim($customer->first_name.' '.$customer->last_name) }} @endif
    </p>
    <table>
        <thead><tr><th>Item</th><th class="n">Qty</th><th class="n">Price</th><th class="n">Amount</th></tr></thead>
        <tbody>
        @foreach ($lines as $line)
            <tr><td>{{ $line->name }}</td><td class="n">{{ (float) $line->quantity }}</td><td class="n">{{ number_format((float) $line->unit_price, 2) }}</td><td class="n">{{ number_format((float) $line->subtotal, 2) }}</td></tr>
        @endforeach
        </tbody>
    </table>
    <div class="totals">
        @if ((float) $sale->shipping_charges > 0) <div>Shipping {{ number_format((float) $sale->shipping_charges, 2) }}</div> @endif
        @if ((float) $sale->tax_amount > 0) <div>Tax {{ number_format((float) $sale->tax_amount, 2) }}</div> @endif
        <div><strong>Total {{ number_format((float) $sale->final_amount, 2) }}</strong></div>
        <div>Paid {{ number_format($paid, 2) }}</div>
        <div>Balance {{ number_format(max((float) $sale->final_amount - $paid, 0), 2) }}</div>
    </div>
    <p class="muted">Thank you for your business.</p>
</div>
</body>
</html>
