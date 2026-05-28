<?php

namespace Zapol\Booking\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Zapol\Booking\Support\MailLocale;

class BookingConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $payload, public array $eventType) {}

    public function build()
    {
        MailLocale::apply($this->payload, $this->eventType);

        $from = config('booking.mail.from');
        $fromName = config('booking.mail.from_name');
        $title = $this->eventType['title'] ?? __('booking::booking.title');

        $m = $this->subject(__('booking::booking.subject_confirmation_attendee', ['title' => $title]))
            ->view('booking::emails.confirmation')
            ->with([
                'payload'    => $this->payload,
                'eventType'  => $this->eventType,
                'fieldRows'  => MailLocale::renderFieldRows($this->payload, $this->eventType),
                'attendee'   => $this->payload['fields']['email'] ?? null,
            ]);

        if ($from) {
            $m->from($from, $fromName ?: null);
        }
        if ($replyTo = config('booking.mail.reply_to')) {
            $m->replyTo($replyTo);
        }

        $organizerEmail = config('booking.organizer.email') ?: ($from ?: 'noreply@example.com');
        $ics = IcsBuilder::build($this->payload, $this->eventType, $organizerEmail, IcsBuilder::METHOD_REQUEST);
        $m->attachData($ics, 'invite.ics', ['mime' => 'text/calendar; method=REQUEST']);

        return $m;
    }
}
