<?php

namespace Zapol\Booking\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Throwable;
use Zapol\Booking\Services\AvailabilityCalculator;
use Zapol\Booking\Support\EventTypeResolver;

class SlotsController extends Controller
{
    public function index(Request $request, string $slug, AvailabilityCalculator $calc): JsonResponse
    {
        if (!EventTypeResolver::find($slug)) {
            return response()->json(['error' => 'event_type_not_found'], 404);
        }

        $tz = config('booking.organizer.timezone', 'UTC');
        try {
            $from = $request->query('from')
                ? CarbonImmutable::parse($request->query('from'), $tz)
                : CarbonImmutable::now($tz)->startOfDay();
            $to = $request->query('to')
                ? CarbonImmutable::parse($request->query('to'), $tz)
                : $from->addDays(60)->endOfDay();
        } catch (Throwable $e) {
            return response()->json(['error' => 'invalid_date_range'], 422);
        }

        try {
            $slots = $calc->slots($slug, $from, $to);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['error' => 'calendar_unavailable', 'message' => $e->getMessage()], 502);
        }

        return response()->json([
            'timezone' => $tz,
            'slots'    => $slots,
        ]);
    }
}
