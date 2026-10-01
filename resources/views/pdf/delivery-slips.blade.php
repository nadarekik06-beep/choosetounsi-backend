{{--
  Delivery slips (bordereaux) for the courier — one page per seller sub-order.
  Rendered by mPDF (App\Services\Orders\DeliveryDocumentService): layout uses
  tables only, which mPDF renders reliably. NO internal data on this document
  (commission, payout, plan, admin notes).
--}}
@php
    $dt  = fn($v) => number_format((float) $v, 3, '.', '') . ' DT';
    // Paragraph direction follows the first letter (Unicode "first strong" rule).
    $dir = fn($s) => preg_match('/^[^\p{L}]*\p{Arabic}/u', (string) $s) ? 'rtl' : 'ltr';
    $methodLabels = ['cod' => 'Paiement à la livraison / Cash on delivery', 'wallet' => 'Portefeuille / Wallet', 'card' => 'Carte bancaire / Card', 'd17' => 'D17'];
    $statusLabels = ['paid' => 'Payé / Paid', 'unpaid' => 'Non payé / Unpaid', 'refunded' => 'Remboursé / Refunded'];
    $support = config('delivery.support_phone');
@endphp
<html>
<head>
<style>
    body        { font-family: dejavusans; font-size: 9.5pt; color: #111; }
    table       { border-collapse: collapse; width: 100%; }
    td, th      { vertical-align: top; }
    .muted      { color: #555; }
    .small      { font-size: 8pt; }
    .label      { font-size: 7.5pt; font-weight: bold; color: #555; text-transform: uppercase; letter-spacing: 0.5pt; }
    /* mPDF: borders/backgrounds go on table cells — on nested divs they misrender */
    td.box      { border: 1pt solid #222; padding: 6pt 8pt; height: 118pt; }
    td.box-title { background: #111; color: #fff; font-weight: bold; font-size: 8.5pt; padding: 3pt 8pt; text-transform: uppercase; }
    .big        { font-size: 12pt; font-weight: bold; }
    .meta td    { border: 0.6pt solid #999; padding: 3pt 6pt; }
    .items th   { background: #eee; border: 0.6pt solid #999; padding: 4pt 5pt; font-size: 8pt; text-align: left; }
    .items td   { border: 0.6pt solid #999; padding: 4pt 5pt; }
    .num        { text-align: right; white-space: nowrap; }
    .totals td  { padding: 2pt 6pt; }
    td.cod      { border: 2.5pt solid #db142e; background: #fff1f2; padding: 8pt; text-align: center; }
    td.cod-paid { border: 2.5pt solid #198f41; background: #effaf3; padding: 8pt; text-align: center; }
    .sign td    { border: 0.8pt solid #222; height: 62pt; padding: 4pt 6pt; width: 50%; }
    .warn       { color: #b45309; font-size: 8pt; font-weight: bold; }
    .kv td      { padding: 0 0 1pt 0; }
    .kv td.k    { width: 52pt; }
</style>
</head>
<body>
@foreach ($slips as $slip)
    @if (!$loop->first)<pagebreak />@endif
    @php $p = $slip['pickup']; $r = $slip['recipient']; $m = $slip['money']; @endphp

    {{-- Header --}}
    <table>
        <tr>
            <td style="width: 16%;"><img src="{{ $logo }}" style="width: 52pt;" /></td>
            <td style="width: 52%; padding-top: 4pt;">
                <div style="font-size: 15pt; font-weight: bold; color: #db142e;">CHOOSE'Tounsi</div>
                <div style="font-size: 11pt; font-weight: bold;">BORDEREAU DE LIVRAISON</div>
                <div class="muted small">Delivery slip · Sub-order {{ $loop->iteration }} of {{ $loop->count }}</div>
            </td>
            <td style="width: 32%; text-align: right;">
                <barcode code="{{ $slip['reference'] }}" type="QR" size="0.75" error="M" disableborder="1" />
            </td>
        </tr>
    </table>

    <table class="meta" style="margin-top: 6pt;">
        <tr>
            <td style="width: 34%;"><span class="label">Commande / Order</span><br><span class="big">{{ $slip['order_number'] }}</span></td>
            <td style="width: 38%;"><span class="label">Référence / Reference</span><br><span class="big">{{ $slip['reference'] }}</span></td>
            <td style="width: 28%;"><span class="label">Date commande / Order date</span><br>{{ optional($slip['order_date'])->format('d/m/Y H:i') }}</td>
        </tr>
        <tr>
            <td colspan="2" style="text-align: center; padding: 4pt;">
                <barcode code="{{ $slip['reference'] }}" type="C128B" size="0.9" height="0.8" />
                <div class="small">{{ $slip['reference'] }}</div>
            </td>
            <td>
                <span class="label">Colis / Packages</span><br><span class="big">{{ $slip['packages'] }}</span>
                <br><span class="label">Imprimé / Printed</span><br><span class="small">{{ now()->format('d/m/Y H:i') }}</span>
            </td>
        </tr>
    </table>

    {{-- Sender / Recipient --}}
    <table style="margin-top: 8pt;">
        <tr>
            <td style="width: 49%; padding: 0;">
                <table>
                    <tr><td class="box-title">Expéditeur — Enlèvement / Sender — Pickup</td></tr>
                    <tr><td class="box">
                        <div class="big" dir="{{ $dir($p['shop_name']) }}">{{ $p['shop_name'] ?? '—' }}</div>
                        {{-- label and value in separate cells: an Arabic value can't reorder the label --}}
                        <table class="kv">
                            <tr><td class="k"><span class="label">Contact</span></td><td dir="{{ $dir($p['contact']) }}">{{ $p['contact'] ?? '—' }}</td></tr>
                            <tr><td class="k"><span class="label">Tél</span></td><td><b>{{ $p['phone'] ?? '—' }}</b></td></tr>
                        </table>
                        <div style="margin-top: 4pt;" dir="{{ $dir($p['address']) }}">{{ $p['address'] ?? '—' }}</div>
                        <div dir="{{ $dir($p['city']) }}">{{ $p['city'] }}</div>
                        <div><b>{{ trim(($p['postal_code'] ?? '') . ' ' . ($p['wilaya'] ?? '')) }}</b></div>
                        @if ($p['notes'])<div class="small muted">{{ $p['notes'] }}</div>@endif
                        @unless ($p['complete'])<div class="warn">Adresse incomplète : {{ implode(', ', $p['missing']) }}</div>@endunless
                    </td></tr>
                </table>
            </td>
            <td style="width: 2%;"></td>
            <td style="width: 49%; padding: 0;">
                <table>
                    <tr><td class="box-title">Destinataire / Recipient</td></tr>
                    <tr><td class="box">
                        <div class="big" dir="{{ $dir($r['name']) }}">{{ $r['name'] ?? '—' }}</div>
                        <div><span class="label">Tél :</span> <b>{{ $r['phone'] ?? '—' }}</b>@if ($r['phone_secondary']) &nbsp;/&nbsp; <b>{{ $r['phone_secondary'] }}</b>@endif</div>
                        <div style="margin-top: 4pt;" dir="{{ $dir($r['address']) }}">{{ $r['address'] ?? '—' }}</div>
                        @if ($r['delegation'])<div dir="{{ $dir($r['delegation']) }}">{{ $r['delegation'] }}</div>@endif
                        <div><b>{{ trim(($r['postal_code'] ?? '') . ' ' . ($r['wilaya'] ?? '')) }}</b></div>
                        @if ($r['notes'])
                            <table class="kv" style="margin-top: 4pt;">
                                <tr><td class="k"><span class="label">Repère</span></td><td dir="{{ $dir($r['notes']) }}">{{ $r['notes'] }}</td></tr>
                            </table>
                        @endif
                        @if ($r['legacy'])<div class="warn">Ancienne commande : adresse non structurée</div>@endif
                    </td></tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- Items --}}
    <table class="items" style="margin-top: 8pt;">
        <thead>
            <tr>
                <th style="width: 41%;">Article / Item</th>
                <th style="width: 18%;">Variante / Variant</th>
                <th class="num" style="width: 7%;">Qté</th>
                <th class="num" style="width: 17%;">P.U.</th>
                <th class="num" style="width: 17%;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($slip['items'] as $item)
                <tr>
                    <td dir="{{ $dir($item['name']) }}">{{ $item['name'] }}</td>
                    <td dir="{{ $dir($item['variant']) }}">{{ $item['variant'] ?: '—' }}</td>
                    <td class="num">{{ $item['quantity'] }}</td>
                    <td class="num">{{ $item['in_bundle'] ? 'pack' : $dt($item['unit_price']) }}</td>
                    <td class="num">{{ $item['in_bundle'] ? 'inclus' : $dt($item['total']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Totals + COD --}}
    <table style="margin-top: 8pt;">
        <tr>
            <td style="width: 50%;">
                <table class="totals">
                    <tr><td>Sous-total / Subtotal</td><td class="num">{{ $dt($m['subtotal']) }}</td></tr>
                    @if ($m['discount'] > 0)
                        <tr><td>Remise / Discount{{ $slip['coupon_code'] ? ' (' . $slip['coupon_code'] . ')' : '' }}</td><td class="num">−{{ $dt($m['discount']) }}</td></tr>
                    @endif
                    <tr><td>Livraison / Shipping</td><td class="num">{{ $m['shipping'] > 0 ? $dt($m['shipping']) : ($loop->count > 1 ? 'sur 1er bordereau' : $dt(0)) }}</td></tr>
                    <tr><td style="border-top: 1pt solid #222;"><b>Total</b></td><td class="num" style="border-top: 1pt solid #222;"><b>{{ $dt($m['total']) }}</b></td></tr>
                </table>
                <div style="margin-top: 6pt;">
                    <span class="label">Paiement / Payment :</span> {{ $methodLabels[$slip['payment_method']] ?? strtoupper($slip['payment_method']) }}
                    <br><span class="label">Statut / Status :</span> {{ $statusLabels[$slip['payment_status']] ?? $slip['payment_status'] }}
                </div>
            </td>
            <td style="width: 4%;"></td>
            <td class="{{ $slip['prepaid'] ? 'cod-paid' : 'cod' }}" style="width: 46%;">
                <div class="label" style="color: #111;">Montant à encaisser (COD)</div>
                <div class="label" style="color: #111;">Amount to collect</div>
                <div style="font-size: 22pt; font-weight: bold; color: {{ $slip['prepaid'] ? '#198f41' : '#db142e' }};">{{ $dt($m['cod']) }}</div>
                @if ($slip['prepaid'])
                    <div class="small"><b>DÉJÀ PAYÉ — NE RIEN ENCAISSER</b><br>Already paid — collect nothing</div>
                @elseif ($loop->count > 1)
                    <div class="small">Colis {{ $loop->iteration }}/{{ $loop->count }} de la commande {{ $slip['order_number'] }}</div>
                @endif
            </td>
        </tr>
    </table>

    {{-- Signatures --}}
    <table class="sign" style="margin-top: 10pt;">
        <tr>
            <td>
                <span class="label">Enlèvement / Pickup</span><br>
                <span class="small">Signature livreur / Courier signature</span><br><br><br>
                <span class="small">Date &amp; heure : ____ / ____ / ________ &nbsp; ____ : ____</span>
            </td>
            <td>
                <span class="label">Livraison / Delivery</span><br>
                <span class="small">Signature destinataire / Recipient signature</span><br><br><br>
                <span class="small">Date &amp; heure : ____ / ____ / ________ &nbsp; ____ : ____</span>
            </td>
        </tr>
    </table>

    <div class="small muted" style="margin-top: 6pt; text-align: center;">
        CHOOSE'Tounsi — marketplace tunisienne{{ $support ? ' · Service client : ' . $support : '' }} · Réf. {{ $slip['reference'] }}
    </div>
@endforeach
</body>
</html>
