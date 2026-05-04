@extends('emails.layout.master')

@php
  $subject   = 'Application Received — We\'re Reviewing It ✅';
  $preheader = 'Your seller application is in our hands, ' . ($seller->name ?? 'friend') . '. Here\'s what happens next.';
@endphp

@section('content')

  {{-- ================================================================
       HERO: STATUS CONFIRMED
  ================================================================ --}}
  <tr>
    <td style="background:linear-gradient(160deg,#0f1117 0%,#111827 100%);padding:0;">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">

        <!-- Status icon -->
        <tr>
          <td align="center" style="padding:48px 32px 20px;">
            <div style="display:inline-block;width:80px;height:80px;background:rgba(25,143,65,0.15);border:2px solid #198f41;border-radius:50%;line-height:76px;text-align:center;font-size:36px;">
              📋
            </div>
          </td>
        </tr>

        <!-- Status badge -->
        <tr>
          <td align="center" style="padding:0 32px 16px;">
            <div style="display:inline-block;background:rgba(25,143,65,0.12);border:1px solid rgba(25,143,65,0.5);border-radius:100px;padding:6px 20px;">
              <span style="font-family:'Barlow',Arial,sans-serif;font-size:11px;font-weight:700;color:#4ade80;letter-spacing:2.5px;text-transform:uppercase;">✅ Application Submitted</span>
            </div>
          </td>
        </tr>

        <!-- Headline -->
        <tr>
          <td align="center" style="padding:0 32px 16px;">
            <h1 class="hero-title" style="margin:0;font-family:'Barlow Condensed',Arial,sans-serif;font-size:46px;font-weight:900;line-height:1.1;color:#ffffff;text-transform:uppercase;letter-spacing:-0.5px;">
              We've Got<br><span style="color:#198f41;">Your Application</span>!
            </h1>
          </td>
        </tr>

        <!-- Sub -->
        <tr>
          <td align="center" style="padding:0 48px 48px;">
            <p class="hero-sub" style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:15px;font-weight:400;line-height:1.7;color:#9ca3af;">
              Marhba bik, <strong style="color:#e5e7eb;">{{ $seller->name ?? 'Seller' }}</strong>! Your application to join ChooseTounsi as a seller has been successfully received. Our team is reviewing your details.
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>

  {{-- ================================================================
       PROGRESS TRACKER
  ================================================================ --}}
  <tr>
    <td style="background:#faf7f2;padding:48px 40px 40px;" class="pad-mobile">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">

        <tr>
          <td align="center" style="padding-bottom:8px;">
            <span style="font-family:'Barlow',Arial,sans-serif;font-size:11px;font-weight:700;color:#db142e;letter-spacing:3px;text-transform:uppercase;">Where You Stand</span>
          </td>
        </tr>
        <tr>
          <td align="center" style="padding-bottom:36px;">
            <h2 style="margin:0;font-family:'Barlow Condensed',Arial,sans-serif;font-size:30px;font-weight:800;color:#0f1117;text-transform:uppercase;">Application Progress</h2>
          </td>
        </tr>

        <!-- Steps -->

        <!-- Step 1 — COMPLETE -->
        <tr>
          <td style="padding-bottom:4px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
              <tr>
                <td width="52" style="vertical-align:top;padding-right:16px;text-align:center;">
                  <div style="width:44px;height:44px;background:#198f41;border-radius:50%;line-height:44px;text-align:center;font-size:20px;display:inline-block;">✅</div>
                  <div style="width:2px;height:32px;background:#e5e7eb;margin:4px auto 0;"></div>
                </td>
                <td style="vertical-align:top;padding-top:8px;">
                  <p style="margin:0 0 2px;font-family:'Barlow',Arial,sans-serif;font-size:14px;font-weight:700;color:#198f41;text-transform:uppercase;letter-spacing:0.5px;">Step 1 — Completed</p>
                  <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:13px;color:#374151;">Application submitted with your business details and preferred plan.</p>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Step 2 — IN PROGRESS -->
        <tr>
          <td style="padding-bottom:4px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
              <tr>
                <td width="52" style="vertical-align:top;padding-right:16px;text-align:center;">
                  <div style="width:44px;height:44px;background:#db142e;border-radius:50%;line-height:40px;text-align:center;font-size:20px;display:inline-block;border:2px solid #db142e;">🔍</div>
                  <div style="width:2px;height:32px;background:#e5e7eb;margin:4px auto 0;"></div>
                </td>
                <td style="vertical-align:top;padding-top:8px;">
                  <p style="margin:0 0 2px;font-family:'Barlow',Arial,sans-serif;font-size:14px;font-weight:700;color:#db142e;text-transform:uppercase;letter-spacing:0.5px;">Step 2 — In Progress ← You are here</p>
                  <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:13px;color:#374151;">Our team reviews your application. This typically takes <strong>1–3 business days</strong>.</p>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Step 3 — PENDING -->
        <tr>
          <td style="padding-bottom:4px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
              <tr>
                <td width="52" style="vertical-align:top;padding-right:16px;text-align:center;">
                  <div style="width:44px;height:44px;background:#f3f4f6;border-radius:50%;line-height:44px;text-align:center;font-size:20px;display:inline-block;border:2px solid #e5e7eb;">📧</div>
                  <div style="width:2px;height:32px;background:#e5e7eb;margin:4px auto 0;"></div>
                </td>
                <td style="vertical-align:top;padding-top:8px;">
                  <p style="margin:0 0 2px;font-family:'Barlow',Arial,sans-serif;font-size:14px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:0.5px;">Step 3 — Decision Email</p>
                  <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:13px;color:#9ca3af;">You'll receive an email with the decision and next steps.</p>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Step 4 — PENDING -->
        <tr>
          <td>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
              <tr>
                <td width="52" style="vertical-align:top;padding-right:16px;text-align:center;">
                  <div style="width:44px;height:44px;background:#f3f4f6;border-radius:50%;line-height:44px;text-align:center;font-size:20px;display:inline-block;border:2px solid #e5e7eb;">🚀</div>
                </td>
                <td style="vertical-align:top;padding-top:8px;">
                  <p style="margin:0 0 2px;font-family:'Barlow',Arial,sans-serif;font-size:14px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:0.5px;">Step 4 — Start Selling!</p>
                  <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:13px;color:#9ca3af;">Once approved, access your dashboard and list your first products.</p>
                </td>
              </tr>
            </table>
          </td>
        </tr>

      </table>
    </td>
  </tr>

  {{-- ================================================================
       APPLICATION SUMMARY BOX
  ================================================================ --}}
  <tr>
    <td style="background:#ffffff;padding:0 40px 48px;" class="pad-mobile">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">

        <tr>
          <td style="padding-bottom:20px;">
            <h3 style="margin:0;font-family:'Barlow Condensed',Arial,sans-serif;font-size:20px;font-weight:800;color:#0f1117;text-transform:uppercase;">📋 Application Summary</h3>
          </td>
        </tr>

        <!-- Summary card -->
        <tr>
          <td style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:4px;border-left:4px solid #db142e;padding:20px 24px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
              <tr>
                <td style="padding-bottom:10px;">
                  <span style="font-family:'Barlow',Arial,sans-serif;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;">Applicant Name</span><br>
                  <span style="font-family:'Barlow',Arial,sans-serif;font-size:15px;font-weight:700;color:#0f1117;">{{ $seller->name ?? 'N/A' }}</span>
                </td>
              </tr>
              <tr>
                <td style="padding-bottom:10px;">
                  <span style="font-family:'Barlow',Arial,sans-serif;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;">Email Address</span><br>
                  <span style="font-family:'Barlow',Arial,sans-serif;font-size:15px;font-weight:700;color:#0f1117;">{{ $seller->email ?? 'N/A' }}</span>
                </td>
              </tr>
              <tr>
                <td style="padding-bottom:10px;">
                  <span style="font-family:'Barlow',Arial,sans-serif;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;">Preferred Plan</span><br>
                  <span style="font-family:'Barlow',Arial,sans-serif;font-size:15px;font-weight:700;color:#db142e;">
                    @if(($application->preferred_plan ?? 'green') === 'black')
                      ⚫ Black Pepper — 129 DT/mo
                    @elseif(($application->preferred_plan ?? 'green') === 'red')
                      🔴 Red Pepper — 49 DT/mo
                    @else
                      🟢 Green Pepper — Free
                    @endif
                  </span>
                </td>
              </tr>
              <tr>
                <td>
                  <span style="font-family:'Barlow',Arial,sans-serif;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;">Submitted On</span><br>
                  <span style="font-family:'Barlow',Arial,sans-serif;font-size:15px;font-weight:700;color:#0f1117;">{{ ($application->created_at ?? now())->format('d M Y — H:i') }}</span>
                </td>
              </tr>
            </table>
          </td>
        </tr>

      </table>
    </td>
  </tr>

  {{-- ================================================================
       WHAT TO DO WHILE WAITING
  ================================================================ --}}
  <tr>
    <td style="background:#0f1117;padding:48px 40px;" class="pad-mobile">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">

        <tr>
          <td align="center" style="padding-bottom:8px;">
            <span style="font-family:'Barlow',Arial,sans-serif;font-size:11px;font-weight:700;color:#db142e;letter-spacing:3px;text-transform:uppercase;">In the meantime</span>
          </td>
        </tr>
        <tr>
          <td align="center" style="padding-bottom:32px;">
            <h2 style="margin:0;font-family:'Barlow Condensed',Arial,sans-serif;font-size:30px;font-weight:900;color:#ffffff;text-transform:uppercase;">Prepare Your Store</h2>
          </td>
        </tr>

        <!-- Tips grid -->
        <tr>
          <td>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
              <tr>
                <td class="stack-col" width="50%" style="padding:0 8px 16px;vertical-align:top;">
                  <div style="background:#1a1d27;border-radius:4px;padding:20px;">
                    <div style="font-size:28px;margin-bottom:8px;">📸</div>
                    <h4 style="margin:0 0 6px;font-family:'Barlow',Arial,sans-serif;font-size:13px;font-weight:700;color:#e5e7eb;text-transform:uppercase;letter-spacing:0.5px;">Prepare Product Photos</h4>
                    <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:12px;color:#6b7280;line-height:1.6;">Good lighting, clean backgrounds. Products with quality images sell 3x more.</p>
                  </div>
                </td>
                <td class="stack-col" width="50%" style="padding:0 8px 16px;vertical-align:top;">
                  <div style="background:#1a1d27;border-radius:4px;padding:20px;">
                    <div style="font-size:28px;margin-bottom:8px;">💬</div>
                    <h4 style="margin:0 0 6px;font-family:'Barlow',Arial,sans-serif;font-size:13px;font-weight:700;color:#e5e7eb;text-transform:uppercase;letter-spacing:0.5px;">Write Descriptions</h4>
                    <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:12px;color:#6b7280;line-height:1.6;">Clear, detailed product descriptions build buyer confidence and reduce returns.</p>
                  </div>
                </td>
              </tr>
              <tr>
                <td class="stack-col" width="50%" style="padding:0 8px;vertical-align:top;">
                  <div style="background:#1a1d27;border-radius:4px;padding:20px;">
                    <div style="font-size:28px;margin-bottom:8px;">📦</div>
                    <h4 style="margin:0 0 6px;font-family:'Barlow',Arial,sans-serif;font-size:13px;font-weight:700;color:#e5e7eb;text-transform:uppercase;letter-spacing:0.5px;">Organize Your Inventory</h4>
                    <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:12px;color:#6b7280;line-height:1.6;">Know your stock counts and sizes. This prevents overselling and bad reviews.</p>
                  </div>
                </td>
                <td class="stack-col" width="50%" style="padding:0 8px;vertical-align:top;">
                  <div style="background:#1a1d27;border-radius:4px;padding:20px;">
                    <div style="font-size:28px;margin-bottom:8px;">💳</div>
                    <h4 style="margin:0 0 6px;font-family:'Barlow',Arial,sans-serif;font-size:13px;font-weight:700;color:#e5e7eb;text-transform:uppercase;letter-spacing:0.5px;">Set Your Pricing</h4>
                    <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:12px;color:#6b7280;line-height:1.6;">Competitive pricing with room for promotions. Check similar items on the platform.</p>
                  </div>
                </td>
              </tr>
            </table>
          </td>
        </tr>

      </table>
    </td>
  </tr>

  {{-- ================================================================
       SUPPORT SECTION
  ================================================================ --}}
  <tr>
    <td style="background:#faf7f2;padding:36px 40px;" class="pad-mobile">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
        <tr>
          <td align="center">
            <p style="margin:0 0 8px;font-family:'Barlow',Arial,sans-serif;font-size:14px;font-weight:600;color:#374151;">Have questions? Our team is here to help.</p>
            <p style="margin:0 0 20px;font-family:'Barlow',Arial,sans-serif;font-size:13px;color:#6b7280;">
              📧 <a href="mailto:sellers@choosetounsi.tn" style="color:#db142e;text-decoration:none;font-weight:600;">sellers@choosetounsi.tn</a>
              &nbsp;&nbsp;|&nbsp;&nbsp;
              📚 <a href="{{ config('app.url') }}/seller-faq" style="color:#198f41;text-decoration:none;font-weight:600;">Seller FAQ</a>
            </p>
            <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:12px;color:#9ca3af;">Reference: <strong>#APP-{{ str_pad($application->id ?? 0, 6, '0', STR_PAD_LEFT) }}</strong></p>
          </td>
        </tr>
      </table>
    </td>
  </tr>

@endsection