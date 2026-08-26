<?php

namespace Zapol\Booking\Support;

/**
 * Turns an event type's `location` setting into the three things the rest of
 * the package needs:
 *
 *   - meet_provider    machine value: google_meet | teams | zoom | custom | null
 *   - location_label   human label printed in the widget, mails and the ICS
 *   - location_url / location_text  what to store on the calendar event
 *
 * Supported `location` values:
 *   'google_meet'  native Google Meet conference (Google driver)
 *   'teams'        native Microsoft Teams meeting (Microsoft driver)
 *   'zoom'         Zoom meeting created through ZoomConferenceProvider
 *   'custom'       static location; reads `location_url` and/or `location_text`
 *   null           no location at all
 */
class LocationResolver
{
    public const NATIVE_CONFERENCES = ['google_meet', 'teams'];

    /** @var array<string,string> */
    private const LABELS = [
        'google_meet' => 'Google Meet',
        'teams'       => 'Microsoft Teams',
        'zoom'        => 'Zoom',
    ];

    /**
     * @param array<string,mixed> $eventType
     */
    public static function meetProvider(array $eventType): ?string
    {
        $location = $eventType['location'] ?? null;
        if (!is_string($location) || $location === '') {
            return null;
        }

        return in_array($location, ['google_meet', 'teams', 'zoom', 'custom'], true) ? $location : null;
    }

    /**
     * Human label: "Google Meet", "Microsoft Teams", "Zoom", or, for custom
     * locations, the event type's own `location_label` / `location_text`.
     *
     * @param array<string,mixed> $eventType
     */
    public static function label(array $eventType): ?string
    {
        $provider = self::meetProvider($eventType);
        if ($provider === null) {
            return null;
        }

        if (isset(self::LABELS[$provider])) {
            return self::LABELS[$provider];
        }

        // custom
        $label = $eventType['location_label'] ?? null;
        if (is_string($label) && $label !== '') {
            return $label;
        }

        $text = self::text($eventType);
        if ($text !== null) {
            return $text;
        }

        return self::url($eventType) !== null ? 'Meeting link' : null;
    }

    /**
     * The static URL configured on a `custom` event type, if any.
     *
     * @param array<string,mixed> $eventType
     */
    public static function url(array $eventType): ?string
    {
        $url = $eventType['location_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * The static text configured on a `custom` event type, if any.
     *
     * @param array<string,mixed> $eventType
     */
    public static function text(array $eventType): ?string
    {
        $text = $eventType['location_text'] ?? null;

        return is_string($text) && $text !== '' ? $text : null;
    }

    /**
     * Does this event type ask the calendar driver for a native conference?
     *
     * @param array<string,mixed> $eventType
     */
    public static function nativeConference(array $eventType): ?string
    {
        $provider = self::meetProvider($eventType);

        return in_array($provider, self::NATIVE_CONFERENCES, true) ? $provider : null;
    }

    /**
     * What to store in the calendar event's location field. A conference URL
     * created by a standalone provider (Zoom) wins; otherwise a custom URL,
     * otherwise custom text.
     *
     * @param array<string,mixed> $eventType
     */
    public static function calendarLocation(array $eventType, ?string $conferenceUrl = null): ?string
    {
        if ($conferenceUrl !== null && $conferenceUrl !== '') {
            return $conferenceUrl;
        }

        if (self::meetProvider($eventType) !== 'custom') {
            return null;
        }

        return self::url($eventType) ?? self::text($eventType);
    }

    /**
     * The link a booker should click. Native conference link first, then the
     * standalone conference join URL, then a custom URL.
     *
     * @param array<string,mixed> $eventType
     */
    public static function meetLink(array $eventType, ?string $nativeLink = null, ?string $conferenceUrl = null): ?string
    {
        foreach ([$nativeLink, $conferenceUrl] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return self::meetProvider($eventType) === 'custom' ? self::url($eventType) : null;
    }

    /**
     * Everything at once, for JSON payloads.
     *
     * @param array<string,mixed> $eventType
     * @return array{meet_provider:?string,location_label:?string,location_url:?string,location_text:?string}
     */
    public static function describe(array $eventType, ?string $conferenceUrl = null): array
    {
        $provider = self::meetProvider($eventType);

        return [
            'meet_provider'  => $provider,
            'location_label' => self::label($eventType),
            'location_url'   => $conferenceUrl ?: ($provider === 'custom' ? self::url($eventType) : null),
            'location_text'  => $provider === 'custom' ? self::text($eventType) : null,
        ];
    }
}
