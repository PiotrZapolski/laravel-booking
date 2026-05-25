# Deploy guide — Zencal → booking widget

These steps swap the live Zencal embed for the new `zapol/laravel-booking`
widget on **AgentsHub** and **TenfoldDevs**.

The Composer package itself is already on GitHub at
<https://github.com/PiotrZapolski/laravel-booking>, declared as a VCS
repository in both projects' `composer.json`. Production just needs to
`composer install` after pulling the latest project code.

---

## On your machine

```bash
# AgentsHub — branch e2e-tests already pushed
cd ~/Projects/agentshub
git checkout main                  # or whatever branch deploy follows
git merge e2e-tests
git push origin main

# TenfoldDevs — not a git repo locally; rsync or however you normally
# deploy. The files changed are:
#   composer.json
#   config/booking.php
#   resources/views/components/⚡consultation-form.blade.php
#   tenfolddevs_google_oauth.json
#   .env.example
#   .gitignore
```

---

## On the production server (65.108.140.190)

Connect:

```bash
ssh root@65.108.140.190
```

### AgentsHub

```bash
cd /path/to/agentshub                # whatever path you used; common: /var/www/agentshub
git pull origin main

# install dependency (zapol/laravel-booking is pulled from GitHub VCS repo)
composer install --no-dev --optimize-autoloader

# put the OAuth credentials JSON in the project root
#   (scp/sftp the local agentshub_google_oauth.json file here)
#   from your laptop:
#     scp ~/Projects/agentshub/agentshub_google_oauth.json \
#         root@65.108.140.190:/path/to/agentshub/agentshub_google_oauth.json
chmod 600 agentshub_google_oauth.json

# set production env vars (append to .env)
cat >> .env <<'ENV'
BOOKING_GOOGLE_CREDENTIALS_FILE=/path/to/agentshub/agentshub_google_oauth.json
BOOKING_GOOGLE_CALENDAR_ID=primary
BOOKING_ORGANIZER_NAME="AgentsHub"
BOOKING_ORGANIZER_EMAIL=piotr@agentshub.pl
BOOKING_ORGANIZER_TZ=Europe/Warsaw
BOOKING_MAIL_FROM=noreply@agentshub.pl
BOOKING_MAIL_FROM_NAME="AgentsHub"
ENV

php artisan config:clear
php artisan cache:clear
```

### TenfoldDevs

```bash
cd /path/to/tenfolddevs              # whatever the path is
# pull or rsync your local changes here

composer install --no-dev --optimize-autoloader

# copy the OAuth credentials JSON
chmod 600 tenfolddevs_google_oauth.json

cat >> .env <<'ENV'
BOOKING_GOOGLE_CREDENTIALS_FILE=/path/to/tenfolddevs/tenfolddevs_google_oauth.json
BOOKING_GOOGLE_CALENDAR_ID=primary
BOOKING_ORGANIZER_NAME="TenfoldDevs"
BOOKING_ORGANIZER_EMAIL=kontakt@tenfolddevs.com
BOOKING_ORGANIZER_TZ=Europe/Warsaw
BOOKING_MAIL_FROM=noreply@tenfolddevs.com
BOOKING_MAIL_FROM_NAME="TenfoldDevs"
ENV

php artisan config:clear
php artisan cache:clear
```

---

## Register the Google callback URLs

In <https://console.cloud.google.com/apis/credentials>, for **each** of
your two OAuth Client IDs:

1. Click the client name → **Authorised redirect URIs** → **Add URI**
2. Paste the project's production callback:

   - AgentsHub: `https://agentshub.pl/booking/google/callback`
   - TenfoldDevs: `https://tenfolddevs.com/booking/google/callback`

(Adjust to your real domains.) Save.

Also enable the [Google Calendar API](https://console.cloud.google.com/apis/library/calendar-json.googleapis.com)
on each project if it isn't already.

---

## Connect your Google account

Run the artisan command **on the production server**, in each project:

```bash
# AgentsHub
cd /path/to/agentshub
php artisan booking:google-auth
```

It prints two things:

- A **signed URL** to `https://agentshub.pl/booking/google/connect?...` valid 60 min
- The exact callback URL to register in Google Cloud Console (already done above)

Open the signed URL in your browser. You'll see a small page with a
**Connect Google Account** button. Click it → Google consent → you're
redirected back to `/booking/google/callback`, which displays:

```
BOOKING_GOOGLE_REFRESH_TOKEN=1//0g...
```

with a Copy button. Paste that line into the server's `.env`, then:

```bash
php artisan config:clear
```

Repeat the same for TenfoldDevs (using **your TenfoldDevs Gmail** when
Google asks which account to use).

---

## Smoke test

```bash
curl -s https://agentshub.pl/booking/widget.js | head -2
# should start with: /**\n * Booking Widget — vanilla JS, no build step.

curl -s 'https://agentshub.pl/booking/api/event-types/consultation' | jq .
# should return the consultation event type metadata

curl -s 'https://agentshub.pl/booking/api/event-types/consultation/slots?from=2026-06-01&to=2026-06-10' | jq .
# should return real slots (or {} if no availability in that window)
```

Then load <https://agentshub.pl/konsultacja>, submit the qualification
form with `employee_count >= 5`, and verify the new widget appears
pre-filled with email + company + employee_count.

If `slots` 502s or `widget.js` 500s, check `storage/logs/laravel.log`.
