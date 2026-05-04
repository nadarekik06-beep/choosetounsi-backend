<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="x-apple-disable-message-reformatting">
  <title>{{ $subject ?? 'ChooseTounsi' }}</title>
  <!--[if mso]>
  <noscript>
    <xml>
      <o:OfficeDocumentSettings>
        <o:PixelsPerInch>96</o:PixelsPerInch>
      </o:OfficeDocumentSettings>
    </xml>
  </noscript>
  <![endif]-->
  <style>
    /* ===== RESET ===== */
    * { box-sizing: border-box; }
    body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
    img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
    body { margin: 0 !important; padding: 0 !important; width: 100% !important; }

    /* ===== BRAND TOKENS ===== */
    :root {
      --ct-red:     #db142e;
      --ct-green:   #198f41;
      --ct-dark:    #0f1117;
      --ct-gold:    #c9963a;
      --ct-cream:   #faf7f2;
      --ct-gray:    #6b7280;
      --ct-light:   #f3f4f6;
    }

    /* ===== TYPOGRAPHY ===== */
    @import url('https://fonts.googleapis.com/css2?family=Barlow:wght@300;400;500;600;700;800;900&family=Barlow+Condensed:wght@600;700;800;900&display=swap');

    /* ===== RESPONSIVE ===== */
    @media only screen and (max-width: 620px) {
      .email-container { width: 100% !important; }
      .hero-title { font-size: 32px !important; line-height: 1.15 !important; }
      .hero-sub { font-size: 15px !important; }
      .btn-cta { width: 100% !important; display: block !important; text-align: center !important; }
      .product-col { width: 50% !important; }
      .hide-mobile { display: none !important; }
      .stack-col { width: 100% !important; display: block !important; }
      .pad-mobile { padding: 24px 20px !important; }
      .feature-icon { font-size: 28px !important; }
    }
  </style>
