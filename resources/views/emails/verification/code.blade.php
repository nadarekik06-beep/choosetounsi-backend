<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <title>Verify Your Email — ChooseTounsi</title>
  <style>
    body, table, td, a { -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
    body { margin:0; padding:0; background-color:#f1f5f9; font-family:Arial,sans-serif; }
    table { border-collapse:collapse !important; }
    img { border:0; height:auto; line-height:100%; outline:none; text-decoration:none; }
    .digit-box {
      display:inline-block;
      width:52px;
      height:60px;
      line-height:60px;
      text-align:center;
      font-size:28px;
      font-weight:900;
      color:#ffffff;
      background:#1e2130;
      border:2px solid #2d3148;
      border-radius:10px;
      margin:0 4px;
      font-family:'Courier New',monospace;
      letter-spacing:0;
    }
    @media only screen and (max-width:600px) {
      .outer { width:100% !important; }
      .inner { padding:32px 20px !important; }
      .digit-box { width:40px !important; height:50px !important; line-height:50px !important; font-size:22px !important; margin:0 2px !important; }
    }
  </style>
</head>
<body>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
       style="background-color:#f1f5f9; padding:40px 16px;">
  <tr>
    <td align="center">

      <!-- ── OUTER CARD ── -->
      <table class="outer" role="presentation" width="560" cellspacing="0" cellpadding="0" border="0"
             style="background:#0f1117; border-radius:16px; overflow:hidden; box-shadow:0 8px 40px rgba(0,0,0,0.25);">

        <!-- ── TOP RED BAR ── -->
        <tr>
          <td style="background:#db142e; height:5px; font-size:1px; line-height:1px;">&nbsp;</td>
        </tr>

        <!-- ── HEADER ── -->
        <tr>
          <td style="padding:36px 48px 0; text-align:center;">
            <!-- Logo mark -->
            <div style="display:inline-block; background:#db142e; border-radius:12px;
                        width:48px; height:48px; line-height:48px; text-align:center;
                        font-size:22px; margin-bottom:20px;">
              🛍️
            </div>
            <!-- Brand -->
            <div style="font-family:Arial,sans-serif; font-size:20px; font-weight:900;
                        color:#ffffff; letter-spacing:-0.5px; margin-bottom:8px;">
              Choose<span style="color:#db142e;">Tounsi</span>
            </div>
            <!-- Badge -->
            <div style="display:inline-block; background:rgba(219,20,46,0.15);
                        border:1px solid rgba(219,20,46,0.4); border-radius:100px;
                        padding:5px 16px; margin-bottom:28px;">
              <span style="font-family:Arial,sans-serif; font-size:11px; font-weight:700;
                           color:#db142e; letter-spacing:2px; text-transform:uppercase;">
                Email Verification
              </span>
            </div>
          </td>
        </tr>

        <!-- ── GREETING ── -->
        <tr>
          <td class="inner" style="padding:0 48px 24px; text-align:center;">
            <h1 style="margin:0 0 12px; font-family:Arial,sans-serif; font-size:26px;
                       font-weight:900; color:#ffffff; line-height:1.2;">
              Hello, {{ $user->name }}!
            </h1>
            <p style="margin:0; font-family:Arial,sans-serif; font-size:15px;
                      color:#9ca3af; line-height:1.6;">
              Enter the code below to verify your email address<br>
              and complete your registration.
            </p>
          </td>
        </tr>

        <!-- ── CODE DISPLAY ── -->
        <tr>
          <td style="padding:0 48px 32px; text-align:center;">
            <!-- Code card -->
            <div style="background:#161928; border:1px solid #2d3148;
                        border-radius:14px; padding:28px 24px; display:inline-block;">

              <!-- Label -->
              <div style="font-family:Arial,sans-serif; font-size:11px; font-weight:700;
                          color:#6b7280; letter-spacing:2.5px; text-transform:uppercase;
                          margin-bottom:20px;">
                Your verification code
              </div>

              <!-- Digit boxes -->
              <div style="text-align:center;">
                @foreach(str_split($code) as $digit)
                  <span class="digit-box">{{ $digit }}</span>
                @endforeach
              </div>

              <!-- Expiry warning -->
              <div style="margin-top:20px; font-family:Arial,sans-serif; font-size:13px;
                          color:#f97316; font-weight:600;">
                ⏱ Expires in <strong>10 minutes</strong>
              </div>

            </div>
          </td>
        </tr>

        <!-- ── DIVIDER ── -->
        <tr>
          <td style="padding:0 48px;">
            <div style="border-top:1px solid #1e2130;"></div>
          </td>
        </tr>

        <!-- ── SECURITY NOTICE ── -->
        <tr>
          <td style="padding:28px 48px; text-align:center;">
            <p style="margin:0 0 10px; font-family:Arial,sans-serif; font-size:13px;
                      color:#6b7280; line-height:1.6;">
              If you didn't create an account on ChooseTounsi,
              you can safely ignore this email.
            </p>
            <p style="margin:0; font-family:Arial,sans-serif; font-size:12px; color:#4b5563;">
              🔒 Never share this code with anyone.
            </p>
          </td>
        </tr>

        <!-- ── BOTTOM RED BAR ── -->
        <tr>
          <td style="background:#db142e; height:4px; font-size:1px; line-height:1px;">&nbsp;</td>
        </tr>

        <!-- ── FOOTER ── -->
        <tr>
          <td style="background:#080b12; padding:20px 48px; text-align:center;">
            <p style="margin:0; font-family:Arial,sans-serif; font-size:11px; color:#374151;">
              © {{ date('Y') }} ChooseTounsi · Tunisia's Local Marketplace
            </p>
          </td>
        </tr>

      </table>
      <!-- ── END OUTER CARD ── -->

    </td>
  </tr>
</table>

</body>
</html>