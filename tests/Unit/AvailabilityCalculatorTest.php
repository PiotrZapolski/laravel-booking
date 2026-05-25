<?php

namespace Zapol\Booking\Tests\Unit;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Zapol\Booking\Services\AvailabilityCalculator;
use Zapol\Booking\Services\Google\GoogleCalendarService;

class AvailabilityCalculatorTest extends TestCase
{
    public function test_multiple_windows_per_day_produce_distinct_slot_groups(): void
    {
        $busy = $this->busyService([]);

        $calc = new AvailabilityCalculator($busy, [
            'organizer' => ['timezone' => 'Europe/Warsaw'],
            'google'    => ['busy_calendars' => ['primary']],
            'event_types' => [
                'consultation' => [
                    'duration_minutes'  => 60,
                    'slot_step_minutes' => 60,
                    'buffer_minutes'    => 0,
                    'min_notice_hours'  => 0,
                    'max_advance_days'  => 9999,
                    'availability' => [
                        // Monday: three windows
                        1 => [['09:00', '12:00'], ['13:00', '15:00'], ['16:00', '18:00']],
                    ],
                ],
            ],
        ]);

        // Pick a Monday far in the future to bypass "now" thresholding
        $monday = CarbonImmutable::parse('2030-06-03', 'Europe/Warsaw')->startOfDay();
        $slots = $calc->slots('consultation', $monday, $monday->endOfDay());

        $times = array_map(fn ($s) => substr($s['start'], 11, 5), $slots);

        // 9-12 → 09:00, 10:00, 11:00 (each fits 60 min within window)
        // 13-15 → 13:00, 14:00
        // 16-18 → 16:00, 17:00
        $this->assertSame(['09:00', '10:00', '11:00', '13:00', '14:00', '16:00', '17:00'], $times);
    }

    public function test_busy_intervals_remove_overlapping_slots(): void
    {
        $busy = $this->busyService([
            [
                'start' => CarbonImmutable::parse('2030-06-03 10:00:00', 'Europe/Warsaw'),
                'end'   => CarbonImmutable::parse('2030-06-03 11:30:00', 'Europe/Warsaw'),
            ],
        ]);

        $calc = new AvailabilityCalculator($busy, [
            'organizer' => ['timezone' => 'Europe/Warsaw'],
            'google'    => ['busy_calendars' => ['primary']],
            'event_types' => [
                'consultation' => [
                    'duration_minutes'  => 60,
                    'slot_step_minutes' => 60,
                    'buffer_minutes'    => 0,
                    'min_notice_hours'  => 0,
                    'max_advance_days'  => 9999,
                    'availability' => [
                        1 => [['09:00', '13:00']],
                    ],
                ],
            ],
        ]);

        $day = CarbonImmutable::parse('2030-06-03', 'Europe/Warsaw');
        $slots = $calc->slots('consultation', $day->startOfDay(), $day->endOfDay());

        $times = array_map(fn ($s) => substr($s['start'], 11, 5), $slots);
        // 09:00 ok, 10:00 overlaps busy 10-11:30, 11:00 overlaps busy, 12:00 ok
        $this->assertSame(['09:00', '12:00'], $times);
    }

    public function test_min_notice_drops_slots_too_close_to_now(): void
    {
        $busy = $this->busyService([]);

        $calc = new AvailabilityCalculator($busy, [
            'organizer' => ['timezone' => 'Europe/Warsaw'],
            'google'    => ['busy_calendars' => ['primary']],
            'event_types' => [
                'consultation' => [
                    'duration_minutes'  => 30,
                    'slot_step_minutes' => 30,
                    'buffer_minutes'    => 0,
                    'min_notice_hours'  => 48,
                    'max_advance_days'  => 365,
                    'availability' => [
                        1 => [['09:00', '17:00']],
                        2 => [['09:00', '17:00']],
                        3 => [['09:00', '17:00']],
                        4 => [['09:00', '17:00']],
                        5 => [['09:00', '17:00']],
                    ],
                ],
            ],
        ]);

        $from = CarbonImmutable::now('Europe/Warsaw');
        $to = $from->addHours(24);
        $slots = $calc->slots('consultation', $from, $to);
        // 48h notice + only 24h ahead requested → zero slots
        $this->assertSame([], $slots);
    }

    private function busyService(array $intervals): GoogleCalendarService
    {
        return new class($intervals) extends GoogleCalendarService {
            public function __construct(private array $intervals)
            {
                parent::__construct([]);
            }
            public function freeBusy(array $calendarIds, CarbonImmutable $from, CarbonImmutable $to): array
            {
                return $this->intervals;
            }
        };
    }
}
