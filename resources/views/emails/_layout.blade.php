{{-- Shared base layout for every booking lifecycle email. Hosts can publish
     this file and replace the logo / footer block freely; the section
     placeholders below pull from the per-template render. --}}
<?php
    $accent = $accent ?? config('booking.theme.primary', '#1f303a');
    $brand = $brand ?? config('booking.mail.from_name', config('booking.organizer.name', 'Booking'));
    $brandUrl = $brandUrl ?? config('app.url');
    $logoUrl = $logoUrl ?? null;
    $footerHtml = $footerHtml ?? '';
?>
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="color-scheme" content="light only" />
    <title>{{ $title ?? $brand }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f6f8;color:#1f303a;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;line-height:1.5;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f5f6f8;padding:32px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="max-width:600px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 1px 2px rgba(15,23,42,0.04),0 4px 12px rgba(15,23,42,0.04);">
                <tr>
                    <td style="background:{{ $accent }};padding:20px 28px;color:#ffffff;">
                        @if ($logoUrl)
                            <a href="{{ $brandUrl }}" style="display:inline-block;text-decoration:none;">
                                <img src="{{ $logoUrl }}" alt="{{ $brand }}" height="32" style="display:block;border:0;height:32px;max-height:32px;" />
                            </a>
                        @else
                            <a href="{{ $brandUrl }}" style="color:#ffffff;text-decoration:none;font-weight:700;letter-spacing:0.02em;font-size:18px;">{{ $brand }}</a>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding:28px 28px 8px;">
                        @yield('content')
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 28px 28px;border-top:1px solid #eef0f3;color:#6b7280;font-size:13px;line-height:1.6;">
                        @if (!empty($footerHtml))
                            {!! $footerHtml !!}
                        @else
                            <a href="{{ $brandUrl }}" style="color:{{ $accent }};text-decoration:none;">{{ $brand }}</a>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
