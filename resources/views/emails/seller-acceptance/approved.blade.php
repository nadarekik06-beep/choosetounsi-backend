@extends('emails.layout.master')

@php
  $subject   = __('seller.mail.approved.subject');
  $preheader = __('seller.mail.approved.preheader', ['name' => $seller->name ?? __('seller.mail.friend')]);
  $rtl       = app()->getLocale() === 'ar';
  $start     = $rtl ? 'right' : 'left';
  $end       = $rtl ? 'left' : 'right';
  $plan      = $application->active_plan ?? 'green';
@endphp

@section('content')

  {{-- ================================================================
       HERO: APPROVED!
  ================================================================ --}}
  <tr>
    <td style="background:linear-gradient(150deg,#0f2d1a 0%,#0f1117 60%,#1a0a0a 100%);padding:0;">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">

        <!-- Confetti-style decorative bar -->
        <tr>
          <td style="padding:0;line-height:0;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
              <tr>
                <td width="20%" height="3" style="background:#db142e;font-size:0;"></td>
                <td width="20%" height="3" style="background:#198f41;font-size:0;"></td>
                <td width="20%" height="3" style="background:#c9963a;font-size:0;"></td>
                <td width="20%" height="3" style="background:#198f41;font-size:0;"></td>
                <td width="20%" height="3" style="background:#db142e;font-size:0;"></td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Trophy icon -->
        <tr>
          <td align="center" style="padding:48px 32px 20px;">
            <div style="display:inline-block;width:88px;height:88px;background:linear-gradient(135deg,rgba(201,150,58,0.2),rgba(201,150,58,0.05));border:2px solid #c9963a;border-radius:50%;line-height:84px;text-align:center;font-size:44px;">
              🏆
            </div>
          </td>
        </tr>

        <!-- Approved badge -->
        <tr>
          <td align="center" style="padding:0 32px 16px;">
            <div style="display:inline-block;background:rgba(25,143,65,0.15);border:1px solid rgba(25,143,65,0.6);border-radius:100px;padding:7px 22px;">
              <span style="font-family:'Barlow',Arial,sans-serif;font-size:12px;font-weight:700;color:#4ade80;letter-spacing:2.5px;text-transform:uppercase;">{!! __('seller.mail.approved.badge') !!}</span>
            </div>
          </td>
        </tr>

        <!-- Main headline -->
        <tr>
          <td align="center" style="padding:0 32px 16px;">
            <h1 class="hero-title" style="margin:0;font-family:'Barlow Condensed',Arial,sans-serif;font-size:50px;font-weight:900;line-height:1.05;color:#ffffff;text-transform:uppercase;letter-spacing:-1px;">
              {!! __('seller.mail.approved.title') !!}
            </h1>
          </td>
        </tr>

        <!-- Subtext -->
        <tr>
          <td align="center" style="padding:0 48px 40px;">
            <p class="hero-sub" style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:15px;line-height:1.7;color:#9ca3af;">
              {!! __('seller.mail.approved.intro', ['name' => e($seller->name ?? __('seller.mail.seller'))]) !!}
            </p>
          </td>
        </tr>

        <!-- Primary CTAs -->
        <tr>
          <td align="center" style="padding:0 32px 52px;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
              <tr>
                <td style="border-radius:3px;background:#198f41;" align="center">
                  <a href="{{ config('app.url') }}/seller/dashboard" class="btn-cta" style="font-family:'Barlow',Arial,sans-serif;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;padding:15px 32px;display:inline-block;letter-spacing:1.5px;text-transform:uppercase;border-radius:3px;">
                    🚀 {!! __('seller.mail.approved.start') !!}
                  </a>
                </td>
                <td width="16">&nbsp;</td>
                <td style="border-radius:3px;background:transparent;border:2px solid #c9963a;" align="center">
                  <a href="{{ config('app.url') }}/seller/products/create" class="btn-cta" style="font-family:'Barlow',Arial,sans-serif;font-size:14px;font-weight:700;color:#c9963a;text-decoration:none;padding:13px 28px;display:inline-block;letter-spacing:1.5px;text-transform:uppercase;border-radius:3px;">
                    + {!! __('seller.mail.approved.add_first') !!}
                  </a>
                </td>
              </tr>
            </table>
          </td>
        </tr>

      </table>
    </td>
  </tr>

  {{-- ================================================================
       CURRENT PLAN CONFIRMATION
  ================================================================ --}}
  <tr>
    <td style="background:#faf7f2;padding:48px 40px 40px;" class="pad-mobile">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">

        <tr>
          <td align="center" style="padding-bottom:8px;">
            <span style="font-family:'Barlow',Arial,sans-serif;font-size:11px;font-weight:700;color:#6b7280;letter-spacing:3px;text-transform:uppercase;">{!! __('seller.mail.approved.active_plan') !!}</span>
          </td>
        </tr>

        <!-- Plan card -->
        <tr>
          <td style="padding-bottom:32px;">
            @if($plan === 'black')
              <div style="background:linear-gradient(135deg,#0f1117,#1a1d27);border:2px solid #c9963a;border-radius:8px;padding:28px;text-align:center;">
                <div style="font-size:40px;margin-bottom:12px;">⚫</div>
                <h3 style="margin:0 0 4px;font-family:'Barlow Condensed',Arial,sans-serif;font-size:28px;font-weight:900;color:#c9963a;text-transform:uppercase;letter-spacing:1px;">Black Pepper</h3>
                <p style="margin:0 0 8px;font-family:'Barlow',Arial,sans-serif;font-size:22px;font-weight:700;color:#ffffff;">{!! __('seller.mail.per_month', ['amount' => 129]) !!}</p>
                <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:13px;color:#9ca3af;">{!! __('seller.mail.approved.black_features') !!}</p>
              </div>
            @elseif($plan === 'red')
              <div style="background:linear-gradient(135deg,#1a0505,#1f0a0a);border:2px solid #db142e;border-radius:8px;padding:28px;text-align:center;">
                <div style="font-size:40px;margin-bottom:12px;">🔴</div>
                <h3 style="margin:0 0 4px;font-family:'Barlow Condensed',Arial,sans-serif;font-size:28px;font-weight:900;color:#db142e;text-transform:uppercase;letter-spacing:1px;">Red Pepper</h3>
                <p style="margin:0 0 8px;font-family:'Barlow',Arial,sans-serif;font-size:22px;font-weight:700;color:#ffffff;">{!! __('seller.mail.per_month', ['amount' => 49]) !!}</p>
                <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:13px;color:#9ca3af;">{!! __('seller.mail.approved.red_features') !!}</p>
              </div>
            @else
              <div style="background:linear-gradient(135deg,#041a0a,#061f0d);border:2px solid #198f41;border-radius:8px;padding:28px;text-align:center;">
                <div style="font-size:40px;margin-bottom:12px;">🟢</div>
                <h3 style="margin:0 0 4px;font-family:'Barlow Condensed',Arial,sans-serif;font-size:28px;font-weight:900;color:#198f41;text-transform:uppercase;letter-spacing:1px;">Green Pepper</h3>
                <p style="margin:0 0 8px;font-family:'Barlow',Arial,sans-serif;font-size:22px;font-weight:700;color:#ffffff;">{!! __('seller.mail.approved.free_forever') !!}</p>
                <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:13px;color:#9ca3af;">{!! __('seller.mail.approved.green_features') !!}</p>
              </div>
            @endif
          </td>
        </tr>

      </table>
    </td>
  </tr>

  {{-- ================================================================
       NEXT STEPS
  ================================================================ --}}
  <tr>
    <td style="background:#ffffff;padding:0 40px 48px;" class="pad-mobile">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">

        <tr>
          <td style="padding-bottom:28px;">
            <h3 style="margin:0;font-family:'Barlow Condensed',Arial,sans-serif;font-size:28px;font-weight:900;color:#0f1117;text-transform:uppercase;">🗺️ {!! __('seller.mail.approved.first_steps') !!}</h3>
          </td>
        </tr>

        <!-- Step 1 -->
        <tr>
          <td style="padding-bottom:20px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
              <tr>
                <td width="52" style="vertical-align:top;padding-{{ $end }}:16px;">
                  <div style="width:44px;height:44px;background:#db142e;border-radius:50%;line-height:44px;text-align:center;font-family:'Barlow Condensed',Arial,sans-serif;font-size:20px;font-weight:900;color:#ffffff;display:inline-block;">1</div>
                </td>
                <td style="vertical-align:top;padding-top:6px;">
                  <p style="margin:0 0 4px;font-family:'Barlow',Arial,sans-serif;font-size:15px;font-weight:700;color:#0f1117;">{!! __('seller.mail.approved.step1_title') !!}</p>
                  <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:13px;color:#6b7280;line-height:1.6;">{!! __('seller.mail.approved.step1_text') !!}</p>
                  <a href="{{ config('app.url') }}/seller/profile" style="font-family:'Barlow',Arial,sans-serif;font-size:12px;font-weight:700;color:#db142e;text-decoration:none;">{!! __('seller.mail.approved.step1_link') !!}</a>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Step 2 -->
        <tr>
          <td style="padding-bottom:20px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
              <tr>
                <td width="52" style="vertical-align:top;padding-{{ $end }}:16px;">
                  <div style="width:44px;height:44px;background:#198f41;border-radius:50%;line-height:44px;text-align:center;font-family:'Barlow Condensed',Arial,sans-serif;font-size:20px;font-weight:900;color:#ffffff;display:inline-block;">2</div>
                </td>
                <td style="vertical-align:top;padding-top:6px;">
                  <p style="margin:0 0 4px;font-family:'Barlow',Arial,sans-serif;font-size:15px;font-weight:700;color:#0f1117;">{!! __('seller.mail.approved.step2_title') !!}</p>
                  <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:13px;color:#6b7280;line-height:1.6;">{!! __('seller.mail.approved.step2_text') !!}</p>
                  <a href="{{ config('app.url') }}/seller/products/create" style="font-family:'Barlow',Arial,sans-serif;font-size:12px;font-weight:700;color:#198f41;text-decoration:none;">{!! __('seller.mail.approved.step2_link') !!}</a>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Step 3 -->
        <tr>
          <td>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
              <tr>
                <td width="52" style="vertical-align:top;padding-{{ $end }}:16px;">
                  <div style="width:44px;height:44px;background:#c9963a;border-radius:50%;line-height:44px;text-align:center;font-family:'Barlow Condensed',Arial,sans-serif;font-size:20px;font-weight:900;color:#ffffff;display:inline-block;">3</div>
                </td>
                <td style="vertical-align:top;padding-top:6px;">
                  <p style="margin:0 0 4px;font-family:'Barlow',Arial,sans-serif;font-size:15px;font-weight:700;color:#0f1117;">{!! __('seller.mail.approved.step3_title') !!}</p>
                  <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:13px;color:#6b7280;line-height:1.6;">{!! __('seller.mail.approved.step3_text') !!}</p>
                  <a href="{{ config('app.url') }}/seller/dashboard" style="font-family:'Barlow',Arial,sans-serif;font-size:12px;font-weight:700;color:#c9963a;text-decoration:none;">{!! __('seller.mail.approved.step3_link') !!}</a>
                </td>
              </tr>
            </table>
          </td>
        </tr>

      </table>
    </td>
  </tr>

  {{-- ================================================================
       UPSELL: UPGRADE YOUR PLAN (shown only for Green plan sellers)
  ================================================================ --}}
  @if($plan === 'green')
  <tr>
    <td style="background:linear-gradient(135deg,#0f1117,#1a0a0a);padding:48px 40px;" class="pad-mobile">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">

        <!-- Upsell label -->
        <tr>
          <td align="center" style="padding-bottom:8px;">
            <div style="display:inline-block;background:rgba(201,150,58,0.12);border:1px solid rgba(201,150,58,0.4);border-radius:100px;padding:5px 16px;">
              <span style="font-family:'Barlow',Arial,sans-serif;font-size:11px;font-weight:700;color:#c9963a;letter-spacing:2px;text-transform:uppercase;">⚡ {!! __('seller.mail.approved.pro_tips') !!}</span>
            </div>
          </td>
        </tr>

        <tr>
          <td align="center" style="padding-bottom:16px;">
            <h2 style="margin:0;font-family:'Barlow Condensed',Arial,sans-serif;font-size:34px;font-weight:900;color:#ffffff;text-transform:uppercase;letter-spacing:-0.5px;">
              {!! __('seller.mail.approved.sell_more') !!}
            </h2>
          </td>
        </tr>

        <tr>
          <td align="center" style="padding-bottom:36px;">
            <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:14px;color:#9ca3af;line-height:1.7;max-width:420px;">
              {!! __('seller.mail.approved.premium_earn') !!}
            </p>
          </td>
        </tr>

        <!-- Plan comparison: Red vs Black -->
        <tr>
          <td>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
              <tr>
                <!-- Red Pepper -->
                <td class="stack-col" width="50%" style="padding:0 8px;vertical-align:top;">
                  <div style="background:#1a0505;border:2px solid #db142e;border-radius:8px;padding:24px 20px;text-align:center;">
                    <div style="font-size:32px;margin-bottom:8px;">🌶️</div>
                    <h3 style="margin:0 0 4px;font-family:'Barlow Condensed',Arial,sans-serif;font-size:22px;font-weight:900;color:#db142e;text-transform:uppercase;">Red Pepper</h3>
                    <p style="margin:0 0 16px;font-family:'Barlow',Arial,sans-serif;font-size:20px;font-weight:700;color:#ffffff;">49 {!! __('seller.mail.currency') !!}<span style="font-size:13px;color:#9ca3af;">{!! __('seller.mail.per_mo') !!}</span></p>
                    <ul style="margin:0 0 20px;padding:0;list-style:none;text-align:{{ $start }};">
                      <li style="font-family:'Barlow',Arial,sans-serif;font-size:12px;color:#e5e7eb;padding:4px 0;border-bottom:1px solid rgba(255,255,255,0.06);">✅ {!! __('seller.mail.approved.f_price') !!}</li>
                      <li style="font-family:'Barlow',Arial,sans-serif;font-size:12px;color:#e5e7eb;padding:4px 0;border-bottom:1px solid rgba(255,255,255,0.06);">✅ {!! __('seller.mail.approved.f_sales') !!}</li>
                      <li style="font-family:'Barlow',Arial,sans-serif;font-size:12px;color:#e5e7eb;padding:4px 0;border-bottom:1px solid rgba(255,255,255,0.06);">✅ {!! __('seller.mail.approved.f_sponsored') !!}</li>
                      <li style="font-family:'Barlow',Arial,sans-serif;font-size:12px;color:#e5e7eb;padding:4px 0;">✅ {!! __('seller.mail.approved.f_analytics') !!}</li>
                    </ul>
                    <a href="{{ config('app.url') }}/seller/plans?upgrade=red" style="font-family:'Barlow',Arial,sans-serif;font-size:12px;font-weight:700;color:#ffffff;text-decoration:none;padding:10px 20px;display:block;letter-spacing:1px;text-transform:uppercase;background:#db142e;border-radius:3px;">
                      {!! __('seller.mail.approved.upgrade_red') !!}
                    </a>
                  </div>
                </td>

                <!-- Black Pepper -->
                <td class="stack-col" width="50%" style="padding:0 8px;vertical-align:top;">
                  <div style="background:linear-gradient(135deg,#0f1117,#1a1505);border:2px solid #c9963a;border-radius:8px;padding:24px 20px;text-align:center;position:relative;">
                    <!-- Best value badge -->
                    <div style="position:absolute;top:-12px;left:50%;transform:translateX(-50%);background:#c9963a;border-radius:100px;padding:3px 14px;white-space:nowrap;">
                      <span style="font-family:'Barlow',Arial,sans-serif;font-size:10px;font-weight:800;color:#0f1117;letter-spacing:1px;text-transform:uppercase;">{!! __('seller.mail.approved.best_value') !!}</span>
                    </div>
                    <div style="font-size:32px;margin-bottom:8px;margin-top:8px;">⚫</div>
                    <h3 style="margin:0 0 4px;font-family:'Barlow Condensed',Arial,sans-serif;font-size:22px;font-weight:900;color:#c9963a;text-transform:uppercase;">Black Pepper</h3>
                    <p style="margin:0 0 16px;font-family:'Barlow',Arial,sans-serif;font-size:20px;font-weight:700;color:#ffffff;">129 {!! __('seller.mail.currency') !!}<span style="font-size:13px;color:#9ca3af;">{!! __('seller.mail.per_mo') !!}</span></p>
                    <ul style="margin:0 0 20px;padding:0;list-style:none;text-align:{{ $start }};">
                      <li style="font-family:'Barlow',Arial,sans-serif;font-size:12px;color:#e5e7eb;padding:4px 0;border-bottom:1px solid rgba(255,255,255,0.06);">✅ {!! __('seller.mail.approved.f_everything_red') !!}</li>
                      <li style="font-family:'Barlow',Arial,sans-serif;font-size:12px;color:#e5e7eb;padding:4px 0;border-bottom:1px solid rgba(255,255,255,0.06);">✅ {!! __('seller.mail.approved.f_forecast') !!}</li>
                      <li style="font-family:'Barlow',Arial,sans-serif;font-size:12px;color:#e5e7eb;padding:4px 0;border-bottom:1px solid rgba(255,255,255,0.06);">✅ {!! __('seller.mail.approved.f_priority') !!}</li>
                      <li style="font-family:'Barlow',Arial,sans-serif;font-size:12px;color:#e5e7eb;padding:4px 0;">✅ {!! __('seller.mail.approved.f_vip') !!}</li>
                    </ul>
                    <a href="{{ config('app.url') }}/seller/plans?upgrade=black" style="font-family:'Barlow',Arial,sans-serif;font-size:12px;font-weight:700;color:#0f1117;text-decoration:none;padding:10px 20px;display:block;letter-spacing:1px;text-transform:uppercase;background:#c9963a;border-radius:3px;">
                      {!! __('seller.mail.approved.upgrade_black') !!}
                    </a>
                  </div>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <tr>
          <td align="center" style="padding-top:24px;">
            <a href="{{ config('app.url') }}/seller/plans" style="font-family:'Barlow',Arial,sans-serif;font-size:12px;color:#6b7280;text-decoration:underline;">{!! __('seller.mail.approved.compare') !!}</a>
          </td>
        </tr>

      </table>
    </td>
  </tr>
  @endif

  {{-- ================================================================
       SUCCESS TIPS
  ================================================================ --}}
  <tr>
    <td style="background:#faf7f2;padding:48px 40px;" class="pad-mobile">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">

        <tr>
          <td align="center" style="padding-bottom:8px;">
            <span style="font-family:'Barlow',Arial,sans-serif;font-size:11px;font-weight:700;color:#198f41;letter-spacing:3px;text-transform:uppercase;">{!! __('seller.mail.approved.pro_tips_short') !!}</span>
          </td>
        </tr>
        <tr>
          <td align="center" style="padding-bottom:28px;">
            <h2 style="margin:0;font-family:'Barlow Condensed',Arial,sans-serif;font-size:28px;font-weight:900;color:#0f1117;text-transform:uppercase;">{!! __('seller.mail.approved.secrets') !!}</h2>
          </td>
        </tr>

        <tr>
          <td>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
              @foreach([
                ['🔥', __('seller.mail.approved.tip1_title'), __('seller.mail.approved.tip1_text')],
                ['⭐', __('seller.mail.approved.tip2_title'), __('seller.mail.approved.tip2_text')],
                ['📊', __('seller.mail.approved.tip3_title'), __('seller.mail.approved.tip3_text')],
                ['🎯', __('seller.mail.approved.tip4_title'), __('seller.mail.approved.tip4_text')],
              ] as $tip)
              <tr>
                <td style="padding:0 0 16px;">
                  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                    <tr>
                      <td width="44" style="vertical-align:top;padding-{{ $end }}:14px;font-size:26px;">{{ $tip[0] }}</td>
                      <td style="vertical-align:top;">
                        <p style="margin:0 0 3px;font-family:'Barlow',Arial,sans-serif;font-size:14px;font-weight:700;color:#0f1117;">{{ $tip[1] }}</p>
                        <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:13px;color:#6b7280;line-height:1.5;">{{ $tip[2] }}</p>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
              @endforeach
            </table>
          </td>
        </tr>

        <!-- Dashboard CTA -->
        <tr>
          <td align="center" style="padding-top:24px;">
            <a href="{{ config('app.url') }}/seller/dashboard" style="font-family:'Barlow',Arial,sans-serif;font-size:13px;font-weight:700;color:#ffffff;text-decoration:none;padding:14px 40px;display:inline-block;letter-spacing:2px;text-transform:uppercase;background:#0f1117;border-radius:3px;">
              {!! __('seller.mail.approved.open_dashboard') !!}
            </a>
          </td>
        </tr>

      </table>
    </td>
  </tr>

@endsection