{{--
  INTERNAL admin order summary — everything, including commission, seller
  payouts, plan, admin notes and export history. Never give this to the courier.
--}}
@php
    $dt  = fn($v) => $v === null ? '—' : number_format((float) $v, 3, '.', '') . ' DT';
    // Paragraph direction follows the first letter (Unicode "first strong" rule).
    $dir = fn($s) => preg_match('/^[^\p{L}]*\p{Arabic}/u', (string) $s) ? 'rtl' : 'ltr';
    $r   = $recipient;
@endphp
<html>
<head>
<style>
    body       { font-family: dejavusans; font-size: 8.8pt; color: #111; }
    table      { border-collapse: collapse; width: 100%; }
    td, th     { vertical-align: top; }
    h2         { font-size: 11pt; margin: 12pt 0 4pt; border-bottom: 1pt solid #111; padding-bottom: 2pt; }
    .label     { font-size: 7.5pt; font-weight: bold; color: #555; text-transform: uppercase; }
    .grid td   { border: 0.6pt solid #aaa; padding: 3pt 5pt; }
    .items th  { background: #eee; border: 0.6pt solid #aaa; padding: 3pt 4pt; font-size: 7.5pt; text-align: left; }
    .items td  { border: 0.6pt solid #aaa; padding: 3pt 4pt; }
    .num       { text-align: right; white-space: nowrap; }
    .internal  { background: #db142e; color: #fff; font-weight: bold; padding: 5pt 8pt; font-size: 10pt; letter-spacing: 1pt; }
    .warn      { color: #b45309; font-weight: bold; }
    .muted     { color: #666; }
</style>
</head>
<body>
<watermarktext content="INTERNAL" alpha="0.07" />
<htmlpagefooter name="footer">
    <div class="muted" style="font-size: 7pt; text-align: center;">INTERNAL — CHOOSE'Tounsi admin only · {{ $order->order_number }} · page {PAGENO}/{nbpg}</div>
</htmlpagefooter>
<sethtmlpagefooter name="footer" value="on" />

<div class="internal">INTERNAL — ADMIN ONLY · DO NOT SEND TO COURIER OR CUSTOMER</div>

<table style="margin-top: 8pt;">
    <tr>
        <td style="width: 12%;"><img src="{{ $logo }}" style="width: 44pt;" /></td>
        <td>
            <div style="font-size: 14pt; font-weight: bold;">Order summary {{ $order->order_number }}</div>
            <div class="muted">Placed {{ optional($order->created_at)->format('d/m/Y H:i') }}
                @if ($order->confirmed_at) · confirmed {{ \Illuminate\Support\Carbon::parse($order->confirmed_at)->format('d/m/Y H:i') }}@endif
                · generated {{ now()->format('d/m/Y H:i') }}</div>
        </td>
    </tr>
</table>

@if ($issues)
    <div class="warn" style="margin-top: 6pt;">Not ready for the courier:</div>
    @foreach ($issues as $issue)<div class="warn">• {{ $issue }}</div>@endforeach
@endif

<h2>Order</h2>
<table class="grid">
    <tr>
        <td><span class="label">Status</span><br>{{ $order->status }}</td>
        <td><span class="label">Payment</span><br>{{ strtoupper($order->payment_method ?? 'cod') }} · {{ $order->payment_status }}</td>
        <td><span class="label">Customer account</span><br>{{ $order->user?->name }}<br><span class="muted">{{ $order->user?->email }}</span></td>
        <td><span class="label">COD to collect (all slips)</span><br><b>{{ $dt($codTotal) }}</b></td>
    </tr>
    <tr>
        <td><span class="label">Items subtotal</span><br>{{ $dt($money['subtotal']) }}</td>
        <td><span class="label">Seller coupons</span><br>−{{ $dt($money['discount_amount']) }}{{ $money['coupon_codes'] ? ' (' . implode(', ', $money['coupon_codes']) . ')' : '' }}</td>
        <td><span class="label">Shipping (customer)</span><br>{{ $dt($money['shipping_fee']) }}</td>
        <td><span class="label">Total customer pays</span><br><b>{{ $dt($money['total']) }}</b></td>
    </tr>
    <tr>
        <td><span class="label">Agency shipping cost</span><br>{{ $dt($order->getAttribute('shipping_cost')) }}</td>
        <td><span class="label">Shipping paid by</span><br>{{ $order->getAttribute('shipping_paid_by') ?? '—' }}</td>
        <td colspan="2"><span class="label">Admin note</span><br>{{ $order->admin_note ?: '—' }}</td>
    </tr>
</table>

<h2>Shipping address (snapshot at checkout)</h2>
<table class="grid">
    <tr>
        <td style="width: 50%;">
            <div dir="{{ $dir($r['name']) }}"><b>{{ $r['name'] ?? '—' }}</b></div>
            {{-- a Latin label first: digits alone take the direction of an Arabic line above --}}
            <div><span class="label">Tel</span> {{ $r['phone'] ?? '—' }}{{ $r['phone_secondary'] ? ' / ' . $r['phone_secondary'] : '' }}</div>
        </td>
        <td>
            <div dir="{{ $dir($r['address']) }}">{{ $r['address'] ?? '—' }}</div>
            <div>{{ $r['delegation'] }} {{ trim(($r['postal_code'] ?? '') . ' ' . ($r['wilaya'] ?? '')) }}</div>
            @if ($r['notes'])<div class="muted" dir="{{ $dir($r['notes']) }}">{{ $r['notes'] }}</div>@endif
            @if ($r['legacy'])<div class="warn">Legacy order — unstructured address</div>@endif
        </td>
    </tr>
</table>

@foreach ($subOrders as $s)
    @php $so = $s['model']; $p = $s['pickup']; @endphp
    <h2>Sub-order {{ $s['reference'] }} — {{ $p['shop_name'] ?? 'Seller #' . $so->seller_id }} {{ $s['active'] ? '' : '(' . strtoupper($so->status) . ' — no slip)' }}</h2>
    <table class="grid">
        <tr>
            <td style="width: 50%;">
                <span class="label">Pickup</span>
                <div dir="{{ $dir($p['contact']) }}">{{ $p['contact'] ?? '—' }}</div>
                <div><span class="label">Tel</span> {{ $p['phone'] ? \App\Support\TunisianPhone::format($p['phone']) : '—' }}</div>
                <div dir="{{ $dir($p['address']) }}">{{ \App\Support\SellerPickup::formatAddress($p) ?: '—' }}</div>
                @unless ($p['complete'])<div class="warn">Missing: {{ implode(', ', $p['missing']) }}</div>@endunless
            </td>
            <td>
                <span class="label">Status</span> {{ $so->status }} · {{ $so->payment_status }}
                @if ($so->getAttribute('payout_status')) · payout {{ $so->getAttribute('payout_status') }}@endif<br>
                <span class="label">Commission</span> {{ $dt($so->getAttribute('commission_amount')) }}
                · <span class="label">Seller net</span> {{ $dt($so->getAttribute('seller_net_amount')) }}<br>
                <span class="label">Shipping cost share</span> {{ $dt($so->getAttribute('shipping_cost')) }}
                · <span class="label">Seller shipping charge</span> {{ $dt($so->getAttribute('seller_shipping_charge')) }}<br>
                <span class="label">Platform profit</span> {{ $dt($so->getAttribute('platform_profit')) }}
                @if ($s['money'])<br><span class="label">Slip total</span> {{ $dt($s['money']['total']) }} · <span class="label">COD</span> <b>{{ $dt($s['money']['cod']) }}</b>@endif
            </td>
        </tr>
    </table>
    <table class="items" style="margin-top: 3pt;">
        <thead>
            <tr>
                <th>Item</th><th>Variant</th><th class="num">Qty</th><th class="num">Unit</th><th class="num">Total</th>
                <th class="num">Discount</th><th class="num">Comm. %</th><th class="num">Commission</th><th class="num">Seller</th><th>Plan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($s['items'] as $i)
                <tr>
                    <td dir="{{ $dir($i->product_name) }}">{{ $i->product_name }}</td>
                    <td>{{ $i->variant_label ?: '—' }}</td>
                    <td class="num">{{ $i->quantity }}</td>
                    <td class="num">{{ $dt($i->unit_price) }}</td>
                    <td class="num">{{ $dt($i->total) }}</td>
                    <td class="num">{{ $dt($i->discount_amount ?? 0) }}</td>
                    <td class="num">{{ $i->commission_percentage !== null ? rtrim(rtrim(number_format((float) $i->commission_percentage, 2, '.', ''), '0'), '.') . '%' : '—' }}</td>
                    <td class="num">{{ $dt($i->commission_amount) }}</td>
                    <td class="num">{{ $dt($i->seller_amount) }}</td>
                    <td>{{ $i->plan_used ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endforeach

<h2>Export history</h2>
@forelse ($order->exports->sortByDesc('created_at') as $e)
    <div>{{ optional($e->created_at)->format('d/m/Y H:i') }} — {{ $e->type }}{{ $e->seller_order_id ? ' (sub-order ' . $e->seller_order_id . ')' : '' }} by {{ $e->exporter?->name ?? 'unknown' }}</div>
@empty
    <div class="muted">No delivery document exported yet.</div>
@endforelse
</body>
</html>
