<!doctype html>
<html>
<body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#1f303a;line-height:1.5;max-width:560px;margin:0 auto;padding:24px;">
    <h2 style="margin-top:0;">{{ $eventType['title'] ?? 'Booking' }} — rescheduled</h2>

    <p>The meeting was moved to a new time.</p>

    <p><strong>{{ \Carbon\Carbon::parse($payload['start'])->format('D, j M Y · H:i') }} – {{ \Carbon\Carbon::parse($payload['end'])->format('H:i') }}</strong></p>

    <p>
        Need to change it again?
        <a href="{{ $payload['reschedule_url'] }}">Reschedule or cancel</a>.
    </p>
</body>
</html>
