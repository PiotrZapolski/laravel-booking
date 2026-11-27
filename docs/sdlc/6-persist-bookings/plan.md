# Plan: persist_bookings ma działać tak, jak opisuje README (#6)

Spec: [spec.md](spec.md)

## Pliki

| Plik | Zmiana |
|---|---|
| `src/Listeners/PersistBooking.php` | nowy listener: insert / update / cancel, błędy połykane |
| `src/BookingServiceProvider.php` | rejestracja listenera, gdy opcja włączona |
| `config/booking.php` | komentarz opisujący faktyczne zachowanie opcji |
| `composer.json` | `suggest: illuminate/database` |
| `phpunit.xml`, `tests/Feature/*` | testy przepływu HTTP na testbench z kalendarzem w pamięci |
| `README.md`, `CHANGELOG.md` | sekcja "Optional bookings table", FAQ, wpis 1.0.1 |

## Kroki

- [x] 1. Testy feature: opcja włączona (wiersz powstaje i się aktualizuje, brak tabeli, awaria bazy) i wyłączona (brak połączenia z bazą).
- [x] 2. Listener i rejestracja w providerze.
- [x] 3. README, config, CHANGELOG, review diffu, PR.

## Testy

`composer validate`, `heavy vendor/bin/phpunit` (zestawy unit i feature). Sprawdzenie odwrotne: z wyłączoną rejestracją listenera testy opcji włączonej padają.

## Ryzyka

- Host bez `illuminate/database` z włączoną opcją: wyjątek jest połykany i raportowany, rezerwacja działa.
- Wycofanie: revert PR; opcja domyślnie wyłączona, więc istniejące instalacje nie zmieniają zachowania.

## Odstępstwa

brak
