# Intent: persist_bookings ma działać tak, jak opisuje README (#6)

## Problem

README (sekcja "Optional bookings table") i `config/booking.php` obiecują, że `persist_bookings = true` włącza zapis rezerwacji do tabeli z opcjonalnej migracji. Stan faktyczny w v1.0.0: klucz istnieje w configu, migracja `booking_bookings` istnieje i jest publikowana, ale nic w `src/` nie czyta opcji ani nie pisze do tabeli (brak modelu, brak zapytań). Kto włączy opcję, dostaje pustą tabelę.

## Dla kogo

Programista instalujący paczkę, który chce mieć własny log rezerwacji (audyt, analityka). Paczka jest wydana jako v1.0.0 i będzie promowana, więc dokumentacja musi mówić prawdę.

## Sukces

- Opcja włączona, tabela zmigrowana: wiersz powstaje przy rezerwacji, zmiana terminu i anulowanie go aktualizują.
- Opcja wyłączona (domyślnie): zero zapytań do bazy, paczka działa bez tabeli.
- Błąd bazy nie blokuje rezerwacji w kalendarzu ani maili.
- README, config i CHANGELOG (1.0.1) opisują dokładnie to zachowanie; testy pilnują obu przypadków.

## Czego nie robimy

Bez zmian w publicznym API HTTP, formacie tokenów HMAC, dostawcach kalendarza i widgecie JS. Bez modelu Eloquent, bez odczytu z tabeli (kalendarz zostaje źródłem prawdy), bez zmiany schematu migracji. Bez wydania i tagu (zrobi to sesja lokalna po merge).
