{{-- One product tile for marketing e-mails. $item: see App\Services\Ads\AdEmailService::item() --}}
@php $rtl = app()->getLocale() === 'ar'; @endphp
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
       style="border:1px solid {{ $item['sponsored'] ? '#f4c26b' : '#eeeeee' }};border-radius:10px;overflow:hidden;background:#ffffff;">
  <tr>
    <td style="padding:0;line-height:0;">
      <a href="{{ $item['url'] }}" style="text-decoration:none;">
        @if ($item['image'])
          <img src="{{ $item['image'] }}" alt="{{ $item['name'] }}" width="100%" style="display:block;width:100%;max-width:100%;height:auto;border:0;">
        @else
          <div style="height:160px;background:#f5f5f5;"></div>
        @endif
      </a>
    </td>
  </tr>
  <tr>
    <td style="padding:10px 12px 12px;font-family:'Barlow',Arial,sans-serif;text-align:{{ $rtl ? 'right' : 'left' }};">
      @if ($item['sponsored'])
        <span style="display:inline-block;font-size:10px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:#92400e;background:#fef3c7;border:1px solid #f4c26b;border-radius:999px;padding:2px 8px;margin-bottom:6px;">
          {{ __('emails.marketing.sponsored') }}
        </span><br>
      @endif
      <a href="{{ $item['url'] }}" style="font-size:14px;font-weight:700;color:#111827;text-decoration:none;line-height:1.35;">{{ $item['name'] }}</a>
      @if (!empty($item['copy']))
        <p style="font-size:12px;color:#6b7280;margin:4px 0 0;line-height:1.4;">{{ $item['copy'] }}</p>
      @endif
      <p style="margin:6px 0 0;font-size:15px;font-weight:800;color:#db142e;">
        {{ number_format($item['price'], 3, ',', ' ') }} DT
        @if ($item['original'])
          <span style="font-size:12px;font-weight:500;color:#9ca3af;text-decoration:line-through;">{{ number_format($item['original'], 3, ',', ' ') }} DT</span>
        @endif
      </p>
      @if (!empty($item['free_delivery']))
        <p style="margin:3px 0 0;font-size:11px;font-weight:700;color:#198f41;">{{ __('emails.marketing.interest.free_delivery') }}</p>
      @endif
    </td>
  </tr>
</table>
