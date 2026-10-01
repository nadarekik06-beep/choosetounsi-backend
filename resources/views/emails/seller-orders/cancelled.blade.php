{{--
  "Order cancelled" e-mail to a seller: reference, the cancelled products and
  one instruction. No amounts, no reason (internal notes stay internal).
  Data: SellerOrderCancelledNotification (see mailSummary()).
--}}
@extends('emails.layout.transactional', ['preheader' => __('order_notifications.cancelled.intro')])

@php
  $start = $rtl ? 'right' : 'left';
  $muted = 'color:#6b7280;';
  $cell  = 'padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:14px;line-height:20px;vertical-align:top;';
@endphp

@section('content')
  <tr>
    <td class="ct-pad" style="padding:28px 32px 8px;">
      <span style="display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;color:#b91c1c;background:#fee2e2;">{{ __('order_notifications.cancelled.title', ['ref' => $s['reference']]) }}</span>
      <h1 class="ct-h1" style="margin:14px 0 8px;font-size:24px;line-height:30px;font-weight:800;color:#111827;">{{ __('order_notifications.cancelled.headline') }}</h1>
      <p style="margin:0;padding:12px 14px;background:#fee2e2;border-{{ $start }}:4px solid #db142e;border-radius:6px;font-size:15px;line-height:22px;font-weight:700;color:#7f1d1d;">{{ __('order_notifications.cancelled.intro') }}</p>
    </td>
  </tr>

  <tr>
    <td class="ct-pad" style="padding:16px 32px 0;">
      <div style="font-size:12px;text-transform:uppercase;letter-spacing:1px;{{ $muted }}">{{ __('order_notifications.common.reference') }}</div>
      <div dir="ltr" style="margin-top:4px;font-family:'Courier New',Courier,monospace;font-size:20px;font-weight:800;letter-spacing:1px;color:#6b7280;text-decoration:line-through;text-align:{{ $start }};">{{ $s['reference'] }}</div>
    </td>
  </tr>

  <tr>
    <td class="ct-pad" style="padding:20px 32px 0;">
      <div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;{{ $muted }}">{{ __('order_notifications.cancelled.items') }}</div>
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:6px;">
        @foreach ($s['items'] as $item)
          <tr>
            <td style="{{ $cell }}text-align:{{ $start }};">
              <strong>{{ $item['name'] }}</strong>
              @if ($item['variant'])<br><span style="font-size:13px;{{ $muted }}">{{ $item['variant'] }}</span>@endif
            </td>
            <td width="64" style="{{ $cell }}text-align:center;white-space:nowrap;">&times;&nbsp;{{ $item['qty'] }}</td>
          </tr>
        @endforeach
      </table>
    </td>
  </tr>

  <tr>
    <td class="ct-pad" align="center" style="padding:28px 32px 32px;">
      <table role="presentation" cellspacing="0" cellpadding="0" border="0" class="ct-btn" width="100%" style="max-width:320px;">
        <tr>
          <td align="center" style="border-radius:10px;background:#db142e;">
            <a href="{{ $s['dashboard_url'] }}" target="_blank"
               style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;">{{ __('order_notifications.common.view_order') }}</a>
          </td>
        </tr>
      </table>
    </td>
  </tr>
@endsection

@section('footer')
  {{ __('order_notifications.common.reason') }}<br>
  {{ __('order_notifications.common.help', ['email' => config('mail.from.address')]) }}
@endsection
