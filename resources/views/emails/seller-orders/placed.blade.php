{{--
  "New order" e-mail to a seller: what was ordered, nothing financial beyond
  item prices. Data: NewSellerOrderNotification (see mailSummary()).
--}}
@extends('emails.layout.transactional', ['preheader' => __('order_notifications.placed.intro')])

@php
  $start = $rtl ? 'right' : 'left';
  $end   = $rtl ? 'left' : 'right';
  $muted = 'color:#6b7280;';
  $cell  = 'padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:14px;line-height:20px;vertical-align:top;';
@endphp

@section('content')
  <tr>
    <td class="ct-pad" style="padding:28px 32px 8px;">
      <span style="display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;color:#b45309;background:#fef3c7;">{{ __('order_notifications.placed.title', ['ref' => $s['reference']]) }}</span>
      <h1 class="ct-h1" style="margin:14px 0 8px;font-size:24px;line-height:30px;font-weight:800;color:#111827;">{{ __('order_notifications.placed.headline') }}</h1>
      <p style="margin:0 0 6px;font-size:15px;line-height:22px;">
        {{ $sellerName !== '' ? __('order_notifications.common.greeting', ['name' => $sellerName]) : __('order_notifications.common.greeting_anon') }}
      </p>
      <p style="margin:0;font-size:15px;line-height:22px;color:#374151;font-weight:600;">{{ __('order_notifications.placed.intro') }}</p>
    </td>
  </tr>

  {{-- Reference + date --}}
  <tr>
    <td class="ct-pad" style="padding:16px 32px 4px;">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:2px dashed #db142e;border-radius:10px;">
        <tr>
          <td align="center" style="padding:14px 12px;">
            <div style="font-size:12px;text-transform:uppercase;letter-spacing:1px;{{ $muted }}">{{ __('order_notifications.common.reference') }}</div>
            <div dir="ltr" style="margin-top:4px;font-family:'Courier New',Courier,monospace;font-size:22px;font-weight:800;letter-spacing:1px;color:#db142e;">{{ $s['reference'] }}</div>
            <div style="margin-top:6px;font-size:13px;{{ $muted }}">{{ __('order_notifications.common.order_date') }}: {{ $s['order_date'] }}</div>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  {{-- Items --}}
  <tr>
    <td class="ct-pad" style="padding:20px 32px 0;">
      <div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;{{ $muted }}">{{ __('order_notifications.common.items') }}</div>
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:6px;">
        @foreach ($s['items'] as $item)
          <tr>
            @if ($item['image'])
              <td width="64" style="{{ $cell }}padding-{{ $end }}:12px;">
                <img src="{{ $item['image'] }}" alt="{{ $item['name'] }}" width="56" height="56"
                     style="display:block;width:56px;height:56px;object-fit:cover;border-radius:8px;border:1px solid #e5e7eb;background:#f3f4f6;">
              </td>
            @endif
            <td style="{{ $cell }}text-align:{{ $start }};" @unless ($item['image']) colspan="2" @endunless>
              <strong>{{ $item['name'] }}</strong>
              @if ($item['variant'])<br><span style="font-size:13px;{{ $muted }}">{{ $item['variant'] }}</span>@endif
              <br><span style="font-size:13px;{{ $muted }}">{{ __('order_notifications.common.qty') }}: {{ $item['qty'] }}@if ($item['unit_price']) &nbsp;&times;&nbsp;<span style="white-space:nowrap;">{{ $item['unit_price'] }}</span>@endif</span>
            </td>
            <td width="96" style="{{ $cell }}text-align:{{ $end }};white-space:nowrap;font-weight:700;">{{ $item['total'] ?? '' }}</td>
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
