{{--
  Transactional e-mail layout (order notifications to sellers…).
  No marketing nav and no unsubscribe link: these are always sent.
  Inline styles + one fluid 600px column so it renders in Gmail on a phone.
  Expects: $subject, $preheader, $rtl, $logoUrl (optional).
--}}
@php
  $dir   = $rtl ? 'rtl' : 'ltr';
  $start = $rtl ? 'right' : 'left';
  $font  = $rtl ? "Tahoma,'Segoe UI',Arial,sans-serif" : "'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="x-apple-disable-message-reformatting">
  <meta name="color-scheme" content="light">
  <meta name="supported-color-schemes" content="light">
  <title>{{ $subject }}</title>
  <style>
    body { margin:0 !important; padding:0 !important; width:100% !important; }
    table, td { mso-table-lspace:0pt; mso-table-rspace:0pt; }
    img { border:0; height:auto; line-height:100%; outline:none; text-decoration:none; }
    @media only screen and (max-width:620px) {
      .ct-pad { padding-left:20px !important; padding-right:20px !important; }
      .ct-h1  { font-size:22px !important; }
      .ct-btn a { display:block !important; }
    }
  </style>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;" dir="{{ $dir }}">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#f3f4f6;font-size:1px;line-height:1px;">{{ $preheader ?? '' }}&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;</div>

  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f3f4f6;">
    <tr>
      <td align="center" style="padding:16px 8px;">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" dir="{{ $dir }}"
               style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;font-family:{{ $font }};color:#111827;text-align:{{ $start }};">

          {{-- Flag stripe --}}
          <tr>
            <td style="padding:0;line-height:0;font-size:0;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>
                <td width="34%" height="5" style="background:#db142e;">&nbsp;</td>
                <td width="33%" height="5" style="background:#ffffff;">&nbsp;</td>
                <td width="33%" height="5" style="background:#198f41;">&nbsp;</td>
              </tr></table>
            </td>
          </tr>

          {{-- Header --}}
          <tr>
            <td class="ct-pad" style="background:#0f1117;padding:18px 32px;" align="{{ $start }}">
              @if (!empty($logoUrl))
                <img src="{{ $logoUrl }}" alt="Choose'Tounsi" width="160" style="display:block;width:160px;max-width:160px;">
              @else
                <span dir="ltr" style="font-family:Arial,Helvetica,sans-serif;font-weight:900;font-size:22px;letter-spacing:1px;color:#ffffff;">CHOOSE<span style="color:#db142e;">'</span>TOUNSI</span>
              @endif
            </td>
          </tr>

          @yield('content')

          {{-- Footer --}}
          <tr>
            <td class="ct-pad" style="background:#f9fafb;border-top:1px solid #e5e7eb;padding:20px 32px;font-size:12px;line-height:18px;color:#6b7280;">
              @yield('footer')
              <div style="margin-top:8px;">&copy; {{ date('Y') }} Choose'Tounsi</div>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
