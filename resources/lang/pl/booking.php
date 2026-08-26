<?php

return [
    // Widget UI
    'title'              => 'Zarezerwuj spotkanie',
    'reschedule_title'   => 'Zmień termin spotkania',
    'error_title'        => 'Coś poszło nie tak',
    'pick_date'          => 'Wybierz datę',
    'pick_time'          => 'Wybierz godzinę',
    'your_details'       => 'Twoje dane',
    'confirm'            => 'Potwierdź',
    'back_to_calendar'   => 'Powrót do kalendarza',
    'cancel'             => 'Anuluj spotkanie',
    'no_slots'           => 'Brak dostępnych terminów - wybierz inny dzień.',
    'success_title'      => 'Rezerwacja potwierdzona',
    'success_subtitle'   => 'Otrzymasz e-mail z potwierdzeniem.',
    'change_time'        => 'Zmień termin',
    'cancelled_title'    => 'Spotkanie anulowane',
    'prefilled_hint'     => 'z poprzedniego kroku',

    // Mail subjects
    'subject_confirmation_attendee'  => ':title - potwierdzenie',
    'subject_confirmation_organizer' => 'Nowa rezerwacja: :title',
    'subject_rescheduled'            => ':title - zmiana terminu',
    'subject_cancelled'              => ':title - anulowane',

    // Mail copy - attendee confirmation
    'mail_confirmation_title'   => ':title - potwierdzenie',
    'mail_confirmation_heading' => 'Twoje spotkanie jest potwierdzone',
    'mail_confirmation_intro'   => 'Dziękujemy za rezerwację. Poniżej szczegóły, do zobaczenia.',
    'mail_label_when'           => 'Termin',
    'mail_btn_join'             => 'Dołącz do spotkania',
    'mail_your_details'         => 'Twoje dane',
    'mail_attachment_hint'      => 'W załączniku znajdziesz zaproszenie do kalendarza - otwórz je, aby dodać spotkanie. Otrzymasz też zaproszenie z kalendarza organizatora.',
    'mail_need_change'          => 'Potrzebujesz zmienić termin?',
    'mail_reschedule_link'      => 'Przełóż lub anuluj',

    // Mail copy - organizer notification
    'mail_org_title'         => 'Nowa rezerwacja: :title',
    'mail_org_heading'       => 'Nowa rezerwacja',
    'mail_org_open_calendar' => 'Otwórz w kalendarzu',
    'mail_org_attendee'      => 'Klient',

    // Mail copy - rescheduled
    'mail_rescheduled_title'    => ':title - zmiana terminu',
    'mail_rescheduled_heading'  => 'Spotkanie zostało przeniesione',
    'mail_rescheduled_intro'    => 'Twoje spotkanie zostało przełożone na nowy termin.',
    'mail_label_new_time'       => 'Nowy termin',
    'mail_need_change_again'    => 'Potrzebujesz zmienić ponownie?',

    // Mail copy - cancelled
    'mail_cancelled_title'             => ':title - anulowane',
    'mail_cancelled_heading'           => 'Spotkanie anulowane',
    'mail_cancelled_intro'             => 'Spotkanie zostało anulowane. Mamy nadzieję, że zobaczymy się innym razem.',
    'mail_label_was'                   => 'Pierwotny termin',
    'mail_cancelled_attachment_hint'   => 'W załączniku znajdziesz anulację zaproszenia - otwarcie usunie wydarzenie z Twojego kalendarza.',
];
