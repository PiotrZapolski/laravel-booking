<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Calendar driver
    |--------------------------------------------------------------------------
    |
    | Which calendar backend this install writes to and checks for conflicts.
    |
    |   google     Google Calendar via OAuth (the default, see below)
    |   microsoft  Microsoft 365 / Outlook via Graph, app-only credentials
    |   caldav     Any CalDAV server: iCloud, Nextcloud, Fastmail, Radicale
    |
    | Only the block for the driver you pick needs to be filled in.
    |
    */

    'calendar' => [
        // google | microsoft | caldav
        'driver' => env('BOOKING_CALENDAR_DRIVER', 'google'),
    ],

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

    /*
    |--------------------------------------------------------------------------
    | Microsoft 365 (Graph)
    |--------------------------------------------------------------------------
    |
    | App-only (client credentials) access to one mailbox. The Azure app needs
    | the application permissions Calendars.ReadWrite and, for free/busy across
    | other mailboxes, Calendars.Read.Shared, both granted admin consent.
    |
    */

    'microsoft' => [
        'tenant_id'     => env('BOOKING_MS_TENANT_ID'),
        'client_id'     => env('BOOKING_MS_CLIENT_ID'),
        'client_secret' => env('BOOKING_MS_CLIENT_SECRET'),
        // UPN / email of the mailbox whose calendar is used, e.g. piotr@contoso.com
        'user'          => env('BOOKING_MS_USER'),
        // Specific calendar id; null = the mailbox's default calendar
        'calendar_id'   => env('BOOKING_MS_CALENDAR_ID'),
        // Additional mailboxes / room addresses to treat as busy (getSchedule)
        'busy_schedules' => array_filter(explode(',', (string) env('BOOKING_MS_BUSY_SCHEDULES', ''))),
    ],

    /*
    |--------------------------------------------------------------------------
    | CalDAV
    |--------------------------------------------------------------------------
    |
    | Works with iCloud, Nextcloud, Fastmail, Radicale and friends. No native
    | video conferencing: use `location` => 'zoom' or 'custom' on event types.
    |
    */

    'caldav' => [
        // Full URL of the calendar collection, e.g. https://caldav.icloud.com/<id>/calendars/<cal>/
        'url'      => env('BOOKING_CALDAV_URL'),
        'username' => env('BOOKING_CALDAV_USERNAME'),
        'password' => env('BOOKING_CALDAV_PASSWORD'),   // iCloud: app-specific password
        // Additional collection URLs whose events count as busy
        'busy_urls' => array_filter(explode(',', (string) env('BOOKING_CALDAV_BUSY_URLS', ''))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Zoom
    |--------------------------------------------------------------------------
    |
    | Server-to-Server OAuth app with the meeting:write:admin scope. Used by
    | event types whose `location` is 'zoom', with any calendar driver.
    |
    */

    'zoom' => [
        'account_id'    => env('BOOKING_ZOOM_ACCOUNT_ID'),
        'client_id'     => env('BOOKING_ZOOM_CLIENT_ID'),
        'client_secret' => env('BOOKING_ZOOM_CLIENT_SECRET'),
        // Zoom user (email or id) who hosts the meetings; 'me' = the S2S app owner
        'user'          => env('BOOKING_ZOOM_USER', 'me'),
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

        // Optional branding the lifecycle email layout reads. Set these per-host
        // and every confirmation / reschedule / cancel email picks them up
        // without forking the templates.
        'logo_url'    => env('BOOKING_MAIL_LOGO_URL'),
        'brand_url'   => env('BOOKING_MAIL_BRAND_URL', env('APP_URL')),
        'footer_html' => env('BOOKING_MAIL_FOOTER_HTML'),
    ],

    'notifications' => [
        // Slack incoming webhook; when set, booking created/rescheduled/cancelled posts a message
        'slack_webhook_url' => env('BOOKING_SLACK_WEBHOOK_URL'),
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
    | `location` says where the meeting happens:
    |
    |   'google_meet'  native Meet link, created by the google driver
    |   'teams'        native Teams meeting, created by the microsoft driver
    |   'zoom'         Zoom meeting created through the zoom credentials above,
    |                  works with every calendar driver
    |   'custom'       static location. Add 'location_url' (a link, shown as the
    |                  join button) and/or 'location_text' (address, "phone call").
    |                  'location_label' overrides the label shown to bookers.
    |   null / absent  no location at all
    |
    | Asking for a native conference the active driver cannot create (Meet on
    | caldav, say) logs a warning and books the meeting without a link.
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

        // A Teams meeting. Requires 'calendar.driver' => 'microsoft'.
        // 'teams-call' => [
        //     'title'             => 'Teams call',
        //     'duration_minutes'  => 30,
        //     'location'          => 'teams',
        //     'slot_step_minutes' => 30,
        //     'availability'      => [
        //         1 => [['09:00', '17:00']],
        //         2 => [['09:00', '17:00']],
        //     ],
        //     'form_fields' => [
        //         ['name' => 'name',  'label' => 'Full name', 'type' => 'text',  'required' => true],
        //         ['name' => 'email', 'label' => 'Email',     'type' => 'email', 'required' => true],
        //     ],
        // ],

        // A Zoom meeting. Works with any calendar driver; the join URL is
        // stored as the calendar event location.
        // 'zoom-call' => [
        //     'title'             => 'Zoom call',
        //     'duration_minutes'  => 45,
        //     'location'          => 'zoom',
        //     'slot_step_minutes' => 15,
        //     'availability'      => [
        //         3 => [['10:00', '16:00']],
        //     ],
        //     'form_fields' => [
        //         ['name' => 'name',  'label' => 'Full name', 'type' => 'text',  'required' => true],
        //         ['name' => 'email', 'label' => 'Email',     'type' => 'email', 'required' => true],
        //     ],
        // ],

        // A fixed location: your own meeting room link, an office address or
        // a phone call. Nothing is created on any conferencing API.
        // 'office-visit' => [
        //     'title'             => 'Office visit',
        //     'duration_minutes'  => 60,
        //     'location'          => 'custom',
        //     'location_label'    => 'Our office',
        //     'location_text'     => 'Marszalkowska 1, Warsaw',
        //     // 'location_url'   => 'https://meet.example.com/room/42',
        //     'slot_step_minutes' => 60,
        //     'availability'      => [
        //         4 => [['09:00', '17:00']],
        //     ],
        //     'form_fields' => [
        //         ['name' => 'name',  'label' => 'Full name', 'type' => 'text',  'required' => true],
        //         ['name' => 'email', 'label' => 'Email',     'type' => 'email', 'required' => true],
        //     ],
        // ],

    ],

];
