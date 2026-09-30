@extends('emails.layout.master')

@php
  $subject   = __('emails.marketing.digest.subject');
  $preheader = __('emails.marketing.digest.preheader');
  $rtl       = app()->getLocale() === 'ar';
  $rows      = array_chunk($items, 2);
@endphp

@section('content')
  <tr>
    <td class="pad-mobile" style="padding:32px 32px 8px;font-family:'Barlow',Arial,sans-serif;text-align:{{ $rtl ? 'right' : 'left' }};">
      <h1 style="font-size:26px;font-weight:800;color:#111827;margin:0 0 8px;">{{ __('emails.marketing.digest.title', ['name' => $user->name]) }}</h1>
      <p style="font-size:15px;color:#4b5563;margin:0;line-height:1.55;">{{ __('emails.marketing.digest.intro') }}</p>
    </td>
  </tr>
  <tr>
    <td class="pad-mobile" style="padding:16px 24px 8px;">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
        @foreach ($rows as $row)
          <tr>
            @foreach ($row as $item)
              <td class="product-col" width="50%" valign="top" style="padding:8px;">
                @include('emails.marketing._product', ['item' => $item])
              </td>
            @endforeach
            @if (count($row) === 1)
              <td class="product-col" width="50%" style="padding:8px;"></td>
            @endif
          </tr>
        @endforeach
      </table>
    </td>
  </tr>
  <tr>
    <td style="padding:8px 32px 28px;text-align:center;">
      <a class="btn-cta" href="{{ rtrim(config('app.frontend_url'), '/') }}?utm_source=email&utm_medium=digest"
         style="display:inline-block;background:#db142e;color:#ffffff;font-family:'Barlow',Arial,sans-serif;font-size:14px;font-weight:800;text-decoration:none;padding:13px 26px;border-radius:8px;">
        {{ __('emails.marketing.see_more') }}
      </a>
      <p style="font-family:'Barlow',Arial,sans-serif;font-size:11px;color:#9ca3af;margin:18px 0 0;line-height:1.5;">{{ __('emails.marketing.why') }}</p>
    </td>
  </tr>
@endsection
