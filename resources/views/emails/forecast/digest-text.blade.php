{!! $headline !!}

{!! $next28 !!}
@if ($accuracy)
{!! $accuracy !!}
@endif
@if ($atRisk)

{!! __('forecast.digest.at_risk') !!}:
@foreach ($atRisk as $row)
- {!! $row !!}
@endforeach
@endif
@if ($events)

{!! __('forecast.digest.events') !!}:
@foreach ($events as $row)
- {!! $row !!}
@endforeach
@endif

{!! $cta !!}: {!! $url !!}

--
{!! $footer !!}
