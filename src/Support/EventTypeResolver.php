<?php

namespace Zapol\Booking\Support;

class EventTypeResolver
{
    /**
     * @return array<string,mixed>|null
     */
    public static function find(string $slug): ?array
    {
        $type = config("booking.event_types.{$slug}");
        return is_array($type) ? $type : null;
    }
}
