<?php

namespace Zapol\Booking\Conference;

use Zapol\Booking\Contracts\ConferenceProvider;

/**
 * Maps an event type `location` value onto a standalone conference provider.
 *
 * Only locations that need a separate API call land here. `google_meet` and
 * `teams` are created by the calendar driver itself, `custom` is static text,
 * so all of those resolve to null.
 */
class ConferenceResolver
{
    /**
     * @param \Illuminate\Contracts\Container\Container|null $container Defaults to the app container.
     */
    public static function for(?string $location, $container = null): ?ConferenceProvider
    {
        if ($location !== 'zoom') {
            return null;
        }

        $container = $container ?: app();

        return $container->make(ZoomConferenceProvider::class);
    }
}
