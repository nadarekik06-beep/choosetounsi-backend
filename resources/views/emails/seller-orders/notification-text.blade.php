{{-- Plain-text part of the seller order e-mail (better deliverability, simple clients). --}}
@php $k = "order_notifications.$event"; @endphp
{!! __("$k.headline") !!}

{!! $sellerName !== '' ? __('order_notifications.common.greeting', ['name' => $sellerName]) : __('order_notifications.common.greeting_anon') !!}
{!! __("$k.intro") !!}

{!! __("$k.ref_hint") !!}: {!! $s['reference'] !!}
@if ($s['buyer'] !== '')
{!! __('order_notifications.common.buyer') !!}: {!! $s['buyer'] !!}
@endif

{!! __('order_notifications.common.items') !!}:
@foreach ($s['items'] as $item)
- {!! $item['name'] !!}{!! $item['variant'] ? ' (' . $item['variant'] . ')' : '' !!} × {!! $item['qty'] !!}{!! $item['total'] ? ' — ' . $item['total'] : '' !!}
@endforeach

{!! __('order_notifications.common.items_total') !!}: {!! $s['items_total'] !!}
@if ($s['discount'])
{!! __('order_notifications.common.discount') !!}: -{!! $s['discount'] !!}
@endif
{!! __('order_notifications.common.commission') !!}: -{!! $s['commission'] !!}
@if ($s['shipping'])
{!! __('order_notifications.common.shipping') !!}: -{!! $s['shipping'] !!}
@endif
{!! __('order_notifications.common.net') !!}: {!! $s['net_label'] !!}
@if ($pickup)

{!! __('order_notifications.pickup.heading') !!}:
{!! $pickup['shop'] !!}
{!! $pickup['address'] ?: '—' !!}
@unless ($pickup['complete'])
! {!! __('order_notifications.pickup.incomplete', ['fields' => implode(', ', $pickup['missing'])]) !!}
{!! __('order_notifications.pickup.fix') !!}: {!! $pickup['settings_url'] !!}
@endunless

{!! __('order_notifications.tips.heading') !!}:
@foreach (__('order_notifications.tips.list') as $tip)
- {!! str_replace(':ref', $s['reference'], $tip) !!}
@endforeach
{!! __("$k.next") !!}
@endif

{!! __('order_notifications.common.view_order') !!}: {!! $s['dashboard_url'] !!}

--
{!! __('order_notifications.common.reason') !!}
{!! __('order_notifications.common.help', ['email' => config('mail.from.address')]) !!}
