<?php

namespace Zapol\Booking\Support;

use Illuminate\Support\Facades\App;

/**
 * Shared helpers used by every package mailable so they all localise the
 * same way and all render form fields with user-facing labels (not raw
 * config keys).
 */
class MailLocale
{
    /**
     * Switch Laravel's locale for the duration of the mailable build, based
     * on (in priority order):
     *   1. `lang` on the booking payload (carried from the widget's data-lang)
     *   2. `theme.lang` on the package config
     *   3. the currently-active app locale (no change)
     */
    public static function apply(array $payload, array $eventType): void
    {
        $lang = $payload['lang']
            ?? $eventType['lang']
            ?? config('booking.theme.lang')
            ?? App::getLocale();

        if (is_string($lang) && $lang !== '') {
            try {
                App::setLocale($lang);
            } catch (\Throwable $e) {
                // Unknown locale - leave the previously-active one alone.
            }
        }
    }

    /**
     * Build a presentation-ready array of {label, value} pairs from the
     * payload['fields'] entries, looking up the human label from the
     * eventType['form_fields'] config so the email shows "Imię i nazwisko"
     * instead of "name".
     *
     * @return array<int,array{label:string,value:string}>
     */
    public static function renderFieldRows(array $payload, array $eventType): array
    {
        $values = $payload['fields'] ?? [];
        if (!is_array($values) || empty($values)) {
            return [];
        }

        $labels = [];
        foreach ($eventType['form_fields'] ?? [] as $f) {
            if (!is_array($f) || empty($f['name'])) {
                continue;
            }
            $labels[$f['name']] = $f['label'] ?? $f['name'];
        }

        $rows = [];
        foreach ($values as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $rows[] = [
                'label' => $labels[$name] ?? ucfirst((string) $name),
                'value' => is_scalar($value) ? (string) $value : json_encode($value),
            ];
        }
        return $rows;
    }
}
