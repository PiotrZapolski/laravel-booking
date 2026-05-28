<?php

namespace Zapol\Booking\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Zapol\Booking\Support\MailLocale;

class BookingRescheduled extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $payload, public array $eventType) {}

    public function build()
    {
        MailLocale::apply($this->payload, $this->eventType);

        $from = config('booking.mail.from');
        $fromName = config('booking.mail.from_name');
        $title = $this->eventType['title'] ?? __('booking::booking.title');

        $m = $this->subject(__('booking::booking.subject_rescheduled', ['title' => $title]))
            ->view('booking::emails.rescheduled')
            ->with([
                'payload'   => $this->payload,
                'eventType' => $this->eventType,
                'fieldRows' => MailLocale::renderFieldRows($this->payload, $this->eventType),
            ]);

        if ($from) {
            $m->from($from, $fromName ?: null);
        }

        // Reschedule = same UID, METHOD:REQUEST, same SEQUENCE+1 — Apple/
        // Google/Outlook treat this as an in-place update of the original
        // event in the attendee's calendar.
        $organizerEmail = config('booking.organizer.email') ?: ($from ?: 'noreply@example.com');
        $ics = IcsBuilder::build($this->payload, $this->eventType, $organizerEmail, IcsBuilder::METHOD_REQUEST);
        $m->attachData($ics, 'invite.ics', ['mime' => 'text/calendar; method=REQUEST']);

        return $m;
    }
}
