@extends('emails.layout.master')

@php
  $subject   = 'Welcome to ChooseTounsi 🇹🇳';
  $preheader = 'Discover authentic Tunisian products — your local marketplace awaits, ' . ($user->name ?? 'friend') . '!';
@endphp

@section('content')

  {{-- ================================================================
       HERO SECTION
  ================================================================ --}}
  <tr>
    <td style="padding:0;background:linear-gradient(145deg,#0f1117 0%,#1a1d27 50%,#0f1117 100%);position:relative;overflow:hidden;">

      <!-- Decorative background pattern -->
      <div style="position:absolute;top:0;left:0;right:0;bottom:0;opacity:0.04;background-image:repeating-linear-gradient(45deg,#ffffff 0,#ffffff 1px,transparent 0,transparent 50%);background-size:20px 20px;"></div>

      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">

        <!-- Hero badge -->
        <tr>
          <td align="center" style="padding:40px 32px 0;">
            <div style="display:inline-block;background:rgba(219,20,46,0.15);border:1px solid rgba(219,20,46,0.4);border-radius:100px;padding:6px 18px;">
              <span style="font-family:'Barlow',Arial,sans-serif;font-size:11px;font-weight:700;color:#db142e;letter-spacing:2px;text-transform:uppercase;">🎉 Welcome Aboard</span>
            </div>
          </td>
        </tr>

        <!-- Hero headline -->
        <tr>
          <td align="center" style="padding:24px 32px 8px;">
            <h1 class="hero-title" style="margin:0;font-family:'Barlow Condensed',Arial,sans-serif;font-size:52px;font-weight:900;line-height:1.05;color:#ffffff;text-transform:uppercase;letter-spacing:-1px;">
              Marhba Bik,<br>
              <span style="color:#db142e;">{{ $user->name ?? 'Friend' }}</span>!
            </h1>
          </td>
        </tr>

        <!-- Hero subheadline -->
        <tr>
          <td align="center" style="padding:0 40px 32px;">
            <p class="hero-sub" style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:16px;font-weight:400;line-height:1.6;color:#9ca3af;">
              You've just joined Tunisia's premier marketplace.<br>Discover authentic local products, support Tunisian sellers, and enjoy unbeatable prices.
            </p>
          </td>
        </tr>

        <!-- Hero CTA Buttons -->
        <tr>
          <td align="center" style="padding:0 32px 40px;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
              <tr>
                <td style="border-radius:3px;background:#db142e;" align="center">
                  <a href="{{ config('app.url') }}/shop" class="btn-cta" style="font-family:'Barlow',Arial,sans-serif;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;padding:14px 32px;display:inline-block;letter-spacing:1.5px;text-transform:uppercase;border-radius:3px;">
                    Shop Now →
                  </a>
                </td>
                <td width="16">&nbsp;</td>
                <td style="border-radius:3px;background:transparent;border:2px solid #198f41;" align="center">
                  <a href="{{ config('app.url') }}/deals" class="btn-cta" style="font-family:'Barlow',Arial,sans-serif;font-size:14px;font-weight:700;color:#198f41;text-decoration:none;padding:12px 32px;display:inline-block;letter-spacing:1.5px;text-transform:uppercase;border-radius:3px;">
                    Discover Deals
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
       TRUST BAR
  ================================================================ --}}
  <tr>
    <td style="background:#db142e;padding:16px 32px;">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
        <tr>
          <td width="33%" align="center">
            <span style="font-family:'Barlow',Arial,sans-serif;font-size:12px;font-weight:700;color:#ffffff;letter-spacing:0.5px;">🚚 FREE DELIVERY</span>
          </td>
          <td width="34%" align="center" style="border-left:1px solid rgba(255,255,255,0.3);border-right:1px solid rgba(255,255,255,0.3);">
            <span style="font-family:'Barlow',Arial,sans-serif;font-size:12px;font-weight:700;color:#ffffff;letter-spacing:0.5px;">✅ VERIFIED SELLERS</span>
          </td>
          <td width="33%" align="center">
            <span style="font-family:'Barlow',Arial,sans-serif;font-size:12px;font-weight:700;color:#ffffff;letter-spacing:0.5px;">🔄 EASY RETURNS</span>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  {{-- ================================================================
       WELCOME MESSAGE BODY
  ================================================================ --}}
  <tr>
    <td style="background:#faf7f2;padding:48px 40px 40px;" class="pad-mobile">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">

        <!-- Section label -->
        <tr>
          <td style="padding-bottom:8px;">
            <span style="font-family:'Barlow',Arial,sans-serif;font-size:11px;font-weight:700;color:#db142e;letter-spacing:3px;text-transform:uppercase;">Your Journey Starts Here</span>
          </td>
        </tr>

        <!-- Section heading -->
        <tr>
          <td style="padding-bottom:16px;">
            <h2 style="margin:0;font-family:'Barlow Condensed',Arial,sans-serif;font-size:32px;font-weight:800;color:#0f1117;text-transform:uppercase;line-height:1.1;">
              Everything Tunisia<br>Has To Offer
            </h2>
          </td>
        </tr>

        <!-- Body text -->
        <tr>
          <td style="padding-bottom:32px;">
            <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:15px;font-weight:400;line-height:1.7;color:#374151;">
              ChooseTounsi is built for Tunisians, by Tunisians. Browse thousands of authentic products from verified local sellers — from handcrafted artisan goods to everyday essentials, fashion, electronics, and more. Every purchase supports a local business and keeps money in our community.
            </p>
          </td>
        </tr>

        <!-- Divider -->
        <tr>
          <td style="padding-bottom:32px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
              <tr>
                <td width="40" style="border-top:3px solid #db142e;"></td>
                <td style="border-top:1px solid #e5e7eb;"></td>
              </tr>
            </table>
          </td>
        </tr>

      </table>
    </td>
  </tr>

  {{-- ================================================================
       KEY BENEFITS (3 Columns)
  ================================================================ --}}
  <tr>
    <td style="background:#ffffff;padding:0 32px 48px;" class="pad-mobile">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">

        <tr>
          <td align="center" style="padding-bottom:32px;padding-top:40px;">
            <span style="font-family:'Barlow',Arial,sans-serif;font-size:11px;font-weight:700;color:#db142e;letter-spacing:3px;text-transform:uppercase;">Why ChooseTounsi?</span>
          </td>
        </tr>

        <tr>
          <!-- Benefit 1 -->
          <td class="product-col" width="33%" style="padding:0 12px;vertical-align:top;text-align:center;">
            <div style="background:#faf7f2;border-radius:4px;padding:28px 20px;border-top:3px solid #db142e;">
              <div class="feature-icon" style="font-size:36px;margin-bottom:12px;">🇹🇳</div>
              <h3 style="margin:0 0 8px;font-family:'Barlow Condensed',Arial,sans-serif;font-size:18px;font-weight:800;color:#0f1117;text-transform:uppercase;">100% Local</h3>
              <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:13px;color:#6b7280;line-height:1.6;">Every seller is Tunisian. Every purchase empowers local entrepreneurs and artisans.</p>
            </div>
          </td>

          <!-- Benefit 2 -->
          <td class="product-col" width="33%" style="padding:0 12px;vertical-align:top;text-align:center;">
            <div style="background:#faf7f2;border-radius:4px;padding:28px 20px;border-top:3px solid #198f41;">
              <div class="feature-icon" style="font-size:36px;margin-bottom:12px;">💰</div>
              <h3 style="margin:0 0 8px;font-family:'Barlow Condensed',Arial,sans-serif;font-size:18px;font-weight:800;color:#0f1117;text-transform:uppercase;">Best Prices</h3>
              <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:13px;color:#6b7280;line-height:1.6;">No middlemen. Competitive prices direct from the seller, with daily deals and flash sales.</p>
            </div>
          </td>

          <!-- Benefit 3 -->
          <td class="product-col" width="33%" style="padding:0 12px;vertical-align:top;text-align:center;">
            <div style="background:#faf7f2;border-radius:4px;padding:28px 20px;border-top:3px solid #c9963a;">
              <div class="feature-icon" style="font-size:36px;margin-bottom:12px;">🔒</div>
              <h3 style="margin:0 0 8px;font-family:'Barlow Condensed',Arial,sans-serif;font-size:18px;font-weight:800;color:#0f1117;text-transform:uppercase;">Safe & Secure</h3>
              <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:13px;color:#6b7280;line-height:1.6;">Buyer protection on every order. Multiple payment options including D17 and wallet.</p>
            </div>
          </td>
        </tr>

      </table>
    </td>
  </tr>

  {{-- ================================================================
       FEATURED PRODUCTS (Mini Catalog)
  ================================================================ --}}
  <tr>
    <td style="background:#0f1117;padding:48px 32px;">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">

        <!-- Section header -->
        <tr>
          <td align="center" style="padding-bottom:8px;">
            <span style="font-family:'Barlow',Arial,sans-serif;font-size:11px;font-weight:700;color:#db142e;letter-spacing:3px;text-transform:uppercase;">Trending Now</span>
          </td>
        </tr>
        <tr>
          <td align="center" style="padding-bottom:32px;">
            <h2 style="margin:0;font-family:'Barlow Condensed',Arial,sans-serif;font-size:36px;font-weight:900;color:#ffffff;text-transform:uppercase;letter-spacing:-0.5px;">
              Popular This Week
            </h2>
          </td>
        </tr>

        <!-- Product grid -->
        <tr>
          <td>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
              <tr>
                @foreach($featuredProducts ?? [] as $product)
                <!-- Product Card -->
                <td class="product-col" width="25%" style="padding:0 6px;vertical-align:top;">
                  <a href="{{ config('app.url') }}/products/{{ $product['slug'] ?? '#' }}" style="text-decoration:none;display:block;">
                    <div style="background:#1a1d27;border-radius:4px;overflow:hidden;">
                      <img src="{{ $product['image'] ?? config('app.url') . '/images/placeholder.jpg' }}"
                           alt="{{ $product['name'] ?? 'Product' }}"
                           width="130" style="width:100%;display:block;max-height:130px;object-fit:cover;">
                      <div style="padding:12px;">
                        <p style="margin:0 0 4px;font-family:'Barlow',Arial,sans-serif;font-size:12px;font-weight:600;color:#e5e7eb;line-height:1.3;">{{ Str::limit($product['name'] ?? 'Product Name', 30) }}</p>
                        <p style="margin:0;font-family:'Barlow Condensed',Arial,sans-serif;font-size:16px;font-weight:800;color:#db142e;">{{ number_format($product['price'] ?? 0, 2) }} <span style="font-size:11px;">DT</span></p>
                      </div>
                    </div>
                  </a>
                </td>
                @endforeach

                {{-- Fallback if no products --}}
                @if(empty($featuredProducts))
                  @foreach([
                    ['name' => 'Artisan Pottery', 'price' => '45.00', 'emoji' => '🏺'],
                    ['name' => 'Handwoven Carpet', 'price' => '120.00', 'emoji' => '🪆'],
                    ['name' => 'Tunisian Olive Oil', 'price' => '18.00', 'emoji' => '🫒'],
                    ['name' => 'Traditional Chéchia', 'price' => '35.00', 'emoji' => '🎩'],
                  ] as $p)
                  <td class="product-col" width="25%" style="padding:0 6px;vertical-align:top;">
                    <div style="background:#1a1d27;border-radius:4px;overflow:hidden;">
                      <div style="background:#252836;height:110px;display:flex;align-items:center;justify-content:center;font-size:48px;text-align:center;padding:20px 0;">{{ $p['emoji'] }}</div>
                      <div style="padding:12px;">
                        <p style="margin:0 0 4px;font-family:'Barlow',Arial,sans-serif;font-size:12px;font-weight:600;color:#e5e7eb;line-height:1.3;">{{ $p['name'] }}</p>
                        <p style="margin:0;font-family:'Barlow Condensed',Arial,sans-serif;font-size:16px;font-weight:800;color:#db142e;">{{ $p['price'] }} <span style="font-size:11px;color:#9ca3af;">DT</span></p>
                      </div>
                    </div>
                  </td>
                  @endforeach
                @endif

              </tr>
            </table>
          </td>
        </tr>

        <!-- Shop All CTA -->
        <tr>
          <td align="center" style="padding-top:32px;">
            <a href="{{ config('app.url') }}/shop" style="font-family:'Barlow',Arial,sans-serif;font-size:13px;font-weight:700;color:#ffffff;text-decoration:none;padding:14px 40px;display:inline-block;letter-spacing:2px;text-transform:uppercase;border:2px solid #db142e;border-radius:3px;">
              Browse All Products →
            </a>
          </td>
        </tr>

      </table>
    </td>
  </tr>

  {{-- ================================================================
       SOCIAL PROOF BANNER
  ================================================================ --}}
  <tr>
    <td style="background:#198f41;padding:28px 32px;">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
        <tr>
          <td align="center">
            <p style="margin:0;font-family:'Barlow Condensed',Arial,sans-serif;font-size:22px;font-weight:800;color:#ffffff;text-transform:uppercase;letter-spacing:0.5px;">
              🤝 Trusted by <span style="color:#fef08a;">10,000+</span> Tunisians — Hundreds of Local Sellers
            </p>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  {{-- ================================================================
       BECOME A SELLER TEASER
  ================================================================ --}}
  <tr>
    <td style="background:#faf7f2;padding:48px 40px;" class="pad-mobile">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
        <tr>
          <td align="center" style="padding-bottom:8px;">
            <span style="font-family:'Barlow',Arial,sans-serif;font-size:11px;font-weight:700;color:#198f41;letter-spacing:3px;text-transform:uppercase;">For Entrepreneurs</span>
          </td>
        </tr>
        <tr>
          <td align="center" style="padding-bottom:12px;">
            <h2 style="margin:0;font-family:'Barlow Condensed',Arial,sans-serif;font-size:32px;font-weight:900;color:#0f1117;text-transform:uppercase;">Have Something To Sell?</h2>
          </td>
        </tr>
        <tr>
          <td align="center" style="padding-bottom:28px;">
            <p style="margin:0;font-family:'Barlow',Arial,sans-serif;font-size:14px;color:#6b7280;line-height:1.6;max-width:400px;">Join hundreds of local sellers already growing their business on ChooseTounsi. Apply in minutes, start selling in days.</p>
          </td>
        </tr>
        <tr>
          <td align="center">
            <a href="{{ config('app.url') }}/become-seller" style="font-family:'Barlow',Arial,sans-serif;font-size:13px;font-weight:700;color:#ffffff;text-decoration:none;padding:14px 36px;display:inline-block;letter-spacing:1.5px;text-transform:uppercase;background:#198f41;border-radius:3px;">
              Become a Seller →
            </a>
          </td>
        </tr>
      </table>
    </td>
  </tr>

@endsection