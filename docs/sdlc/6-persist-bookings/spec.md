# Spec: persist_bookings ma działać tak, jak opisuje README (#6)

Intent: [intent.md](intent.md)

## Zachowanie

Nowy listener `Zapol\Booking\Listeners\PersistBooking`, rejestrowany w `BookingServiceProvider::boot()` na zdarzenia `BookingCreated`, `BookingRescheduled`, `BookingCancelled` tylko gdy `booking.persist_bookings` jest prawdą (ten sam wzór co `NotifySlack`).

- Created: insert do `booking_bookings` (event_type, attendee_email, attendee_name, google_event_id = id zdarzenia w kalendarzu dowolnego sterownika, starts_at, ends_at, fields JSON, meet_link, status `confirmed`).
- Rescheduled: update `starts_at`, `ends_at` po `google_event_id`; brak wiersza (rezerwacja sprzed włączenia opcji) = insert z danych zdarzenia.
- Cancelled: `status = cancelled`, wiersz zostaje; brak wiersza = nic.
- Daty zapisywane w `app.timezone` jako `Y-m-d H:i:s` (konwencja Eloquent).
- Opcja wyłączona: listener nie jest rejestrowany, paczka nie otwiera połączenia z bazą.

Warianty odrzucone: zapis wprost w `BookingController` (większa zmiana w pliku z logiką rezerwacji, a zdarzenia niosą już komplet danych); zapis zawsze, gdy tabela istnieje (zapytanie `hasTable` przy każdej rezerwacji łamie obietnicę "zero zapytań" i robi martwą opcję z configu); model Eloquent (niepotrzebny do logu, a `illuminate/database` nie jest twardą zależnością paczki).

## Przypadki brzegowe

- Opcja włączona, tabela niezmigrowana: `Log::warning` z komendą naprawczą, rezerwacja przechodzi.
- Brak połączenia albo błąd zapytania: `report()` i dalej (konwencja paczki: Slack, mailer, konferencja).
- Zdarzenie jest wysyłane po utworzeniu wpisu w kalendarzu i po mailach, więc błąd zapisu nie ma czego zablokować.
- Opcja czytana przy starcie aplikacji (jak webhook Slacka); dodatkowo sprawdzana w `handle()`.
- Dwa wiersze o tym samym `google_event_id` nie powstają w normalnym przepływie; kolumna nie ma unikalnego indeksu i schematu nie zmieniamy.

## Polityki

- Security i dostęp: bez zmian w endpointach; tabela jest tylko zapisywana.
- Dane: tabela trzyma e-mail, imię i pola formularza uczestnika w bazie aplikacji hosta, wyłącznie po świadomym włączeniu opcji; migracja bez zmian.
- Pieniądze i ceny: nie dotyczy.
- Klienci: nie dotyczy (żadnych nowych wiadomości ani powiadomień).
