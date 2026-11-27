# Changelog

All notable changes to this package are listed here. Versions follow [Semantic Versioning](https://semver.org/).

## 1.0.1

### Fixed

- `persist_bookings` now does what the README says. In 1.0.0 the option was never read, so switching it on stored nothing. With the option on and the `booking_bookings` table migrated, a booking is written on create, its times are updated on reschedule, and its status becomes `cancelled` on cancel (#6).
- A failed write to the bookings table (no connection, table not migrated) is reported and never blocks the booking in the calendar or its emails.

### Unchanged

- `persist_bookings` stays `false` by default, and with it off the package runs no database queries. HTTP API, tokens, calendar drivers and the widget are untouched.

## 1.0.0

- First tagged release.
