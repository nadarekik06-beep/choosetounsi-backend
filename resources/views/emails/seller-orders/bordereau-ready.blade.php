{{--
  "Bordereau ready" e-mail to a seller: the Intigo parcel exists, print the
  bordereau from the order page. Links to the dashboard only, never to the PDF.
  Data: SellerBordereauReadyNotification (see mailSummary()).
--}}
@extends('emails.layout.transactional', ['preheader' => __('order_notifications.bordereau_ready.intro')])

@php
  $start = $rtl ? 'right' : 'left';
  $muted = 'color:#6b7280;';
@endphp

@section('content')
  <tr>
    <td class="ct-pad" style="padding:28px 32px 8px;">
      <span style="display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;color:#1d4ed8;background:#dbeafe;">{{ __('order_notifications.bordereau_ready.title', ['ref' => $s['reference']]) }}</span>
      <h1 class="ct-h1" style="margin:14px 0 8px;font-size:24px;line-height:30px;font-weight:800;color:#111827;">{{ __('order_notifications.bordereau_ready.headline') }}</h1>
      <p style="margin:0;font-size:15px;line-height:22px;color:#374151;">{{ __('order_notifications.bordereau_ready.intro') }}</p>
    </td>
  </tr>

  <tr>
    <td class="ct-pad" style="padding:16px 32px 0;">
      <div style="font-size:12px;text-transform:uppercase;letter-spacing:1px;{{ $muted }}">{{ __('order_notifications.common.reference') }}</div>
      <div dir="ltr" style="margin-top:4px;font-family:'Courier New',Courier,monospace;font-size:20px;font-weight:800;letter-spacing:1px;color:#db142e;text-align:{{ $start }};">{{ $s['reference'] }}</div>
      <p style="margin:14px 0 0;font-size:14px;line-height:21px;color:#111827;font-weight:600;">{{ __('order_notifications.bordereau_ready.next') }}</p>
    </td>
  </tr>

  <tr>
    <td class="ct-pad" align="center" style="padding:28px 32px 32px;">
      <table role="presentation" cellspacing="0" cellpadding="0" border="0" class="ct-btn" width="100%" style="max-width:320px;">
        <tr>
          <td align="center" style="border-radius:10px;background:#db142e;">
            <a href="{{ $s['dashboard_url'] }}" target="_blank"
               style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;">{{ __('order_notifications.bordereau_ready.cta') }}</a>
          </td>
        </tr>
      </table>
    </td>
  </tr>
@endsection

@section('footer')
  {{ __('order_notifications.common.reason') }}<br>
  {{ __('order_notifications.common.help', ['email' => config('mail.contact_to')]) }}
@endsection