</head>
<body style="margin:0;padding:0;background-color:#f0ede8;font-family:'Barlow',Arial,sans-serif;">

  <!-- Preheader (hidden preview text) -->
  <div style="display:none;font-size:1px;color:#f0ede8;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">
    {{ $preheader ?? '' }}&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;
  </div>

  <!-- Email Wrapper -->
  <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background:#f0ede8;">
    <tr>
      <td align="center" style="padding:20px 10px;">

        <!-- Main Container -->
        <table role="presentation" class="email-container" cellspacing="0" cellpadding="0" border="0" width="600" style="max-width:600px;background:#ffffff;border-radius:4px;overflow:hidden;box-shadow:0 4px 40px rgba(0,0,0,0.12);">

          <!-- TOP BAR (flag stripe: red-white-green) -->
          <tr>
            <td style="padding:0;line-height:0;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                <tr>
                  <td width="33.33%" height="5" style="background:#db142e;font-size:0;line-height:0;">&nbsp;</td>
                  <td width="33.34%" height="5" style="background:#ffffff;font-size:0;line-height:0;">&nbsp;</td>
                  <td width="33.33%" height="5" style="background:#198f41;font-size:0;line-height:0;">&nbsp;</td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- HEADER / NAV BAR -->
          <tr>
            <td style="background:#0f1117;padding:20px 32px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                <tr>
                  <td>
                    <!-- Logo -->
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                      <tr>
                        <td style="padding-right:10px;vertical-align:middle;">
                          <!-- Flag icon (emoji fallback, replace with real img tag if logo URL is available) -->
                          <span style="font-size:22px;line-height:1;">🇹🇳</span>
                        </td>
                        <td style="vertical-align:middle;">
                          <span style="font-family:'Barlow Condensed',Arial,sans-serif;font-weight:900;font-size:24px;letter-spacing:1px;color:#ffffff;">CHOOSE<span style="color:#db142e;">'</span>TOUNSI</span>
                        </td>
                      </tr>
                    </table>
                  </td>
                  <td align="right" style="vertical-align:middle;">
                    <span style="font-family:'Barlow',Arial,sans-serif;font-size:12px;color:#9ca3af;letter-spacing:0.5px;">MADE IN TUNISIA 🤝</span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- NAV LINKS -->
          <tr>
            <td style="background:#1a1d27;padding:12px 32px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                <tr>
                  <td align="center">
                    <a href="{{ config('app.url') }}" style="font-family:'Barlow',Arial,sans-serif;font-size:12px;font-weight:600;color:#9ca3af;text-decoration:none;letter-spacing:1px;margin:0 12px;text-transform:uppercase;">SHOP</a>
                    <a href="{{ config('app.url') }}/sellers" style="font-family:'Barlow',Arial,sans-serif;font-size:12px;font-weight:600;color:#9ca3af;text-decoration:none;letter-spacing:1px;margin:0 12px;text-transform:uppercase;">SELLERS</a>
                    <a href="{{ config('app.url') }}/deals" style="font-family:'Barlow',Arial,sans-serif;font-size:12px;font-weight:600;color:#db142e;text-decoration:none;letter-spacing:1px;margin:0 12px;text-transform:uppercase;">DEALS</a>
                    <a href="{{ config('app.url') }}/categories" style="font-family:'Barlow',Arial,sans-serif;font-size:12px;font-weight:600;color:#9ca3af;text-decoration:none;letter-spacing:1px;margin:0 12px;text-transform:uppercase;">CATEGORIES</a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- CONTENT SLOT -->
          @yield('content')

          <!-- FOOTER -->
          <tr>
            <td style="background:#0f1117;padding:40px 32px 24px;">

              <!-- Footer Logo -->
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                <tr>
                  <td align="center" style="padding-bottom:20px;">
                    <span style="font-family:'Barlow Condensed',Arial,sans-serif;font-weight:900;font-size:20px;letter-spacing:1px;color:#ffffff;">CHOOSE<span style="color:#db142e;">'</span>TOUNSI</span>
                  </td>
                </tr>

                <!-- Tagline -->
                <tr>
                  <td align="center" style="padding-bottom:24px;">
                    <span style="font-family:'Barlow',Arial,sans-serif;font-size:13px;color:#6b7280;letter-spacing:0.5px;">Tunisia's Marketplace — Supporting Local Commerce</span>
                  </td>
                </tr>

                <!-- Social Icons -->
                <tr>
                  <td align="center" style="padding-bottom:28px;">
                    <a href="#" style="display:inline-block;margin:0 8px;width:36px;height:36px;background:#1a1d27;border-radius:50%;line-height:36px;text-align:center;text-decoration:none;font-size:16px;">📘</a>
                    <a href="#" style="display:inline-block;margin:0 8px;width:36px;height:36px;background:#1a1d27;border-radius:50%;line-height:36px;text-align:center;text-decoration:none;font-size:16px;">📷</a>
                    <a href="#" style="display:inline-block;margin:0 8px;width:36px;height:36px;background:#1a1d27;border-radius:50%;line-height:36px;text-align:center;text-decoration:none;font-size:16px;">🐦</a>
                  </td>
                </tr>

                <!-- Divider -->
                <tr>
                  <td style="border-top:1px solid #1f2937;padding-top:20px;">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                      <tr>
                        <td align="center" style="padding-bottom:8px;">
                          <a href="{{ $unsubscribeUrl ?? '#' }}" style="font-family:'Barlow',Arial,sans-serif;font-size:11px;color:#4b5563;text-decoration:underline;margin:0 8px;">Unsubscribe</a>
                          <span style="color:#4b5563;font-size:11px;">|</span>
                          <a href="{{ config('app.url') }}/privacy" style="font-family:'Barlow',Arial,sans-serif;font-size:11px;color:#4b5563;text-decoration:underline;margin:0 8px;">Privacy Policy</a>
                          <span style="color:#4b5563;font-size:11px;">|</span>
                          <a href="{{ config('app.url') }}/contact" style="font-family:'Barlow',Arial,sans-serif;font-size:11px;color:#4b5563;text-decoration:underline;margin:0 8px;">Contact</a>
                        </td>
                      </tr>
                      <tr>
                        <td align="center">
                          <span style="font-family:'Barlow',Arial,sans-serif;font-size:11px;color:#374151;">© {{ date('Y') }} ChooseTounsi — Sfax, Tunisia. All rights reserved.</span>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- BOTTOM FLAG STRIPE -->
          <tr>
            <td style="padding:0;line-height:0;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                <tr>
                  <td width="33.33%" height="4" style="background:#db142e;font-size:0;line-height:0;">&nbsp;</td>
                  <td width="33.34%" height="4" style="background:#ffffff;font-size:0;line-height:0;">&nbsp;</td>
                  <td width="33.33%" height="4" style="background:#198f41;font-size:0;line-height:0;">&nbsp;</td>
                </tr>
              </table>
            </td>
          </tr>

        </table>
        <!-- /Main Container -->

      </td>
    </tr>
  </table>

</body>
</html>