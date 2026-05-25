<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google Calendar
    |--------------------------------------------------------------------------
    |
    | OAuth credentials for the Google account that owns the calendar. Each
    | install of this package authorises its own Google account, so projects
    | can use different organiser identities. Run `php artisan booking:google-auth`
    | once to mint the refresh token.
    |
    */

    'google' => [
        // OAuth client credentials. Two ways to provide them:
        //   1. Drop the JSON file downloaded from Google Cloud Console into the
        //      project and point credentials_file at it (BOOKING_GOOGLE_CREDENTIALS_FILE).
        //      That wins if present.
        //   2. Set BOOKING_GOOGLE_CLIENT_ID / BOOKING_GOOGLE_CLIENT_SECRET in .env.
        'credentials_file' => env('BOOKING_GOOGLE_CREDENTIALS_FILE'),
        'client_id'        => env('BOOKING_GOOGLE_CLIENT_ID'),
        'client_secret'    => env('BOOKING_GOOGLE_CLIENT_SECRET'),

        'refresh_token'  => env('BOOKING_GOOGLE_REFRESH_TOKEN'),
        'calendar_id'    => env('BOOKING_GOOGLE_CALENDAR_ID', 'primary'),
        'busy_calendars' => array_filter([
            env('BOOKING_GOOGLE_CALENDAR_ID', 'primary'),
        ]),
        'redirect_uri'   => env('BOOKING_GOOGLE_REDIRECT_URI'),
    ],

    'organizer' => [
        'name'     => env('BOOKING_ORGANIZER_NAME', 'Organizer'),
        'email'    => env('BOOKING_ORGANIZER_EMAIL'),
        'timezone' => env('BOOKING_ORGANIZER_TZ', 'Europe/Warsaw'),
    ],

    'mail' => [
        'from'             => env('BOOKING_MAIL_FROM', env('MAIL_FROM_ADDRESS')),
        'from_name'        => env('BOOKING_MAIL_FROM_NAME', env('MAIL_FROM_NAME', 'Booking')),
        'reply_to'         => env('BOOKING_MAIL_REPLY_TO'),
        'notify_organizer' => true,
    ],

    'route_prefix'     => 'booking',
    'persist_bookings' => false,
    'token_ttl_days'   => 60,

    'theme' => [
        'primary'   => '#47b2e4',
        'secondary' => '#1f303a',
        'lang'      => 'pl',
        'ampm'      => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Event Types
    |--------------------------------------------------------------------------
    |
    | Each event type is a bookable meeting kind. Availability is expressed
    | per ISO weekday (1=Mon..7=Sun) as a list of [start, end] windows. Add as
    | many windows per day as you need; omit a weekday to disable that day.
    |
    */

    'event_types' => [

        'consultation' => [
            'title'                => 'Free Consultation',
            'description'          => 'Let\'s talk about your project on a video call.',
            'duration_minutes'     => 60,
            'location'             => 'google_meet',
            'additional_attendees' => [],
            'min_notice_hours'     => 12,
            'max_advance_days'     => 60,
            'buffer_minutes'       => 0,
            'slot_step_minutes'    => 60,

            'availability' => [
                1 => [['09:00', '12:00'], ['13:00', '15:00'], ['16:00', '18:00']],
                2 => [['11:00', '15:00']],
                3 => [['09:00', '12:00'], ['14:00', '17:00']],
                4 => [['09:00', '12:00']],
                5 => [['09:00', '14:00']],
            ],

            'form_fields' => [
                ['name' => 'name',           'label' => 'Full name',        'type' => 'text',     'required' => true],
                ['name' => 'email',          'label' => 'Email',            'type' => 'email',    'required' => true],
                ['name' => 'phone',          'label' => 'Phone',            'type' => 'tel',      'required' => false],
                ['name' => 'company',        'label' => 'Company',          'type' => 'text',     'required' => false],
                ['name' => 'employee_count', 'label' => 'Employees',        'type' => 'number',   'required' => false],
                ['name' => 'description',    'label' => 'How can we help?', 'type' => 'textarea', 'required' => false],
            ],
        ],

    ],

];
