{{-- Phase B47 (Module 29 §75–76): an invoice from Umar Techy to a store, drawn from the stored invoice only. --}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Invoice {{ $invoice->number }}</title>
@include('billing.pdf-style')
</head>
<body>
<table class="head">
    <tr>
        <td>
            <div class="issuer">{{ $issuer['name'] }}</div>
            @if ($issuer['details'])<div class="muted pre">{{ $issuer['details'] }}</div>@endif
        </td>
        <td class="right">
            <div class="title">Invoice</div>
            <div>{{ $invoice->number }}</div>
            <div class="badge badge-{{ $invoice->status->value }}">{{ ucfirst($invoice->status->value) }}</div>
        </td>
    </tr>
</table>

<table class="meta">
    <tr>
        <td>
            <div class="label">Bill to</div>
            <div>{{ $invoice->bill_to['store'] ?? '' }}</div>
            @if (! empty($invoice->bill_to['email']))<div class="muted">{{ $invoice->bill_to['email'] }}</div>@endif
        </td>
        <td>
            <div class="label">Issued</div><div>{{ $invoice->issued_at->format('j M Y') }}</div>
            <div class="label">Due</div><div>{{ $invoice->due_at->format('j M Y') }}</div>
        </td>
        <td>
            <div class="label">Period</div>
            <div>{{ $invoice->period_start->format('j M Y') }} – {{ $invoice->period_end->format('j M Y') }}</div>
        </td>
    </tr>
</table>

<table class="lines">
    <thead><tr><th>Description</th><th class="right">Amount</th></tr></thead>
    <tbody>
    @foreach ($invoice->lines as $line)
        <tr>
            <td>{{ $line->description }}</td>
            <td class="right">{{ ($line->kind ?? 'charge') === 'credit' ? '− ' : '' }}{{ $money($line->amount_minor, $invoice->currency) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td>Subtotal</td><td class="right">{{ $money($invoice->subtotal_minor, $invoice->currency) }}</td></tr>
    <tr><td>{{ $taxLabel }} ({{ rtrim(rtrim(number_format($invoice->tax_rate_bps / 100, 2), '0'), '.') }} %)</td><td class="right">{{ $money($invoice->tax_minor, $invoice->currency) }}</td></tr>
    @if ($invoice->credit_applied_minor > 0)
        <tr><td>Account credit used</td><td class="right">− {{ $money($invoice->credit_applied_minor, $invoice->currency) }}</td></tr>
    @endif
    <tr class="grand"><td>Total</td><td class="right">{{ $money($invoice->total_minor, $invoice->currency) }}</td></tr>
    @if ($invoice->amount_paid_minor > 0)
        <tr><td>Paid</td><td class="right">− {{ $money($invoice->amount_paid_minor, $invoice->currency) }}</td></tr>
    @endif
    @foreach ($credits as $credit)
        <tr><td>Credit note {{ $credit->number }} ({{ str_replace('_', ' ', $credit->settlement) }})</td><td class="right">{{ $money($credit->total_minor, $credit->currency) }}</td></tr>
    @endforeach
    @if ($invoice->status->value === 'open')
        <tr class="grand"><td>Still due</td><td class="right">{{ $money($invoice->amountDue(), $invoice->currency) }}</td></tr>
    @endif
</table>

@if ($invoice->payments->isNotEmpty())
    <div class="label section">Payments received</div>
    <table class="lines">
        @foreach ($invoice->payments as $payment)
            <tr><td>{{ $payment->received_at->format('j M Y') }} · {{ str_replace('_', ' ', $payment->method->value ?? $payment->method) }}{{ $payment->reference ? ' · '.$payment->reference : '' }}</td><td class="right">{{ $money($payment->amount_minor, $payment->currency) }}</td></tr>
        @endforeach
    </table>
@endif

<div class="footer">Invoice {{ $invoice->number }} · document template v{{ $invoice->document_version ?: $templateVersion }} · amounts in {{ $invoice->currency }}</div>
</body>
</html>
