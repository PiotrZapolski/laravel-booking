<?php

namespace Zapol\Booking\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Zapol\Booking\Support\EventTypeResolver;
use Zapol\Booking\Support\LocationResolver;

class EventTypeController extends Controller
{
    public function show(string $slug): JsonResponse
    {
        $type = EventTypeResolver::find($slug);
        if (!$type) {
            return response()->json(['error' => 'event_type_not_found'], 404);
        }

        $organizer = config('booking.organizer');
        $theme = config('booking.theme');

        return response()->json([
            'slug'           => $slug,
            'title'          => $type['title'] ?? $slug,
            'description'    => $type['description'] ?? '',
            'duration'       => (int)($type['duration_minutes'] ?? 30),
            'location'       => $type['location'] ?? null,
            'meet_provider'  => LocationResolver::meetProvider($type),
            'location_label' => LocationResolver::label($type),
            'timezone'       => $organizer['timezone'] ?? 'UTC',
            'organizer'      => [
                'name'  => $organizer['name'] ?? null,
                'email' => $organizer['email'] ?? null,
            ],
            'form_fields'    => $type['form_fields'] ?? [],
            'theme'          => $theme,
        ]);
    }
}
