@extends('booking::emails._layout', [
    'title' => __('booking::booking.mail_org_title', ['title' => $eventType['title'] ?? '']),
])

@section('content')
<?php
    $accent = config('booking.theme.primary', '#1f303a');
    $start  = \Carbon\Carbon::parse($payload['start']);
    $end    = \Carbon\Carbon::parse($payload['end']);
    $tz     = config('booking.organizer.timezone', 'UTC');
?>
<h1 style="font-size:22px;line-height:1.25;margin:0 0 16px;color:#0f172a;">
    {{ __('booking::booking.mail_org_heading') }}: {{ $eventType['title'] ?? '' }}
</h1>

<p style="margin:0 0 18px;font-size:15px;">
    <strong>{{ $start->setTimezone($tz)->isoFormat('dddd, D MMMM YYYY · HH:mm') }} – {{ $end->setTimezone($tz)->isoFormat('HH:mm') }}</strong>
    <span style="color:#94a3b8;">({{ $tz }})</span>
</p>

@if (!empty($payload['meet_link']))
    <p style="margin:0 0 18px;">
        <a href="{{ $payload['meet_link'] }}"
           style="display:inline-block;background:{{ $accent }};color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:600;font-size:14px;">
            Google Meet →
        </a>
    </p>
@endif

@if (!empty($payload['html_link']))
    <p style="margin:0 0 18px;font-size:14px;">
        <a href="{{ $payload['html_link'] }}" style="color:{{ $accent }};">{{ __('booking::booking.mail_org_open_calendar') }}</a>
    </p>
@endif

@if (!empty($fieldRows))
    <h2 style="font-size:16px;margin:18px 0 10px;color:#0f172a;">{{ __('booking::booking.mail_org_attendee') }}</h2>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
           style="border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;font-size:14px;">
        @foreach ($fieldRows as $row)
            <tr>
                <td style="padding:10px 14px;background:#f8fafc;color:#475569;width:40%;@if(!$loop->first) border-top:1px solid #e5e7eb; @endif">
                    <strong>{{ $row['label'] }}</strong>
                </td>
                <td style="padding:10px 14px;color:#0f172a;@if(!$loop->first) border-top:1px solid #e5e7eb; @endif">
                    {{ $row['value'] }}
                </td>
            </tr>
        @endforeach
    </table>
@endif
@endsection
