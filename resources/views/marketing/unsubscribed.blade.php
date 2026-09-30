<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title>{{ $found ? __('emails.marketing.unsubscribed.title') : __('emails.marketing.unsubscribed.not_found') }} · ChooseTounsi</title>
  <style>
    body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f0ede8; font-family: 'Barlow', Arial, sans-serif; color: #111827; }
    .card { background: #fff; max-width: 440px; width: calc(100% - 32px); border-radius: 16px; padding: 28px 24px; box-shadow: 0 4px 30px rgba(0,0,0,.08); text-align: center; border-top: 5px solid #db142e; }
    h1 { font-size: 22px; margin: 0 0 10px; }
    p { font-size: 15px; line-height: 1.55; color: #4b5563; margin: 0 0 20px; }
    a { display: inline-block; background: #db142e; color: #fff; text-decoration: none; font-weight: 700; padding: 12px 22px; border-radius: 10px; }
  </style>
</head>
<body>
  <main class="card">
    @if ($found)
      <h1>{{ __('emails.marketing.unsubscribed.title') }}</h1>
      <p>{{ __('emails.marketing.unsubscribed.body') }}</p>
    @else
      <h1>{{ __('emails.marketing.unsubscribed.not_found') }}</h1>
      <p>{{ __('emails.marketing.unsubscribed.not_found_body') }}</p>
    @endif
    <a href="{{ $shopUrl }}">{{ __('emails.marketing.back_to_shop') }}</a>
  </main>
</body>
</html>
