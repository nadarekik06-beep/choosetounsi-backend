{{-- Plain-text part of the "order cancelled" e-mail. --}}
{!! __('order_notifications.cancelled.headline') !!}

{!! __('order_notifications.cancelled.intro') !!}

{!! __('order_notifications.common.reference') !!}: {!! $s['reference'] !!}

{!! __('order_notifications.cancelled.items') !!}:
@foreach ($s['items'] as $item)
- {!! $item['name'] !!}{!! $item['variant'] ? ' (' . $item['variant'] . ')' : '' !!} × {!! $item['qty'] !!}
@endforeach

{!! __('order_notifications.common.view_order') !!}: {!! $s['dashboard_url'] !!}

--
{!! __('order_notifications.common.reason') !!}
{!! __('order_notifications.common.help', ['email' => config('mail.from.address')]) !!}
