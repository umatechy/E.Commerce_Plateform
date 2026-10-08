{{-- Phase B47 (Module 29 §43, §75): a credit note, drawn from the stored note only. --}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Credit note {{ $note->number }}</title>
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
            <div class="title">Credit note</div>
            <div>{{ $note->number }}</div>
        </td>
    </tr>
</table>

<table class="meta">
    <tr>
        <td><div class="label">Issued to</div><div>{{ $invoice->bill_to['store'] ?? '' }}</div></td>
        <td><div class="label">Issued</div><div>{{ $note->issued_at?->format('j M Y') }}</div></td>
        <td><div class="label">For invoice</div><div>{{ $invoice->number }}</div></td>
    </tr>
</table>

<table class="lines">
    <thead><tr><th>Description</th><th class="right">Amount</th></tr></thead>
    <tbody>
    @foreach ($note->lines as $line)
        <tr><td>{{ $line['description'] }}</td><td class="right">{{ $money($line['amount_minor'], $note->currency) }}</td></tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td>Before {{ $taxLabel }}</td><td class="right">{{ $money($note->subtotal_minor, $note->currency) }}</td></tr>
    <tr><td>{{ $taxLabel }}</td><td class="right">{{ $money($note->tax_minor, $note->currency) }}</td></tr>
    <tr class="grand"><td>Total credited</td><td class="right">{{ $money($note->total_minor, $note->currency) }}</td></tr>
</table>

<p>
    @if ($note->settlement === 'refund')
        Paid back by {{ str_replace('_', ' ', $note->refund_method) }}, reference {{ $note->refund_reference }}.
    @elseif ($note->settlement === 'account_credit')
        Kept as account credit: it pays towards the next invoices.
    @else
        Taken off the amount due on invoice {{ $invoice->number }}.
    @endif
</p>
<p class="muted">Reason: {{ $note->reason }}</p>

<div class="footer">Credit note {{ $note->number }} · document template v{{ $note->document_version ?: $templateVersion }} · amounts in {{ $note->currency }}</div>
</body>
</html>
