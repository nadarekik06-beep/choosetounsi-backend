{!! $title !!}

{!! $intro !!}

@foreach ($items as $item)
- {!! $item['name'] !!} — {!! $item['line'] !!}
@endforeach

{!! $cta !!}: {!! $url !!}

--
{!! $footer !!}
