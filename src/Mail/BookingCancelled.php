<?php

namespace Zapol\Booking\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class BookingCancelled extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $payload, public array $eventType) {}

    public function build()
    {
        $from = config('booking.mail.from');
        $fromName = config('booking.mail.from_name');

        $m = $this->subject(($this->eventType['title'] ?? 'Booking') . ' — cancelled')
            ->view('booking::emails.cancelled')
            ->with(['payload' => $this->payload, 'eventType' => $this->eventType]);

        if ($from) {
            $m->from($from, $fromName ?: null);
        }

        return $m;
    }
}
