{{-- Plain-text part of the "new order" e-mail. --}}
{!! __('order_notifications.placed.headline') !!}

{!! $sellerName !== '' ? __('order_notifications.common.greeting', ['name' => $sellerName]) : __('order_notifications.common.greeting_anon') !!}
{!! __('order_notifications.placed.intro') !!}

{!! __('order_notifications.common.reference') !!}: {!! $s['reference'] !!}
{!! __('order_notifications.common.order_date') !!}: {!! $s['order_date'] !!}

{!! __('order_notifications.common.items') !!}:
@foreach ($s['items'] as $item)
- {!! $item['name'] !!}{!! $item['variant'] ? ' (' . $item['variant'] . ')' : '' !!} — {!! __('order_notifications.common.qty') !!}: {!! $item['qty'] !!}{!! $item['unit_price'] ? ' × ' . $item['unit_price'] : '' !!}{!! $item['total'] ? ' = ' . $item['total'] : '' !!}
@endforeach

{!! __('order_notifications.common.view_order') !!}: {!! $s['dashboard_url'] !!}

--
{!! __('order_notifications.common.reason') !!}
{!! __('order_notifications.common.help', ['email' => config('mail.from.address')]) !!}
