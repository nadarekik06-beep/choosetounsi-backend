{{--
  Return slip (bordereau de retour) for the courier — the reverse of a delivery:
  pick-up at the CLIENT, destination the SELLER. Rendered by mPDF
  (App\Services\Orders\DeliveryDocumentService::returnSlipPdf): tables only.
  Proof photos are printed for the courier's condition check (anti-fraud).
--}}
@php
    $dt  = fn($v) => number_format((float) $v, 3, '.', '') . ' DT';
    $dir = fn($s) => preg_match('/^[^\p{L}]*\p{Arabic}/u', (string) $s) ? 'rtl' : 'ltr';
    $c = $slip['client']; $s = $slip['seller'];
    $payer = $slip['shipping_payer'] === 'seller'
        ? 'Vendeur (article non conforme / défectueux) / Seller (wrong or defective item)'
        : 'Client (déduit du remboursement) / Client (deducted from refund)';
    $methodLabels = ['cod' => 'Paiement à la livraison / COD', 'wallet' => 'Portefeuille / Wallet', 'card' => 'Carte / Card', 'd17' => 'D17'];
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
    td.box      { border: 1pt solid #222; padding: 6pt 8pt; height: 84pt; }
    td.box-title { background: #111; color: #fff; font-weight: bold; font-size: 8.5pt; padding: 3pt 8pt; text-transform: uppercase; }
    td.box-title.ret { background: #db142e; }
    .big        { font-size: 12pt; font-weight: bold; }
    .meta td    { border: 0.6pt solid #999; padding: 3pt 6pt; }
    .items th   { background: #eee; border: 0.6pt solid #999; padding: 4pt 5pt; font-size: 8pt; text-align: left; }
    .items td   { border: 0.6pt solid #999; padding: 4pt 5pt; vertical-align: middle; }
    .num        { text-align: right; white-space: nowrap; }
    .totals td  { padding: 2pt 6pt; }
    td.refund   { border: 2.5pt solid #198f41; background: #effaf3; padding: 8pt; text-align: center; }
    .check td   { border: 0.8pt solid #222; padding: 4pt 6pt; }
    .sign td    { border: 0.8pt solid #222; height: 50pt; padding: 4pt 6pt; width: 50%; }
    .kv td      { padding: 0 0 1pt 0; }
    .kv td.k    { width: 52pt; }
    .banner     { background: #fff1f2; border: 1pt solid #db142e; color: #db142e; font-weight: bold; text-align: center; padding: 4pt; }
</style>
</head>
<body>
    {{-- Header --}}
    <table>
        <tr>
            <td style="width: 16%;"><img src="{{ $logo }}" style="width: 52pt;" /></td>
            <td style="width: 52%; padding-top: 4pt;">
                <div style="font-size: 15pt; font-weight: bold; color: #db142e;">CHOOSE'Tounsi</div>
                <div style="font-size: 11pt; font-weight: bold;">BORDEREAU DE RETOUR</div>
                <div class="muted small">Return slip · Enlèvement chez le client → livraison au vendeur</div>
            </td>
            <td style="width: 32%; text-align: right;">
                <barcode code="{{ $slip['reference'] }}" type="QR" size="0.75" error="M" disableborder="1" />
            </td>
        </tr>
    </table>

    @if ($slip['cash_refund'])
        <div class="banner" style="margin-top: 4pt;">RETOUR — LE LIVREUR REMBOURSE LE CLIENT EN ESPÈCES : {{ $dt($slip['refund_amount']) }} / COURIER PAYS THE CLIENT BACK IN CASH</div>
    @else
        <div class="banner" style="margin-top: 4pt;">RETOUR — NE RIEN ENCAISSER NI PAYER / RETURN — NO MONEY (PAID ONLINE)</div>
    @endif

    <table class="meta" style="margin-top: 6pt;">
        <tr>
            <td style="width: 34%;"><span class="label">Réf. retour / Return ref.</span><br><span class="big">{{ $slip['reference'] }}</span></td>
            <td style="width: 33%;"><span class="label">Commande d'origine / Original order</span><br><span class="big">{{ $slip['order_number'] }}</span></td>
            <td style="width: 33%;">
                <span class="label">Commande / Ordered</span> {{ optional($slip['order_date'])->format('d/m/Y') }}<br>
                <span class="label">Demande / Requested</span> {{ optional($slip['requested_at'])->format('d/m/Y') }}<br>
                <span class="label">Approuvé / Approved</span> {{ optional($slip['approved_at'])->format('d/m/Y') ?: '—' }}
            </td>
        </tr>
        <tr>
            <td colspan="2" style="text-align: center; padding: 4pt;">
                <barcode code="{{ $slip['reference'] }}" type="C128B" size="0.9" height="0.8" />
                <div class="small">{{ $slip['reference'] }}</div>
            </td>
            <td>
                <span class="label">Retour / Scope</span><br><b>{{ $slip['scope'] === 'full' ? 'Commande complète / Whole order' : 'Partiel / Partial' }}</b>
                <br><span class="label">Imprimé / Printed</span><br><span class="small">{{ now()->format('d/m/Y H:i') }}</span>
            </td>
        </tr>
    </table>

    {{-- Pickup = client / Destination = seller --}}
    <table style="margin-top: 6pt;">
        <tr>
            <td style="width: 49%; padding: 0;">
                <table>
                    <tr><td class="box-title ret">1 · Enlèvement chez le client / Pickup — Client</td></tr>
                    <tr><td class="box">
                        <div class="big" dir="{{ $dir($c['name']) }}">{{ $c['name'] ?? '—' }}</div>
                        <div><span class="label">Tél :</span> <b>{{ $c['phone'] ?? '—' }}</b>@if ($c['phone_secondary']) &nbsp;/&nbsp; <b>{{ $c['phone_secondary'] }}</b>@endif</div>
                        <div style="margin-top: 4pt;" dir="{{ $dir($c['address']) }}">{{ $c['address'] ?? '—' }}</div>
                        @if ($c['delegation'])<div dir="{{ $dir($c['delegation']) }}">{{ $c['delegation'] }}</div>@endif
                        <div><b>{{ trim(($c['postal_code'] ?? '') . ' ' . ($c['wilaya'] ?? '')) }}</b></div>
                        @if ($c['notes'])
                            <table class="kv" style="margin-top: 4pt;">
                                <tr><td class="k"><span class="label">Repère</span></td><td dir="{{ $dir($c['notes']) }}">{{ $c['notes'] }}</td></tr>
                            </table>
                        @endif
                    </td></tr>
                </table>
            </td>
            <td style="width: 2%;"></td>
            <td style="width: 49%; padding: 0;">
                <table>
                    <tr><td class="box-title">2 · Livraison au vendeur / Destination — Seller</td></tr>
                    <tr><td class="box">
                        <div class="big" dir="{{ $dir($s['shop_name']) }}">{{ $s['shop_name'] ?? '—' }}</div>
                        <table class="kv">
                            <tr><td class="k"><span class="label">Contact</span></td><td dir="{{ $dir($s['contact']) }}">{{ $s['contact'] ?? '—' }}</td></tr>
                            <tr><td class="k"><span class="label">Tél</span></td><td><b>{{ $s['phone'] ?? '—' }}</b></td></tr>
                        </table>
                        <div style="margin-top: 4pt;" dir="{{ $dir($s['address']) }}">{{ $s['address'] ?? '—' }}</div>
                        <div dir="{{ $dir($s['city']) }}">{{ $s['city'] }}</div>
                        <div><b>{{ trim(($s['postal_code'] ?? '') . ' ' . ($s['wilaya'] ?? '')) }}</b></div>
                        @if ($s['notes'])<div class="small muted">{{ $s['notes'] }}</div>@endif
                    </td></tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- Returned products --}}
    <table class="items" style="margin-top: 6pt;">
        <thead>
            <tr>
                <th style="width: 11%;">Photo</th>
                <th style="width: 34%;">Article retourné / Returned item</th>
                <th style="width: 19%;">Variante / Variant</th>
                <th class="num" style="width: 7%;">Qté</th>
                <th class="num" style="width: 14%;">P.U. payé</th>
                <th class="num" style="width: 15%;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($slip['items'] as $item)
                <tr>
                    <td style="text-align: center;">@if ($item['image'])<img src="{{ $item['image'] }}" style="width: 38pt; height: 38pt;" />@else — @endif</td>
                    <td dir="{{ $dir($item['name']) }}">{{ $item['name'] }}</td>
                    <td dir="{{ $dir($item['variant']) }}">{{ $item['variant'] ?: '—' }}</td>
                    <td class="num"><b>{{ $item['quantity'] }}</b></td>
                    <td class="num">{{ $dt($item['unit_price']) }}</td>
                    <td class="num">{{ $dt($item['total']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Money + reason --}}
    <table style="margin-top: 6pt;">
        <tr>
            <td style="width: 54%;">
                <table class="totals">
                    <tr><td>Articles retournés (prix payé) / Returned items (paid)</td><td class="num">{{ $dt($slip['items_amount']) }}</td></tr>
                    <tr><td>Frais de retour / Return shipping</td><td class="num">{{ $dt($slip['shipping_fee']) }}</td></tr>
                    <tr><td colspan="2" class="small muted">À la charge de / Paid by : {{ $payer }}</td></tr>
                </table>
                <div style="margin-top: 6pt;">
                    <span class="label">Motif / Reason :</span> <b>{{ $slip['reason'] }}</b><br>
                    <span class="small" dir="{{ $dir($slip['description']) }}">{{ \Illuminate\Support\Str::limit($slip['description'], 300) }}</span><br>
                    <span class="label">Paiement initial / Original payment :</span> {{ $methodLabels[$slip['payment_method']] ?? strtoupper($slip['payment_method']) }}
                </div>
            </td>
            <td style="width: 3%;"></td>
            <td class="refund" style="width: 43%;">
                @if ($slip['cash_refund'])
                    <div class="label" style="color: #111;">À payer au client par le livreur</div>
                    <div class="label" style="color: #111;">Courier pays the client (cash)</div>
                    <div style="font-size: 20pt; font-weight: bold; color: #198f41;">{{ $dt($slip['refund_amount']) }}</div>
                    <div class="small">À remettre en espèces au client à l'enlèvement, après contrôle de l'article avec les photos. Article non conforme : ne rien payer, ne pas enlever, appeler le service client.</div>
                @else
                    <div class="label" style="color: #111;">Remboursement client</div>
                    <div class="label" style="color: #111;">Client refund</div>
                    <div style="font-size: 20pt; font-weight: bold; color: #198f41;">{{ $dt($slip['refund_amount']) }}</div>
                    <div class="small">Commande payée en ligne : remboursée sur le moyen de paiement du client après contrôle. Le livreur ne paie et n'encaisse rien.</div>
                @endif
            </td>
        </tr>
    </table>

    {{-- Proof photos (anti-fraud) --}}
    @if (count($slip['photos']))
        <table style="margin-top: 6pt;">
            <tr><td class="box-title">Photos du client (preuve) / Client proof photos</td></tr>
            <tr><td style="border: 1pt solid #222; padding: 6pt;">
                @foreach ($slip['photos'] as $photo)
                    <img src="{{ $photo }}" style="height: 70pt; margin-right: 6pt;" />
                @endforeach
                <div class="small muted">Comparez l'article remis avec ces photos avant de l'accepter. / Compare the parcel with these photos before accepting it.</div>
            </td></tr>
        </table>
    @endif

    {{-- Condition check at pickup --}}
    <table class="check" style="margin-top: 6pt;">
        <tr>
            <td style="width: 34%;"><span class="label">Contrôle à l'enlèvement / Pickup check</span>@if ($slip['cash_refund'])<br><span class="small">☐ Client remboursé en espèces : ____________ DT</span>@endif</td>
            <td style="width: 22%;">☐ Conforme aux photos<br><span class="small">Matches photos</span></td>
            <td style="width: 22%;">☐ Emballage d'origine<br><span class="small">Original packaging</span></td>
            <td style="width: 22%;">☐ Quantité vérifiée<br><span class="small">Quantity checked</span></td>
        </tr>
        <tr>
            <td colspan="4" style="height: 28pt;"><span class="label">Observations / Remarks</span></td>
        </tr>
    </table>

    {{-- Signatures --}}
    <table class="sign" style="margin-top: 6pt;">
        <tr>
            <td>
                <span class="label">Enlèvement chez le client / Pickup</span><br>
                <span class="small">Signature livreur + client / Courier + client signature</span><br><br><br>
                <span class="small">Date &amp; heure : ____ / ____ / ________ &nbsp; ____ : ____</span>
            </td>
            <td>
                <span class="label">Remise au vendeur / Drop-off</span><br>
                <span class="small">Signature vendeur / Seller signature</span><br><br><br>
                <span class="small">Date &amp; heure : ____ / ____ / ________ &nbsp; ____ : ____</span>
            </td>
        </tr>
        <tr>
            <td colspan="2" class="small muted" style="height: auto; border: 0; text-align: center; padding-top: 4pt;">
                CHOOSE'Tounsi — marketplace tunisienne{{ $support ? ' · Service client : ' . $support : '' }} · Retour {{ $slip['reference'] }} · Commande {{ $slip['order_number'] }}
            </td>
        </tr>
    </table>


</body>
</html>
