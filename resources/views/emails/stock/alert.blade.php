{{-- Grouped stock alert e-mail (low stock / out of stock). Data: App\Notifications\StockAlertNotification::toMail() --}}
@extends('emails.layout.transactional', ['preheader' => $intro])

@php
  $start = $rtl ? 'right' : 'left';
  $end   = $rtl ? 'left' : 'right';
  $cell  = 'padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:14px;line-height:20px;vertical-align:top;';
@endphp

@section('content')
  <tr>
    <td class="ct-pad" style="padding:28px 32px 8px;">
      <h1 class="ct-h1" style="margin:0 0 10px;font-size:22px;line-height:28px;font-weight:800;color:#111827;">{{ $title }}</h1>
      <p style="margin:0;font-size:15px;line-height:22px;color:#374151;">{{ $intro }}</p>
    </td>
  </tr>
  <tr>
    <td class="ct-pad" style="padding:12px 32px 0;">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
        @foreach ($items as $item)
          <tr>
            <td style="{{ $cell }}text-align:{{ $start }};"><strong>{{ $item['name'] }}</strong></td>
            <td style="{{ $cell }}text-align:{{ $end }};color:#b45309;">{{ $item['line'] }}</td>
          </tr>
        @endforeach
      </table>
    </td>
  </tr>
  <tr>
    <td class="ct-pad ct-btn" style="padding:20px 32px 28px;">
      <a href="{{ $url }}" style="display:inline-block;padding:12px 22px;border-radius:10px;background:#db142e;color:#ffffff;font-weight:700;font-size:15px;text-decoration:none;">{{ $cta }}</a>
    </td>
  </tr>
@endsection

@section('footer')
  {{ $footer }}
@endsection
