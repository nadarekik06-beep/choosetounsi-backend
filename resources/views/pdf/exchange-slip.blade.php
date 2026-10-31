{{--
  Exchange slip (bordereau d'échange) for the courier — one trip:
  1. pick the REPLACEMENT up at the SELLER, 2. deliver it to the CLIENT, collect
  the amount shown and take the OLD item back, 3. bring the old item to the SELLER.
  Rendered by mPDF (DeliveryDocumentService::returnSlipPdf): tables only.
  Proof photos are printed for the condition check of the old item (anti-fraud).
--}}
@php
    $dt  = fn($v) => number_format((float) $v, 3, '.', '') . ' DT';
    $dir = fn($s) => preg_match('/^[^\p{L}]*\p{Arabic}/u', (string) $s) ? 'rtl' : 'ltr';
    $c = $slip['client']; $s = $slip['seller'];
    $collect = (float) $slip['collect_amount'];
    $payer = $slip['shipping_payer'] === 'seller'
        ? 'Vendeur (article non conforme / défectueux) / Seller (wrong or defective item)'
        : 'Client (payé au livreur) / Client (paid to the courier)';
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
    td.box-title.ex { background: #1d4ed8; }
    .big        { font-size: 12pt; font-weight: bold; }
    .meta td    { border: 0.6pt solid #999; padding: 3pt 6pt; }
    .items th   { background: #eee; border: 0.6pt solid #999; padding: 4pt 5pt; font-size: 8pt; text-align: left; }
    .items td   { border: 0.6pt solid #999; padding: 4pt 5pt; vertical-align: middle; }
    .num        { text-align: right; white-space: nowrap; }
    .totals td  { padding: 2pt 6pt; }
    td.collect  { border: 2.5pt solid #1d4ed8; background: #eff4ff; padding: 8pt; text-align: center; }
    .check td   { border: 0.8pt solid #222; padding: 4pt 6pt; }
    .sign td    { border: 0.8pt solid #222; height: 50pt; padding: 4pt 6pt; width: 33%; }
    .kv td      { padding: 0 0 1pt 0; }
    .kv td.k    { width: 52pt; }
    .banner     { background: #eff4ff; border: 1pt solid #1d4ed8; color: #1d4ed8; font-weight: bold; text-align: center; padding: 4pt; }
</style>
</head>
<body>
    {{-- Header --}}
    <table>
        <tr>
            <td style="width: 16%;"><img src="{{ $logo }}" style="width: 52pt;" /></td>
            <td style="width: 52%; padding-top: 4pt;">
                <div style="font-size: 15pt; font-weight: bold; color: #db142e;">CHOOSE'Tounsi</div>
                <div style="font-size: 11pt; font-weight: bold;">BORDEREAU D'ÉCHANGE</div>
                <div class="muted small">Exchange slip · Vendeur → client (remplacement) · client → vendeur (ancien article)</div>
            </td>
            <td style="width: 32%; text-align: right;">
                <barcode code="{{ $slip['reference'] }}" type="QR" size="0.75" error="M" disableborder="1" />
            </td>
        </tr>
    </table>

    @if ($collect > 0)
        <div class="banner" style="margin-top: 4pt;">ÉCHANGE — ENCAISSER {{ $dt($collect) }} AUPRÈS DU CLIENT / EXCHANGE — COLLECT {{ $dt($collect) }} FROM THE CLIENT</div>
    @else
        <div class="banner" style="margin-top: 4pt;">ÉCHANGE — RIEN À ENCAISSER / EXCHANGE — NOTHING TO COLLECT</div>
    @endif

    <table class="meta" style="margin-top: 6pt;">
        <tr>
            <td style="width: 34%;"><span class="label">Réf. échange / Exchange ref.</span><br><span class="big">{{ $slip['reference'] }}</span></td>
            <td style="width: 33%;"><span class="label">Commande d'origine / Original order</span><br><span class="big">{{ $slip['order_number'] }}</span></td>
            <td style="width: 33%;">
                <span class="label">Colis Intigo / Intigo parcel</span><br><b>{{ $slip['intigo_nid'] ?: '—' }}</b><br>
                <span class="label">Taille / Size</span> {{ $slip['fee_size'] ?? '—' }}
                &nbsp; <span class="label">Approuvé</span> {{ optional($slip['approved_at'])->format('d/m/Y') ?: '—' }}
            </td>
        </tr>
        <tr>
            <td colspan="2" style="text-align: center; padding: 4pt;">
                <barcode code="{{ $slip['reference'] }}" type="C128B" size="0.9" height="0.8" />
                <div class="small">{{ $slip['reference'] }}</div>
            </td>
            <td>
                <span class="label">Demande / Requested</span> {{ optional($slip['requested_at'])->format('d/m/Y') }}<br>
                <span class="label">Imprimé / Printed</span><br><span class="small">{{ now()->format('d/m/Y H:i') }}</span>
            </td>
        </tr>
    </table>

    {{-- Seller (replacement out, old item back) / Client --}}
    <table style="margin-top: 6pt;">
        <tr>
            <td style="width: 49%; padding: 0;">
                <table>
                    <tr><td class="box-title">1 · Vendeur : remplacement à enlever, ancien article à rapporter / Seller</td></tr>
                    <tr><td class="box">
                        <div class="big" dir="{{ $dir($s['shop_name']) }}">{{ $s['shop_name'] ?? '—' }}</div>
                        <table class="kv">
                            <tr><td class="k"><span class="label">Contact</span></td><td dir="{{ $dir($s['contact']) }}">{{ $s['contact'] ?? '—' }}</td></tr>
                            <tr><td class="k"><span class="label">Tél</span></td><td><b>{{ $s['phone'] ?? '—' }}</b></td></tr>
                        </table>
                        <div style="margin-top: 4pt;" dir="{{ $dir($s['address']) }}">{{ $s['address'] ?? '—' }}</div>
                        <div dir="{{ $dir($s['zone'] ?? $s['city']) }}">{{ $s['zone'] ?? $s['city'] }}</div>
                        <div><b>{{ trim(($s['postal_code'] ?? '') . ' ' . ($s['wilaya'] ?? '')) }}</b></div>
                    </td></tr>
                </table>
            </td>
            <td style="width: 2%;"></td>
            <td style="width: 49%; padding: 0;">
                <table>
                    <tr><td class="box-title ex">2 · Client : livrer, encaisser, reprendre l'ancien / Client</td></tr>
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
        </tr>
    </table>

    {{-- Old item → replacement --}}
    <table class="items" style="margin-top: 6pt;">
        <thead>
            <tr>
                <th style="width: 11%;">Photo</th>
                <th style="width: 30%;">Ancien article (à reprendre) / Old item (take back)</th>
                <th style="width: 18%;">Variante / Variant</th>
                <th style="width: 33%;">Remplacement (à livrer) / Replacement (deliver)</th>
                <th class="num" style="width: 8%;">Qté</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($slip['items'] as $item)
                <tr>
                    <td style="text-align: center;">@if ($item['image'])<img src="{{ $item['image'] }}" style="width: 38pt; height: 38pt;" />@else — @endif</td>
                    <td dir="{{ $dir($item['name']) }}">{{ $item['name'] }}</td>
                    <td dir="{{ $dir($item['variant']) }}">{{ $item['variant'] ?: '—' }}</td>
                    <td dir="{{ $dir($item['replacement']) }}"><b>{{ $item['replacement'] ?: $item['name'] }}</b></td>
                    <td class="num"><b>{{ $item['quantity'] }}</b></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Money + reason --}}
    <table style="margin-top: 6pt;">
        <tr>
            <td style="width: 54%;">
                <table class="totals">
                    <tr><td>Frais d'échange / Exchange fee</td><td class="num">{{ $dt($slip['shipping_fee']) }}</td></tr>
                    <tr><td colspan="2" class="small muted">À la charge de / Paid by : {{ $payer }}</td></tr>
                </table>
                <div style="margin-top: 6pt;">
                    <span class="label">Motif / Reason :</span> <b>{{ $slip['reason'] }}</b><br>
                    <span class="small" dir="{{ $dir($slip['description']) }}">{{ \Illuminate\Support\Str::limit($slip['description'], 300) }}</span>
                </div>
            </td>
            <td style="width: 3%;"></td>
            <td class="collect" style="width: 43%;">
                <div class="label" style="color: #111;">À encaisser auprès du client</div>
                <div class="label" style="color: #111;">Collect from the client (cash)</div>
                <div style="font-size: 20pt; font-weight: bold; color: #1d4ed8;">{{ $dt($collect) }}</div>
                <div class="small">Remettre le remplacement, encaisser ce montant et reprendre l'ancien article après contrôle avec les photos. Client absent ou refus : ne rien laisser, rapporter le remplacement au vendeur.</div>
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
                <div class="small muted">Comparez l'ancien article avec ces photos avant de le reprendre. / Compare the old item with these photos before taking it back.</div>
            </td></tr>
        </table>
    @endif

    {{-- Check at the client --}}
    <table class="check" style="margin-top: 6pt;">
        <tr>
            <td style="width: 34%;"><span class="label">Contrôle chez le client / Check at the client</span>@if ($collect > 0)<br><span class="small">☐ Encaissé : ____________ DT</span>@endif</td>
            <td style="width: 22%;">☐ Ancien article conforme aux photos<br><span class="small">Old item matches photos</span></td>
            <td style="width: 22%;">☐ Emballage d'origine<br><span class="small">Original packaging</span></td>
            <td style="width: 22%;">☐ Remplacement remis<br><span class="small">Replacement handed over</span></td>
        </tr>
        <tr>
            <td colspan="4" style="height: 28pt;"><span class="label">Observations / Remarks</span></td>
        </tr>
    </table>

    {{-- Signatures --}}
    <table class="sign" style="margin-top: 6pt;">
        <tr>
            <td>
                <span class="label">Enlèvement du remplacement / Pickup</span><br>
                <span class="small">Signature vendeur + livreur</span><br><br><br>
                <span class="small">Date : ____ / ____ / ________</span>
            </td>
            <td>
                <span class="label">Chez le client / At the client</span><br>
                <span class="small">Signature client + livreur</span><br><br><br>
                <span class="small">Date : ____ / ____ / ________</span>
            </td>
            <td>
                <span class="label">Ancien article rendu / Old item back</span><br>
                <span class="small">Signature vendeur</span><br><br><br>
                <span class="small">Date : ____ / ____ / ________</span>
            </td>
        </tr>
        <tr>
            <td colspan="3" class="small muted" style="height: auto; border: 0; text-align: center; padding-top: 4pt;">
                CHOOSE'Tounsi — marketplace tunisienne{{ $support ? ' · Service client : ' . $support : '' }} · Échange {{ $slip['reference'] }} · Commande {{ $slip['order_number'] }}
            </td>
        </tr>
    </table>
</body>
</html>
