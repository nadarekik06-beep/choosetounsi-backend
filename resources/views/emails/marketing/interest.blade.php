@extends('emails.layout.master')

@php
  $subject   = __('emails.marketing.interest.subject', ['category' => $category]);
  $preheader = __('emails.marketing.interest.preheader');
  $rtl       = app()->getLocale() === 'ar';
@endphp

@section('content')
  <tr>
    <td class="pad-mobile" style="padding:32px 32px 8px;font-family:'Barlow',Arial,sans-serif;text-align:{{ $rtl ? 'right' : 'left' }};">
      <h1 style="font-size:26px;font-weight:800;color:#111827;margin:0 0 8px;">{{ __('emails.marketing.interest.title', ['category' => $category]) }}</h1>
      <p style="font-size:15px;color:#4b5563;margin:0;line-height:1.55;">{{ __('emails.marketing.interest.intro', ['category' => $category]) }}</p>
    </td>
  </tr>
  <tr>
    <td class="pad-mobile" align="center" style="padding:20px 32px 8px;">
      <table role="presentation" width="280" cellspacing="0" cellpadding="0" border="0" style="max-width:280px;width:100%;" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
        <tr><td>@include('emails.marketing._product', ['item' => $item])</td></tr>
      </table>
    </td>
  </tr>
  <tr>
    <td style="padding:12px 32px 28px;text-align:center;">
      <a class="btn-cta" href="{{ $item['url'] }}"
         style="display:inline-block;background:#db142e;color:#ffffff;font-family:'Barlow',Arial,sans-serif;font-size:14px;font-weight:800;text-decoration:none;padding:13px 26px;border-radius:8px;">
        {{ __('emails.marketing.view') }}
      </a>
      <p style="font-family:'Barlow',Arial,sans-serif;font-size:11px;color:#9ca3af;margin:18px 0 0;line-height:1.5;">{{ __('emails.marketing.why') }}</p>
    </td>
  </tr>
@endsection
