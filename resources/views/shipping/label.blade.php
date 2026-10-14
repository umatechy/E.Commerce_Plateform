{{-- Phase B49 (Module 13 §55–56): a 4 × 6 inch shipping label printed by the platform — sans-serif (owner decision 14), no remote resources. --}}
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 14px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #000; }
    .box { border: 1.5px solid #000; padding: 6px 8px; margin-bottom: 6px; }
    .label { font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.4px; color: #333; }
    .big { font-size: 13px; font-weight: bold; }
    .huge { font-size: 17px; font-weight: bold; }
    .center { text-align: center; }
    table { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; }
    .cod { background: #000; color: #fff; padding: 6px; text-align: center; }
    .prepaid { border: 1.5px dashed #000; padding: 5px; text-align: center; }
    .muted { color: #333; }
    .barcode img { width: 100%; height: 58px; }
    .mono { font-family: 'DejaVu Sans Mono', monospace; letter-spacing: 1px; }
</style>
</head>
<body>
    <div class="box"><table><tr>
        <td>
            <div class="label">{{ $isReturn ? 'Return to' : 'From' }}</div>
            <div class="big">{{ $sender['name'] }}</div>
            <div>{{ $sender['address'] }}</div>
            @if ($sender['phone'])<div>{{ $sender['phone'] }}</div>@endif
        </td>
        <td style="width: 38%; text-align: right;">
            <div class="label">Courier</div>
            <div class="big">{{ $carrier }}</div>
            <div class="muted">{{ $date }}</div>
        </td>
    </tr></table></div>

    <div class="box">
        <div class="label">{{ $isReturn ? 'Return to (store)' : 'Deliver to' }}</div>
        <div class="huge">{{ $receiver['name'] }}</div>
        @if ($receiver['phone'])<div class="big">{{ $receiver['phone'] }}</div>@endif
        <div style="font-size: 11px;">{{ $receiver['address'] }}</div>
        @if ($receiver['city'])<div class="big">{{ $receiver['city'] }}</div>@endif
    </div>

    @if ($trackingNumber)
        <div class="box center barcode">
            <img src="data:image/svg+xml;base64,{{ $barcode }}" alt="">
            <div class="mono big">{{ $trackingNumber }}</div>
        </div>
    @endif

    @if ($codAmount)
        <div class="cod"><span class="label" style="color:#fff;">Collect cash on delivery</span><br><span class="huge">{{ $codAmount }}</span></div>
    @elseif (! $isReturn)
        <div class="prepaid big">PREPAID — do not collect cash</div>
    @endif

    <div class="box" style="margin-top: 6px;"><table><tr>
        <td><div class="label">Order</div><div class="big">{{ $orderNumber }}</div></td>
        <td><div class="label">Pieces</div><div class="big">{{ $pieces }}</div></td>
        <td><div class="label">Weight</div><div class="big">{{ $weight }}</div></td>
    </tr></table></div>

    @if (count($items) > 0)
        <div class="muted" style="font-size: 8px;">
            @foreach ($items as $item){{ $item }}@if (! $loop->last) · @endif @endforeach
        </div>
    @endif
    <div class="muted center" style="font-size: 7px; margin-top: 4px;">Shipment {{ $reference }} · label v{{ $templateVersion }}</div>
</body>
</html>
