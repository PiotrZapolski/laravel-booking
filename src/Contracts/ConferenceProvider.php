<?php

namespace Zapol\Booking\Contracts;

use Carbon\CarbonImmutable;

interface ConferenceProvider
{
    /** @param array{summary:string,start:CarbonImmutable,end:CarbonImmutable,timezone:string,attendee:array{email:string,name:string}} $draft
     *  @return array{id:string,join_url:string,password:?string} */
    public function create(array $draft): array;
    public function reschedule(string $meetingId, CarbonImmutable $start, CarbonImmutable $end, string $timezone): void;
    public function delete(string $meetingId): void;
}
