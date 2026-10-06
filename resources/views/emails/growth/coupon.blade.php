{{-- Targeted coupon (Growth Radar → warm audience). Data: App\Mail\Growth\TargetedCouponMail --}}
@extends('emails.layout.master')

@php
  $subject   = __('growth.coupon.subject', ['product' => $c['product']]);
  $preheader = __('growth.coupon.preheader', ['discount' => $c['discount']]);
  $rtl       = app()->getLocale() === 'ar';
@endphp

@section('content')
  <tr>
    <td class="pad-mobile" style="padding:32px 32px 8px;font-family:'Barlow',Arial,sans-serif;text-align:{{ $rtl ? 'right' : 'left' }};">
      <h1 style="font-size:24px;font-weight:800;color:#111827;margin:0 0 8px;">{{ __('growth.coupon.title', ['discount' => $c['discount'], 'product' => $c['product']]) }}</h1>
      <p style="font-size:15px;color:#4b5563;margin:0;line-height:1.55;">{{ __('growth.coupon.intro', ['shop' => $c['shop'], 'product' => $c['product']]) }}</p>
    </td>
  </tr>
  <tr>
    <td align="center" style="padding:20px 32px 4px;">
      <div style="display:inline-block;border:2px dashed #db142e;border-radius:12px;padding:14px 28px;font-family:'Courier New',monospace;font-size:22px;font-weight:800;letter-spacing:2px;color:#db142e;">{{ $c['code'] }}</div>
      @if (!empty($c['expires']))
        <p style="font-family:'Barlow',Arial,sans-serif;font-size:12px;color:#6b7280;margin:8px 0 0;">{{ __('growth.coupon.expires', ['date' => $c['expires']]) }}</p>
      @endif
    </td>
  </tr>
  <tr>
    <td style="padding:16px 32px 28px;text-align:center;">
      <a class="btn-cta" href="{{ $c['url'] }}"
         style="display:inline-block;background:#db142e;color:#ffffff;font-family:'Barlow',Arial,sans-serif;font-size:14px;font-weight:800;text-decoration:none;padding:13px 26px;border-radius:8px;">
        {{ __('emails.marketing.view') }}
      </a>
      <p style="font-family:'Barlow',Arial,sans-serif;font-size:11px;color:#9ca3af;margin:18px 0 0;line-height:1.5;">{{ __('emails.marketing.why') }}</p>
    </td>
  </tr>
@endsection
