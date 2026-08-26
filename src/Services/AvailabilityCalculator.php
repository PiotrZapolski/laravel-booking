<?php

namespace Zapol\Booking\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use InvalidArgumentException;
use Zapol\Booking\Contracts\CalendarProvider;

/**
 * Computes available booking slots = (configured weekly windows) minus
 * (busy intervals reported by the active calendar driver), with notice,
 * advance, buffer, and step constraints applied.
 *
 * Which calendars count as busy is the driver's business, not ours.
 */
class AvailabilityCalculator
{
    public function __construct(
        private CalendarProvider $calendar,
        private array $config,
    ) {}

    /**
     * @return array<int,array{start:string,end:string}> ISO 8601 with offset, in organiser TZ.
     */
    public function slots(string $eventTypeSlug, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $eventType = $this->config['event_types'][$eventTypeSlug] ?? null;
        if (!$eventType) {
            throw new InvalidArgumentException("Unknown event type: {$eventTypeSlug}");
        }

        $tz = $this->config['organizer']['timezone'] ?? 'UTC';
        $duration = (int)($eventType['duration_minutes'] ?? 30);
        $step = (int)($eventType['slot_step_minutes'] ?? $duration);
        $buffer = (int)($eventType['buffer_minutes'] ?? 0);
        $minNoticeHours = (int)($eventType['min_notice_hours'] ?? 0);
        $maxAdvanceDays = (int)($eventType['max_advance_days'] ?? 90);

        $from = $from->setTimezone($tz);
        $to = $to->setTimezone($tz);

        $earliest = CarbonImmutable::now($tz)->addHours($minNoticeHours);
        $latest = CarbonImmutable::now($tz)->addDays($maxAdvanceDays);

        $rangeFrom = $from->lessThan($earliest) ? $earliest : $from;
        $rangeTo = $to->greaterThan($latest) ? $latest : $to;

        if ($rangeFrom->greaterThanOrEqualTo($rangeTo)) {
            return [];
        }

        $candidates = $this->candidateSlots($eventType, $rangeFrom, $rangeTo, $duration, $step, $tz);
        if (empty($candidates)) {
            return [];
        }

        $busy = $this->calendar->freeBusy(
            $rangeFrom->subMinutes($buffer),
            $rangeTo->addMinutes($buffer + $duration),
        );

        $free = [];
        foreach ($candidates as $candidate) {
            $candStart = $candidate->subMinutes($buffer);
            $candEnd = $candidate->addMinutes($duration + $buffer);

            if ($this->overlapsAny($candStart, $candEnd, $busy)) {
                continue;
            }

            $free[] = [
                'start' => $candidate->toIso8601String(),
                'end'   => $candidate->addMinutes($duration)->toIso8601String(),
            ];
        }

        return $free;
    }

    /**
     * @return array<int,CarbonImmutable>
     */
    private function candidateSlots(
        array $eventType,
        CarbonImmutable $from,
        CarbonImmutable $to,
        int $duration,
        int $step,
        string $tz,
    ): array {
        $availability = $eventType['availability'] ?? [];
        $slots = [];

        $day = $from->startOfDay();
        $end = $to->endOfDay();

        while ($day->lessThanOrEqualTo($end)) {
            $weekday = (int) $day->isoWeekday(); // 1..7
            $windows = $availability[$weekday] ?? [];

            foreach ($windows as $window) {
                if (!is_array($window) || count($window) !== 2) {
                    continue;
                }
                [$startHM, $endHM] = $window;
                $winStart = $this->applyTime($day, $startHM, $tz);
                $winEnd = $this->applyTime($day, $endHM, $tz);
                if (!$winStart || !$winEnd || $winEnd->lessThanOrEqualTo($winStart)) {
                    continue;
                }

                $cursor = $winStart;
                $stepInterval = CarbonInterval::minutes($step);
                while ($cursor->copy()->addMinutes($duration)->lessThanOrEqualTo($winEnd)) {
                    if ($cursor->greaterThanOrEqualTo($from) && $cursor->lessThanOrEqualTo($to)) {
                        $slots[] = $cursor;
                    }
                    $cursor = $cursor->add($stepInterval);
                }
            }

            $day = $day->addDay();
        }

        return $slots;
    }

    private function applyTime(CarbonImmutable $day, string $hm, string $tz): ?CarbonImmutable
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hm, $m)) {
            return null;
        }
        return $day->setTimezone($tz)->setTime((int)$m[1], (int)$m[2], 0);
    }

    /**
     * @param array<int,array{start:CarbonImmutable,end:CarbonImmutable}> $intervals
     */
    private function overlapsAny(CarbonImmutable $start, CarbonImmutable $end, array $intervals): bool
    {
        foreach ($intervals as $busy) {
            if ($start->lessThan($busy['end']) && $end->greaterThan($busy['start'])) {
                return true;
            }
        }
        return false;
    }
}
