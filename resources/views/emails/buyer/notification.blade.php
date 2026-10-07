@component('mail::message')
# {{ $heading }}

@if($name)
{{ __('buyer_notifications.mail.greeting', ['name' => $name]) }}
@else
{{ __('buyer_notifications.mail.greeting_anonymous') }}
@endif

{{ $body }}

@foreach($lines as $line)
{{ $line }}

@endforeach
@if(!empty($table))
@component('mail::table')
| | |
|:--|--:|
@foreach($table as $row)
| {{ $row[0] }} | {{ $row[1] }} |
@endforeach
@endcomponent
@endif

@if($button && $url)
@component('mail::button', ['url' => $url, 'color' => 'red'])
{{ $button }}
@endcomponent
@endif

{{ __('buyer_notifications.mail.signature') }}<br>
{{ config('app.name') }}

@slot('subcopy')
{{ $footer }}
@endslot
@endcomponent
