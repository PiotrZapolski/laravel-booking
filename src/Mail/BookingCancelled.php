<?php

namespace Zapol\Booking\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Zapol\Booking\Support\MailLocale;
use Zapol\Booking\Support\MailRenderer;

class BookingCancelled extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $payload, public array $eventType) {}

    public function build()
    {
        MailLocale::apply($this->payload, $this->eventType);

        $from = config('booking.mail.from');
        $fromName = config('booking.mail.from_name');
        $title = $this->eventType['title'] ?? __('booking::booking.title');

        $vars = [
            'payload'   => $this->payload,
            'eventType' => $this->eventType,
            'fieldRows' => MailLocale::renderFieldRows($this->payload, $this->eventType),
            'title'     => __('booking::booking.mail_cancelled_title', ['title' => $title]),
        ];
        $m = $this->subject(__('booking::booking.subject_cancelled', ['title' => $title]))
            ->html(MailRenderer::render('cancelled', $vars));

        if ($from) {
            $m->from($from, $fromName ?: null);
        }

        // Send a CANCEL ICS so attendees' calendar apps auto-remove the
        // event. UID must match the original REQUEST.
        if (!empty($this->payload['start']) && !empty($this->payload['end'])) {
            $organizerEmail = config('booking.organizer.email') ?: ($from ?: 'noreply@example.com');
            $ics = IcsBuilder::build($this->payload, $this->eventType, $organizerEmail, IcsBuilder::METHOD_CANCEL);
            $m->attachData($ics, 'cancel.ics', ['mime' => 'text/calendar; method=CANCEL']);
        }

        return $m;
    }
}
