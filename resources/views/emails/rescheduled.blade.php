<?php
    $accent = config('booking.theme.primary', '#1f303a');
    $start  = \Carbon\Carbon::parse($payload['start']);
    $end    = \Carbon\Carbon::parse($payload['end']);
    $tz     = config('booking.organizer.timezone', 'UTC');
?>
<h1 style="font-size:24px;line-height:1.25;margin:0 0 12px;color:#0f172a;">
    {{ __('booking::booking.mail_rescheduled_heading') }}
</h1>
<p style="margin:0 0 20px;color:#475569;font-size:15px;">
    {{ __('booking::booking.mail_rescheduled_intro') }}
</p>

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
       style="border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;margin:0 0 20px;font-size:15px;">
    <tr>
        <td style="padding:14px 16px;background:#f8fafc;color:#475569;width:40%;">
            <strong>{{ __('booking::booking.mail_label_new_time') }}</strong>
        </td>
        <td style="padding:14px 16px;">
            {{ $start->setTimezone($tz)->isoFormat('dddd, D MMMM YYYY · HH:mm') }} - {{ $end->setTimezone($tz)->isoFormat('HH:mm') }}
            <div style="color:#94a3b8;font-size:12px;margin-top:4px;">{{ $tz }}</div>
        </td>
    </tr>
    @if (!empty($payload['meet_link']))
        <tr>
            <td style="padding:14px 16px;background:#f8fafc;color:#475569;border-top:1px solid #e5e7eb;">
                <strong>{{ $payload['location_label'] ?? 'Meeting link' }}</strong>
            </td>
            <td style="padding:14px 16px;border-top:1px solid #e5e7eb;word-break:break-all;">
                <a href="{{ $payload['meet_link'] }}" style="color:{{ $accent }};">{{ $payload['meet_link'] }}</a>
            </td>
        </tr>
    @endif
</table>

<p style="margin:0 0 24px;color:#475569;font-size:14px;">
    {{ __('booking::booking.mail_attachment_hint') }}
</p>

@if (!empty($payload['reschedule_url']))
    <p style="margin:0;font-size:14px;">
        {{ __('booking::booking.mail_need_change_again') }}
        <a href="{{ $payload['reschedule_url'] }}" style="color:{{ $accent }};">{{ __('booking::booking.mail_reschedule_link') }}</a>
    </p>
@endif
