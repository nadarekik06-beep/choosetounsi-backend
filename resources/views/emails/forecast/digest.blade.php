{{-- Weekly forecast digest. Data: App\Notifications\ForecastDigestNotification::toMail() --}}
@extends('emails.layout.transactional', ['preheader' => $next28])

@php $muted = 'color:#6b7280;'; @endphp

@section('content')
  <tr>
    <td class="ct-pad" style="padding:28px 32px 8px;">
      <h1 class="ct-h1" style="margin:0 0 10px;font-size:22px;line-height:28px;font-weight:800;color:#111827;">{{ $headline }}</h1>
      <p style="margin:0;font-size:15px;line-height:22px;color:#374151;">{{ $next28 }}</p>
      @if ($accuracy)
        <p style="margin:8px 0 0;font-size:13px;line-height:20px;{{ $muted }}">{{ $accuracy }}</p>
      @endif
    </td>
  </tr>
  @if ($atRisk)
    <tr>
      <td class="ct-pad" style="padding:16px 32px 0;">
        <div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;{{ $muted }}">{{ __('forecast.digest.at_risk') }}</div>
        @foreach ($atRisk as $row)
          <p style="margin:6px 0 0;font-size:14px;line-height:20px;color:#b91c1c;">• {{ $row }}</p>
        @endforeach
      </td>
    </tr>
  @endif
  @if ($events)
    <tr>
      <td class="ct-pad" style="padding:16px 32px 0;">
        <div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;{{ $muted }}">{{ __('forecast.digest.events') }}</div>
        @foreach ($events as $row)
          <p style="margin:6px 0 0;font-size:14px;line-height:20px;color:#374151;">• {{ $row }}</p>
        @endforeach
      </td>
    </tr>
  @endif
  @if (!$atRisk && !$events)
    <tr><td class="ct-pad" style="padding:16px 32px 0;font-size:14px;{{ $muted }}">{{ __('forecast.digest.nothing') }}</td></tr>
  @endif
  <tr>
    <td class="ct-pad ct-btn" style="padding:22px 32px 28px;">
      <a href="{{ $url }}" style="display:inline-block;padding:12px 22px;border-radius:10px;background:#db142e;color:#ffffff;font-weight:700;font-size:15px;text-decoration:none;">{{ $cta }}</a>
    </td>
  </tr>
@endsection

@section('footer')
  {{ $footer }}
@endsection
