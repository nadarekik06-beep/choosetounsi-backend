{{--
  Seller sub-order e-mail: confirmed | pickup_reminder (new order and
  cancelled have their own minimal views: placed / cancelled.blade.php).
  Data: App\Notifications\Orders\SellerOrderNotification::toMail()
  Privacy: buyer's first name + wilaya only — never their phone or address.
--}}
@extends('emails.layout.transactional', ['preheader' => __("order_notifications.$event.intro")])

@php
  $start  = $rtl ? 'right' : 'left';
  $end    = $rtl ? 'left' : 'right';
  $k      = "order_notifications.$event";
  $badge  = [
    'confirmed'       => ['#15803d', '#dcfce7'],
    'pickup_reminder' => ['#b45309', '#fef3c7'],
  ][$event];
  $muted  = 'color:#6b7280;';
  $cell   = 'padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:14px;line-height:20px;vertical-align:top;';
@endphp

@section('content')
  {{-- Headline --}}
  <tr>
    <td class="ct-pad" style="padding:28px 32px 8px;">
      <span style="display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;color:{{ $badge[0] }};background:{{ $badge[1] }};">{{ __("$k.title", ['ref' => $s['reference']]) }}</span>
      <h1 class="ct-h1" style="margin:14px 0 8px;font-size:24px;line-height:30px;font-weight:800;color:#111827;">{{ __("$k.headline") }}</h1>
      <p style="margin:0 0 6px;font-size:15px;line-height:22px;">
        {{ $sellerName !== '' ? __('order_notifications.common.greeting', ['name' => $sellerName]) : __('order_notifications.common.greeting_anon') }}
      </p>
      <p style="margin:0;font-size:15px;line-height:22px;color:#374151;">{{ __("$k.intro") }}</p>
    </td>
  </tr>

  {{-- Reference --}}
  <tr>
    <td class="ct-pad" style="padding:16px 32px 4px;">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:2px dashed #db142e;border-radius:10px;">
        <tr>
          <td align="center" style="padding:14px 12px;">
            <div style="font-size:12px;text-transform:uppercase;letter-spacing:1px;{{ $muted }}">
              {{ __("$k.ref_hint") }}
            </div>
            <div dir="ltr" style="margin-top:4px;font-family:'Courier New',Courier,monospace;font-size:22px;font-weight:800;letter-spacing:1px;color:#db142e;">{{ $s['reference'] }}</div>
            @if ($s['buyer'] !== '')
              <div style="margin-top:6px;font-size:13px;{{ $muted }}">{{ __('order_notifications.common.buyer') }}: {{ $s['buyer'] }}</div>
            @endif
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
            <td style="{{ $cell }}text-align:{{ $start }};">
              <strong>{{ $item['name'] }}</strong>
              @if ($item['variant'])<br><span style="font-size:13px;{{ $muted }}">{{ $item['variant'] }}</span>@endif
            </td>
            <td width="56" style="{{ $cell }}text-align:center;white-space:nowrap;">&times;&nbsp;{{ $item['qty'] }}</td>
            <td width="96" style="{{ $cell }}text-align:{{ $end }};white-space:nowrap;">{{ $item['total'] ?? '' }}</td>
          </tr>
        @endforeach
      </table>
    </td>
  </tr>

  {{-- Money --}}
  <tr>
    <td class="ct-pad" style="padding:8px 32px 0;">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="font-size:14px;line-height:20px;">
        <tr>
          <td style="padding:4px 0;text-align:{{ $start }};{{ $muted }}">{{ __('order_notifications.common.items_total') }}</td>
          <td style="padding:4px 0;text-align:{{ $end }};white-space:nowrap;">{{ $s['items_total'] }}</td>
        </tr>
        @if ($s['discount'])
          <tr>
            <td style="padding:4px 0;text-align:{{ $start }};{{ $muted }}">{{ __('order_notifications.common.discount') }}</td>
            <td style="padding:4px 0;text-align:{{ $end }};white-space:nowrap;">&minus;&nbsp;{{ $s['discount'] }}</td>
          </tr>
        @endif
        <tr>
          <td style="padding:4px 0;text-align:{{ $start }};{{ $muted }}">{{ __('order_notifications.common.commission') }}</td>
          <td style="padding:4px 0;text-align:{{ $end }};white-space:nowrap;">&minus;&nbsp;{{ $s['commission'] }}</td>
        </tr>
        @if ($s['shipping'])
          <tr>
            <td style="padding:4px 0;text-align:{{ $start }};{{ $muted }}">{{ __('order_notifications.common.shipping') }}</td>
            <td style="padding:4px 0;text-align:{{ $end }};white-space:nowrap;">&minus;&nbsp;{{ $s['shipping'] }}</td>
          </tr>
        @endif
        <tr>
          <td style="padding:10px 0 4px;border-top:2px solid #111827;text-align:{{ $start }};font-weight:800;">{{ __('order_notifications.common.net') }}</td>
          <td style="padding:10px 0 4px;border-top:2px solid #111827;text-align:{{ $end }};font-weight:800;white-space:nowrap;color:#198f41;">{{ $s['net_label'] }}</td>
        </tr>
      </table>
    </td>
  </tr>

  {{-- Pickup + packing tips (confirmed, reminder) --}}
  @if ($pickup)
    <tr>
      <td class="ct-pad" style="padding:22px 32px 0;">
        <div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;{{ $muted }}">{{ __('order_notifications.pickup.heading') }}</div>
        <div style="margin-top:6px;padding:12px 14px;background:#f9fafb;border-radius:8px;font-size:14px;line-height:21px;">
          @if ($pickup['shop'])<strong>{{ $pickup['shop'] }}</strong><br>@endif
          {{ $pickup['address'] ?: '—' }}
          @if ($pickup['phone'])<br><span dir="ltr">{{ $pickup['phone'] }}</span>@endif
        </div>
        @unless ($pickup['complete'])
          <div style="margin-top:10px;padding:12px 14px;background:#fef3c7;border-{{ $start }}:4px solid #f59e0b;border-radius:6px;font-size:14px;line-height:21px;color:#78350f;">
            &#9888;&#65039; {{ __('order_notifications.pickup.incomplete', ['fields' => implode(', ', $pickup['missing'])]) }}<br>
            <a href="{{ $pickup['settings_url'] }}" style="color:#b45309;font-weight:700;">{{ __('order_notifications.pickup.fix') }}</a>
          </div>
        @endunless
      </td>
    </tr>
    <tr>
      <td class="ct-pad" style="padding:22px 32px 0;">
        <div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;{{ $muted }}">{{ __('order_notifications.tips.heading') }}</div>
        <ul style="margin:8px 0 0;padding-{{ $start }}:20px;font-size:14px;line-height:22px;color:#374151;">
          @foreach (__('order_notifications.tips.list') as $tip)
            <li style="margin:0 0 4px;">{{ str_replace(':ref', $s['reference'], $tip) }}</li>
          @endforeach
        </ul>
        <p style="margin:12px 0 0;font-size:14px;line-height:21px;color:#111827;font-weight:600;">{{ __("$k.next") }}</p>
      </td>
    </tr>
  @endif

  {{-- CTA --}}
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
