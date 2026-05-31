<!doctype html>
<html lang="{{ config('booking.theme.lang', 'en') }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{{ __('booking::booking.reschedule_title') }}</title>
    <style>
        body { font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif; background:#f8f9fa; margin:0; padding:32px; color:{{ config('booking.theme.secondary', '#1f303a') }}; }
        .container { max-width:1024px; margin:0 auto; }
    </style>
</head>
<body>
    <div class="container">
        <h1 style="font-size:20px;">{{ __('booking::booking.reschedule_title') }}</h1>
        <div id="booking-widget"></div>
        <script
            src="{{ route('booking.widget.script') }}"
            data-event-type="{{ $eventType }}"
            data-reschedule-token="{{ $token }}"
            data-primary="{{ config('booking.theme.primary', '#47b2e4') }}"
            data-lang="{{ config('booking.theme.lang', 'en') }}"
            defer></script>
    </div>
</body>
</html>
