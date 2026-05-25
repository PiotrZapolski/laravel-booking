# laravel-booking

A self-hosted, Google-Calendar-synced booking widget for Laravel 9–13. Drop-in replacement for Zencal / Calendly when you only need scheduling and want to own the data, the look, and the form.

Config-driven (no admin panel), embeds with a single `<script>` tag, supports **multiple availability windows per weekday**, and accepts pre-filled form data so users never re-type information they already gave you on a previous step.

## What it does

- Embeds a booking widget on any page via one `<script>` tag
- Reads `data-*` attributes from the script tag to pre-fill form fields and lock them as read-only — perfect for qualification preforms that flow into a calendar
- Computes available slots from configured weekly windows minus busy intervals on your Google Calendar (`freebusy.query`)
- Creates Google Calendar events with attendee invites + Google Meet links on booking
- Sends confirmation emails to attendee + organiser with ICS attachments
- Reschedule and cancel via Laravel-signed URLs — no database table required
- Works across stacks: October CMS / Twig, plain Blade, anywhere a `<script>` tag fits

## Install

```bash
composer require zapol/laravel-booking
php artisan vendor:publish --tag=booking-config
php artisan vendor:publish --tag=booking-assets   # optional, if you want a copy in public/
```

Add the env keys:

```env
BOOKING_GOOGLE_CLIENT_ID=...
BOOKING_GOOGLE_CLIENT_SECRET=...
BOOKING_GOOGLE_REFRESH_TOKEN=...
BOOKING_GOOGLE_CALENDAR_ID=primary

BOOKING_ORGANIZER_NAME="Piotr"
BOOKING_ORGANIZER_EMAIL=piotr@example.com
BOOKING_ORGANIZER_TZ=Europe/Warsaw

BOOKING_MAIL_FROM=noreply@example.com
BOOKING_MAIL_FROM_NAME="AgentsHub"
```

### One-time Google auth

Create OAuth credentials at <https://console.cloud.google.com/apis/credentials> with redirect URI `urn:ietf:wg:oauth:2.0:oob` (Desktop app type works). Then:

```bash
php artisan booking:google-auth
```

It prints an auth URL, you sign in with the Google account this install should book on behalf of, paste the code back, and it gives you the `BOOKING_GOOGLE_REFRESH_TOKEN` to drop into `.env`. Each install can use a **different Google account** — just run the command separately per project.

## Configure event types

`config/booking.php`:

```php
'event_types' => [
    'consultation' => [
        'title'             => 'Free Consultation',
        'description'       => 'Let\'s talk about your project on a video call.',
        'duration_minutes'  => 60,
        'location'          => 'google_meet',
        'min_notice_hours'  => 12,
        'max_advance_days'  => 60,
        'slot_step_minutes' => 60,

        // ISO weekday (1=Mon..7=Sun) → list of [start, end] windows
        'availability' => [
            1 => [['09:00', '12:00'], ['13:00', '15:00'], ['16:00', '18:00']],
            2 => [['11:00', '15:00']],
            3 => [['09:00', '12:00'], ['14:00', '17:00']],
            4 => [['09:00', '12:00']],
            5 => [['09:00', '14:00']],
        ],

        'form_fields' => [
            ['name' => 'name',           'label' => 'Full name', 'type' => 'text',     'required' => true],
            ['name' => 'email',          'label' => 'Email',     'type' => 'email',    'required' => true],
            ['name' => 'phone',          'label' => 'Phone',     'type' => 'tel',      'required' => false],
            ['name' => 'company',        'label' => 'Company',   'type' => 'text',     'required' => false],
            ['name' => 'employee_count', 'label' => 'Employees', 'type' => 'number',   'required' => false],
            ['name' => 'description',    'label' => 'Details',   'type' => 'textarea', 'required' => false],
        ],
    ],
],
```

Adding a second event type is just another key under `event_types`.

## Embed the widget

```html
<div id="booking-widget"></div>
<script
    src="https://your-app.test/booking/widget.js"
    data-event-type="consultation"
    data-name="Jan Kowalski"
    data-email="jan@example.com"
    data-fields-company="Acme Sp. z o.o."
    data-fields-employee_count="12"
    data-primary="#47b2e4"
    data-lang="pl"
    defer></script>
```

Any `data-fields-<name>` attribute pre-fills the field with `name="<name>"` in the booking form. Pre-filled fields render as **read-only with a small "from previous step" hint** so the user can verify them — not hidden inputs.

### October CMS / Twig (e.g. AgentsHub)

```twig
<script
    src="/booking/widget.js"
    data-event-type="consultation"
    data-email="{{ form.email|e('html_attr') }}"
    data-fields-company="{{ form.company_name|e('html_attr') }}"
    data-fields-employee_count="{{ form.employee_count }}"
    data-lang="pl"
    defer></script>
<div id="booking-widget"></div>
```

### Laravel Blade (e.g. TenfoldDevs)

```blade
<script
    src="{{ url('/booking/widget.js') }}"
    data-event-type="consultation"
    data-name="{{ $name }}"
    data-email="{{ $email }}"
    data-fields-company="{{ $company }}"
    data-lang="pl"
    defer></script>
<div id="booking-widget"></div>
```

## How it works

| Endpoint | What it does |
|---|---|
| `GET /booking/widget.js` | Serves the JS widget bundle |
| `GET /booking/api/event-types/{slug}` | Returns event type metadata + form schema |
| `GET /booking/api/event-types/{slug}/slots?from=…&to=…` | Returns available slots (windows minus busy) |
| `POST /booking/api/bookings` | Creates the Google Calendar event, sends emails |
| `GET /booking/reschedule/{token}` | Renders the widget in reschedule mode |
| `POST /booking/api/bookings/{token}/reschedule` | Patches the event start/end |
| `POST /booking/api/bookings/{token}/cancel` | Deletes the event, notifies both sides |

Reschedule and cancel use Laravel signed-URL-style tokens (HMAC-SHA256 over a JSON payload, keyed by `APP_KEY`). No bookings table required — Google Calendar is the source of truth.

## Optional bookings table

Set `'persist_bookings' => true` in config and run:

```bash
php artisan vendor:publish --tag=booking-migrations
php artisan migrate
```

This is purely for audit / analytics; the package works without it.

## Customise emails

```bash
php artisan vendor:publish --tag=booking-views
```

Then edit `resources/views/vendor/booking/emails/*.blade.php`.

## Testing

```bash
composer install
vendor/bin/phpunit
```

Unit tests cover the availability calculator (multi-window math, busy-overlap, min notice) and the token signer (round-trip, tamper detection, expiry).

## License

MIT
