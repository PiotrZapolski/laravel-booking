<?php

namespace Zapol\Booking\Calendar;

use RuntimeException;
use Zapol\Booking\Contracts\CalendarProvider;
use Zapol\Booking\Services\Google\GoogleCalendarService;

/**
 * Picks the calendar driver for this install from `booking.calendar.driver`.
 *
 * Every driver is registered as a singleton by the service provider, so the
 * manager only has to map a driver name onto a class and let the container
 * build it. Unknown driver names fail loudly instead of silently falling back
 * to Google, so a typo in .env is obvious the first time a slot is requested.
 */
class CalendarManager
{
    public const DRIVERS = ['google', 'microsoft', 'caldav'];

    /**
     * @param \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Container\Container $app
     */
    public static function resolve($app): CalendarProvider
    {
        $driver = (string) ($app['config']->get('booking.calendar.driver') ?: 'google');

        switch ($driver) {
            case 'google':
                return $app->make(GoogleCalendarService::class);
            case 'microsoft':
                return $app->make(MicrosoftCalendarProvider::class);
            case 'caldav':
                return $app->make(CalDavCalendarProvider::class);
        }

        throw new RuntimeException(
            "Booking package: unknown calendar driver [{$driver}]. Set BOOKING_CALENDAR_DRIVER to one of: "
            . implode(', ', self::DRIVERS) . '.'
        );
    }
}
