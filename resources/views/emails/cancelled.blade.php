@extends('booking::emails._layout', [
    'title' => __('booking::booking.mail_cancelled_title', ['title' => $eventType['title'] ?? '']),
])

@section('content')
<?php
    $tz = config('booking.organizer.timezone', 'UTC');
    $hasTime = !empty($payload['start']) && !empty($payload['end']);
    $start = $hasTime ? \Carbon\Carbon::parse($payload['start']) : null;
    $end   = $hasTime ? \Carbon\Carbon::parse($payload['end'])   : null;
?>
<h1 style="font-size:24px;line-height:1.25;margin:0 0 12px;color:#0f172a;">
    {{ __('booking::booking.mail_cancelled_heading') }}
</h1>
<p style="margin:0 0 18px;color:#475569;font-size:15px;">
    {{ __('booking::booking.mail_cancelled_intro') }}
</p>

@if ($hasTime)
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
           style="border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;margin:0 0 20px;font-size:15px;">
        <tr>
            <td style="padding:14px 16px;background:#f8fafc;color:#475569;width:40%;">
                <strong>{{ __('booking::booking.mail_label_was') }}</strong>
            </td>
            <td style="padding:14px 16px;color:#0f172a;text-decoration:line-through;">
                {{ $start->setTimezone($tz)->isoFormat('dddd, D MMMM YYYY · HH:mm') }} – {{ $end->setTimezone($tz)->isoFormat('HH:mm') }}
                <div style="color:#94a3b8;font-size:12px;margin-top:4px;text-decoration:none;">{{ $tz }}</div>
            </td>
        </tr>
    </table>
@endif

<p style="margin:18px 0 0;color:#475569;font-size:14px;">
    {{ __('booking::booking.mail_cancelled_attachment_hint') }}
</p>
@endsection
