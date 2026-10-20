@component('mail::layout')
{{-- Header --}}
@slot('header')
@component('mail::header', ['url' => frontend_url('/')])
{{ config('app.name') }}
@endcomponent
@endslot

{{-- Body --}}
{{ $slot }}

{{-- Subcopy --}}
@isset($subcopy)
@slot('subcopy')
@component('mail::subcopy')
{{ $subcopy }}
@endcomponent
@endslot
@endisset

{{-- Footer --}}
@slot('footer')
@component('mail::footer')
[{{ __('emails.layout.privacy') }}]({{ frontend_url('/privacy') }}) · [{{ __('emails.layout.terms') }}]({{ frontend_url('/terms') }}) · [{{ __('emails.layout.contact') }}]({{ frontend_url('/contact') }})

© {{ date('Y') }} {{ config('app.name') }}. @lang('All rights reserved.')
@endcomponent
@endslot
@endcomponent
