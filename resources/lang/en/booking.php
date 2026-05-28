<?php

return [
    // Widget UI
    'title'              => 'Book a meeting',
    'reschedule_title'   => 'Reschedule your meeting',
    'error_title'        => 'Something went wrong',
    'pick_date'          => 'Pick a date',
    'pick_time'          => 'Pick a time',
    'your_details'       => 'Your details',
    'confirm'            => 'Confirm',
    'back_to_calendar'   => 'Back to calendar',
    'cancel'             => 'Cancel meeting',
    'no_slots'           => 'No times available — try another day.',
    'success_title'      => 'Booking confirmed',
    'success_subtitle'   => 'You will receive a confirmation email shortly.',
    'change_time'        => 'Change time',
    'cancelled_title'    => 'Meeting cancelled',
    'prefilled_hint'     => 'from previous step',

    // Mail subjects
    'subject_confirmation_attendee'  => ':title — confirmed',
    'subject_confirmation_organizer' => 'New booking: :title',
    'subject_rescheduled'            => ':title — rescheduled',
    'subject_cancelled'              => ':title — cancelled',

    // Mail copy — attendee confirmation
    'mail_confirmation_title'   => ':title — confirmed',
    'mail_confirmation_heading' => 'Your meeting is confirmed',
    'mail_confirmation_intro'   => 'Thanks for booking. Here are the details — see you soon.',
    'mail_label_when'           => 'Date & time',
    'mail_btn_join'             => 'Join Google Meet',
    'mail_your_details'         => 'Your details',
    'mail_attachment_hint'      => 'A calendar invite is attached — open it to add the meeting to your calendar. You should also receive a separate invite from Google Calendar.',
    'mail_need_change'          => 'Need a different time?',
    'mail_reschedule_link'      => 'Reschedule or cancel',

    // Mail copy — organizer notification
    'mail_org_title'         => 'New booking: :title',
    'mail_org_heading'       => 'New booking',
    'mail_org_open_calendar' => 'Open in Google Calendar →',
    'mail_org_attendee'      => 'Attendee',

    // Mail copy — rescheduled
    'mail_rescheduled_title'    => ':title — rescheduled',
    'mail_rescheduled_heading'  => 'Your meeting was moved',
    'mail_rescheduled_intro'    => 'The meeting has been rescheduled to a new time.',
    'mail_label_new_time'       => 'New date & time',
    'mail_need_change_again'    => 'Need to change it again?',

    // Mail copy — cancelled
    'mail_cancelled_title'             => ':title — cancelled',
    'mail_cancelled_heading'           => 'Meeting cancelled',
    'mail_cancelled_intro'             => 'The meeting has been cancelled. We hope to see you another time.',
    'mail_label_was'                   => 'Original time',
    'mail_cancelled_attachment_hint'   => 'A cancellation invite is attached — opening it will remove the event from your calendar automatically.',
];
