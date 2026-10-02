{{-- Forecast alert e-mail. Data: App\Notifications\ForecastAlertNotification::toMail() --}}
@extends('emails.layout.transactional', ['preheader' => $body])

@section('content')
  <tr>
    <td class="ct-pad" style="padding:28px 32px 8px;">
      <h1 class="ct-h1" style="margin:0 0 10px;font-size:22px;line-height:28px;font-weight:800;color:#111827;">{{ $title }}</h1>
      <p style="margin:0;font-size:15px;line-height:22px;color:#374151;">{{ $body }}</p>
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
