{{-- Plain-text part of the "bordereau ready" e-mail. --}}
{!! __("order_notifications.$event.headline") !!}

{!! __("order_notifications.$event.intro") !!}

{!! __('order_notifications.common.reference') !!}: {!! $s['reference'] !!}
{!! __("order_notifications.$event.next") !!}

{!! __("order_notifications.$event.cta") !!}: {!! $s['dashboard_url'] !!}

--
{!! __('order_notifications.common.reason') !!}
{!! __('order_notifications.common.help', ['email' => config('mail.contact_to')]) !!}
