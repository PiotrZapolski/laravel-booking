<!doctype html>
<html lang="{{ config('booking.theme.lang', 'en') }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{{ __('booking::booking.title') }}</title>
</head>
<body style="margin:0;padding:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#fff;color:#1f303a;">
    <div id="booking-widget"></div>
    <script src="{{ route('booking.widget.script') }}" data-event-type="{{ $slug }}" defer></script>
</body>
</html>
