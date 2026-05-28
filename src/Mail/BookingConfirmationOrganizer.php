<?php

namespace Zapol\Booking\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Zapol\Booking\Support\MailLocale;

class BookingConfirmationOrganizer extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $payload, public array $eventType) {}

    public function build()
    {
        MailLocale::apply($this->payload, $this->eventType);

        $from = config('booking.mail.from');
        $fromName = config('booking.mail.from_name');
        $title = $this->eventType['title'] ?? __('booking::booking.title');

        $m = $this->subject(__('booking::booking.subject_confirmation_organizer', ['title' => $title]))
            ->view('booking::emails.confirmation-organizer')
            ->with([
                'payload'   => $this->payload,
                'eventType' => $this->eventType,
                'fieldRows' => MailLocale::renderFieldRows($this->payload, $this->eventType),
            ]);

        if ($from) {
            $m->from($from, $fromName ?: null);
        }

        // Attach the same REQUEST ICS so the organiser can add it to their
        // personal calendar in clients that don't share the Google account.
        $organizerEmail = config('booking.organizer.email') ?: ($from ?: 'noreply@example.com');
        $ics = IcsBuilder::build($this->payload, $this->eventType, $organizerEmail, IcsBuilder::METHOD_REQUEST);
        $m->attachData($ics, 'invite.ics', ['mime' => 'text/calendar; method=REQUEST']);

        return $m;
    }
}
