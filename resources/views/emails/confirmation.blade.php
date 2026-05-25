<!doctype html>
<html>
<body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#1f303a;line-height:1.5;max-width:560px;margin:0 auto;padding:24px;">
    <h2 style="margin-top:0;">{{ $eventType['title'] ?? 'Booking' }}</h2>

    <p>Your meeting has been confirmed.</p>

    <table cellpadding="6" style="border-collapse:collapse;">
        <tr><td><strong>Date & time</strong></td><td>{{ \Carbon\Carbon::parse($payload['start'])->format('D, j M Y · H:i') }} – {{ \Carbon\Carbon::parse($payload['end'])->format('H:i') }}</td></tr>
        @if(!empty($payload['meet_link']))
        <tr><td><strong>Join link</strong></td><td><a href="{{ $payload['meet_link'] }}">{{ $payload['meet_link'] }}</a></td></tr>
        @endif
    </table>

    @if(!empty($payload['fields']))
        <h3 style="margin-top:24px;">Your details</h3>
        <table cellpadding="6" style="border-collapse:collapse;">
            @foreach($payload['fields'] as $k => $v)
                @if($v !== null && $v !== '')
                <tr><td><strong>{{ $k }}</strong></td><td>{{ is_scalar($v) ? $v : json_encode($v) }}</td></tr>
                @endif
            @endforeach
        </table>
    @endif

    <p style="margin-top:24px;">
        Need a different time?
        <a href="{{ $payload['reschedule_url'] }}">Reschedule or cancel</a>.
    </p>
</body>
</html>
