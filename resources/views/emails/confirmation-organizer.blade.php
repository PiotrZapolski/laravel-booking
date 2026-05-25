<!doctype html>
<html>
<body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#1f303a;line-height:1.5;max-width:560px;margin:0 auto;padding:24px;">
    <h2 style="margin-top:0;">New booking: {{ $eventType['title'] ?? 'event' }}</h2>

    <p><strong>{{ \Carbon\Carbon::parse($payload['start'])->format('D, j M Y · H:i') }} – {{ \Carbon\Carbon::parse($payload['end'])->format('H:i') }}</strong></p>

    @if(!empty($payload['fields']))
        <h3>Attendee</h3>
        <table cellpadding="6" style="border-collapse:collapse;">
            @foreach($payload['fields'] as $k => $v)
                @if($v !== null && $v !== '')
                <tr><td><strong>{{ $k }}</strong></td><td>{{ is_scalar($v) ? $v : json_encode($v) }}</td></tr>
                @endif
            @endforeach
        </table>
    @endif

    @if(!empty($payload['html_link']))
        <p><a href="{{ $payload['html_link'] }}">Open in Google Calendar</a></p>
    @endif
</body>
</html>
