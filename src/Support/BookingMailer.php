<?php

namespace Zapol\Booking\Support;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Throwable;

class BookingMailer
{
    public function send(Mailable $mailable, ?string $to): void
    {
        if (!$to) {
            return;
        }
        try {
            Mail::to($to)->send($mailable);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
