<?php

namespace Zapol\Booking\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class BookingConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $payload, public array $eventType) {}

    public function build()
    {
        $from = config('booking.mail.from');
        $fromName = config('booking.mail.from_name');

        $m = $this->subject(($this->eventType['title'] ?? 'Booking') . ' — confirmation')
            ->view('booking::emails.confirmation')
            ->with(['payload' => $this->payload, 'eventType' => $this->eventType]);

        if ($from) {
            $m->from($from, $fromName ?: null);
        }
        if ($replyTo = config('booking.mail.reply_to')) {
            $m->replyTo($replyTo);
        }

        $ics = IcsBuilder::build($this->payload, $this->eventType, $from ?: 'noreply@example.com');
        $m->attachData($ics, 'invite.ics', ['mime' => 'text/calendar; method=REQUEST']);

        return $m;
    }
}
