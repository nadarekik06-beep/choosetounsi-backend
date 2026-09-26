@extends('emails.layout.master')

@php
  $subject   = __('seller.mail.submitted.subject');
  $preheader = __('seller.mail.submitted.preheader', ['name' => $seller->name ?? __('seller.mail.friend')]);
  $rtl       = app()->getLocale() === 'ar';
  $start     = $rtl ? 'right' : 'left';
  $end       = $rtl ? 'left' : 'right';
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
              <span style="font-family:'Barlow',Arial,sans-serif;font-size:11px;font-weight:700;color:#4ade80;letter-spacing:2.5px;text-transform:uppercase;">✅ {!! __('seller.mail.submitted.badge') !!}</span>
            </div>
          </td>
        </tr>

        <!-- Headline -->
        <tr>
          <td align="center" style="padding:0 32px 16px;">
            <h1 class="hero-title" style="margin:0;font-family:'Barlow Condensed',Arial,sans-serif;font-size:46px;font-weight:900;line-height:1.1;color:#ffffff;text-transform:uppercase;letter-spacing:-0.5px;">
              {!! __('seller.mail.submitted.title') !!}
            </h1>
          </td>
        </tr>

        <!-- Sub -->
        <tr>
          <td align="center" style="padding:0 48px 48px;">
            <p class="hero-sub" style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:15px;font-weight:400;line-height:1.7;color:#9ca3af;">
              {!! __('seller.mail.submitted.intro', ['name' => e($seller->name ?? __('seller.mail.seller'))]) !!}
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
            <span style="font-family:'Barlow',Arial,sans-serif;font-size:11px;font-weight:700;color:#db142e;letter-spacing:3px;text-transform:uppercase;">{!! __('seller.mail.submitted.where') !!}</span>
          </td>
        </tr>
        <tr>
          <td align="center" style="padding-bottom:36px;">
            <h2 style="margin:0;font-family:'Barlow Condensed',Arial,sans-serif;font-size:30px;font-weight:800;color:#0f1117;text-transform:uppercase;">{!! __('seller.mail.submitted.progress') !!}</h2>
          </td>
        </tr>

        <!-- Steps -->

        <!-- Step 1 — COMPLETE -->
        <tr>
          <td style="padding-bottom:4px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
              <tr>
                <td width="52" style="vertical-align:top;padding-{{ $end }}:16px;text-align:center;">
                  <div style="width:44px;height:44px;background:#198f41;border-radius:50%;line-height:44px;text-align:center;font-size:20px;display:inline-block;">✅</div>
                  <div style="width:2px;height:32px;background:#e5e7eb;margin:4px auto 0;"></div>
                </td>
                <td style="vertical-align:top;padding-top:8px;">
                  <p style="margin:0 0 2px;font-family:'Barlow',Arial,sans-serif;font-size:14px;font-weight:700;color:#198f41;text-transform:uppercase;letter-spacing:0.5px;">{!! __('seller.mail.submitted.step1_title') !!}</p>
                  <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:13px;color:#374151;">{!! __('seller.mail.submitted.step1_text') !!}</p>
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
                <td width="52" style="vertical-align:top;padding-{{ $end }}:16px;text-align:center;">
                  <div style="width:44px;height:44px;background:#db142e;border-radius:50%;line-height:40px;text-align:center;font-size:20px;display:inline-block;border:2px solid #db142e;">🔍</div>
                  <div style="width:2px;height:32px;background:#e5e7eb;margin:4px auto 0;"></div>
                </td>
                <td style="vertical-align:top;padding-top:8px;">
                  <p style="margin:0 0 2px;font-family:'Barlow',Arial,sans-serif;font-size:14px;font-weight:700;color:#db142e;text-transform:uppercase;letter-spacing:0.5px;">{!! __('seller.mail.submitted.step2_title') !!}</p>
                  <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:13px;color:#374151;">{!! __('seller.mail.submitted.step2_text') !!}</p>
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
                <td width="52" style="vertical-align:top;padding-{{ $end }}:16px;text-align:center;">
                  <div style="width:44px;height:44px;background:#f3f4f6;border-radius:50%;line-height:44px;text-align:center;font-size:20px;display:inline-block;border:2px solid #e5e7eb;">📧</div>
                  <div style="width:2px;height:32px;background:#e5e7eb;margin:4px auto 0;"></div>
                </td>
                <td style="vertical-align:top;padding-top:8px;">
                  <p style="margin:0 0 2px;font-family:'Barlow',Arial,sans-serif;font-size:14px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:0.5px;">{!! __('seller.mail.submitted.step3_title') !!}</p>
                  <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:13px;color:#9ca3af;">{!! __('seller.mail.submitted.step3_text') !!}</p>
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
                <td width="52" style="vertical-align:top;padding-{{ $end }}:16px;text-align:center;">
                  <div style="width:44px;height:44px;background:#f3f4f6;border-radius:50%;line-height:44px;text-align:center;font-size:20px;display:inline-block;border:2px solid #e5e7eb;">🚀</div>
                </td>
                <td style="vertical-align:top;padding-top:8px;">
                  <p style="margin:0 0 2px;font-family:'Barlow',Arial,sans-serif;font-size:14px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:0.5px;">{!! __('seller.mail.submitted.step4_title') !!}</p>
                  <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:13px;color:#9ca3af;">{!! __('seller.mail.submitted.step4_text') !!}</p>
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
            <h3 style="margin:0;font-family:'Barlow Condensed',Arial,sans-serif;font-size:20px;font-weight:800;color:#0f1117;text-transform:uppercase;">📋 {!! __('seller.mail.submitted.summary') !!}</h3>
          </td>
        </tr>

        <!-- Summary card -->
        <tr>
          <td style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:4px;border-{{ $start }}:4px solid #db142e;padding:20px 24px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
              <tr>
                <td style="padding-bottom:10px;">
                  <span style="font-family:'Barlow',Arial,sans-serif;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;">{!! __('seller.mail.submitted.name') !!}</span><br>
                  <span style="font-family:'Barlow',Arial,sans-serif;font-size:15px;font-weight:700;color:#0f1117;">{{ $seller->name ?? 'N/A' }}</span>
                </td>
              </tr>
              <tr>
                <td style="padding-bottom:10px;">
                  <span style="font-family:'Barlow',Arial,sans-serif;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;">{!! __('seller.mail.submitted.email') !!}</span><br>
                  <span style="font-family:'Barlow',Arial,sans-serif;font-size:15px;font-weight:700;color:#0f1117;">{{ $seller->email ?? 'N/A' }}</span>
                </td>
              </tr>
              <tr>
                <td style="padding-bottom:10px;">
                  <span style="font-family:'Barlow',Arial,sans-serif;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;">{!! __('seller.mail.submitted.plan') !!}</span><br>
                  <span style="font-family:'Barlow',Arial,sans-serif;font-size:15px;font-weight:700;color:#db142e;">
                    @if(($application->preferred_plan ?? 'green') === 'black')
                      ⚫ Black Pepper — 129 {!! __('seller.mail.currency') !!}{!! __('seller.mail.per_mo') !!}
                    @elseif(($application->preferred_plan ?? 'green') === 'red')
                      🔴 Red Pepper — 49 {!! __('seller.mail.currency') !!}{!! __('seller.mail.per_mo') !!}
                    @else
                      🟢 Green Pepper — {!! __('seller.mail.free') !!}
                    @endif
                  </span>
                </td>
              </tr>
              <tr>
                <td>
                  <span style="font-family:'Barlow',Arial,sans-serif;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;">{!! __('seller.mail.submitted.date') !!}</span><br>
                  <span style="font-family:'Barlow',Arial,sans-serif;font-size:15px;font-weight:700;color:#0f1117;">{{ ($application->created_at ?? now())->translatedFormat('j F Y — H:i') }}</span>
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
            <span style="font-family:'Barlow',Arial,sans-serif;font-size:11px;font-weight:700;color:#db142e;letter-spacing:3px;text-transform:uppercase;">{!! __('seller.mail.submitted.meantime') !!}</span>
          </td>
        </tr>
        <tr>
          <td align="center" style="padding-bottom:32px;">
            <h2 style="margin:0;font-family:'Barlow Condensed',Arial,sans-serif;font-size:30px;font-weight:900;color:#ffffff;text-transform:uppercase;">{!! __('seller.mail.submitted.prepare') !!}</h2>
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
                    <h4 style="margin:0 0 6px;font-family:'Barlow',Arial,sans-serif;font-size:13px;font-weight:700;color:#e5e7eb;text-transform:uppercase;letter-spacing:0.5px;">{!! __('seller.mail.submitted.photos_title') !!}</h4>
                    <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:12px;color:#6b7280;line-height:1.6;">{!! __('seller.mail.submitted.photos_text') !!}</p>
                  </div>
                </td>
                <td class="stack-col" width="50%" style="padding:0 8px 16px;vertical-align:top;">
                  <div style="background:#1a1d27;border-radius:4px;padding:20px;">
                    <div style="font-size:28px;margin-bottom:8px;">💬</div>
                    <h4 style="margin:0 0 6px;font-family:'Barlow',Arial,sans-serif;font-size:13px;font-weight:700;color:#e5e7eb;text-transform:uppercase;letter-spacing:0.5px;">{!! __('seller.mail.submitted.desc_title') !!}</h4>
                    <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:12px;color:#6b7280;line-height:1.6;">{!! __('seller.mail.submitted.desc_text') !!}</p>
                  </div>
                </td>
              </tr>
              <tr>
                <td class="stack-col" width="50%" style="padding:0 8px;vertical-align:top;">
                  <div style="background:#1a1d27;border-radius:4px;padding:20px;">
                    <div style="font-size:28px;margin-bottom:8px;">📦</div>
                    <h4 style="margin:0 0 6px;font-family:'Barlow',Arial,sans-serif;font-size:13px;font-weight:700;color:#e5e7eb;text-transform:uppercase;letter-spacing:0.5px;">{!! __('seller.mail.submitted.stock_title') !!}</h4>
                    <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:12px;color:#6b7280;line-height:1.6;">{!! __('seller.mail.submitted.stock_text') !!}</p>
                  </div>
                </td>
                <td class="stack-col" width="50%" style="padding:0 8px;vertical-align:top;">
                  <div style="background:#1a1d27;border-radius:4px;padding:20px;">
                    <div style="font-size:28px;margin-bottom:8px;">💳</div>
                    <h4 style="margin:0 0 6px;font-family:'Barlow',Arial,sans-serif;font-size:13px;font-weight:700;color:#e5e7eb;text-transform:uppercase;letter-spacing:0.5px;">{!! __('seller.mail.submitted.price_title') !!}</h4>
                    <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:12px;color:#6b7280;line-height:1.6;">{!! __('seller.mail.submitted.price_text') !!}</p>
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
            <p style="margin:0 0 8px;font-family:'Barlow',Arial,sans-serif;font-size:14px;font-weight:600;color:#374151;">{!! __('seller.mail.submitted.questions') !!}</p>
            <p style="margin:0 0 20px;font-family:'Barlow',Arial,sans-serif;font-size:13px;color:#6b7280;">
              📧 <a href="mailto:sellers@choosetounsi.tn" style="color:#db142e;text-decoration:none;font-weight:600;">sellers@choosetounsi.tn</a>
              &nbsp;&nbsp;|&nbsp;&nbsp;
              📚 <a href="{{ config('app.url') }}/seller-faq" style="color:#198f41;text-decoration:none;font-weight:600;">{!! __('seller.mail.submitted.faq') !!}</a>
            </p>
            <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:12px;color:#9ca3af;">{!! __('seller.mail.submitted.reference') !!} <strong>#APP-{{ str_pad($application->id ?? 0, 6, '0', STR_PAD_LEFT) }}</strong></p>
          </td>
        </tr>
      </table>
    </td>
  </tr>

@endsection