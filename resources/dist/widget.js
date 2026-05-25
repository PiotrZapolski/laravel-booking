/**
 * Booking Widget — vanilla JS, no build step.
 *
 * Reads its own <script> tag's data-* attributes:
 *   data-event-type        : event type slug (required)
 *   data-name              : pre-fill attendee name
 *   data-email             : pre-fill attendee email
 *   data-fields-<field>    : pre-fill any form field (e.g. data-fields-company)
 *   data-primary           : primary colour
 *   data-lang              : "pl" | "en" | …
 *   data-reschedule-token  : reschedule mode
 *   data-mount             : CSS selector to mount in (default: #booking-widget)
 *   data-api               : override API base (default: derived from script src)
 */
(function () {
    'use strict';

    var script = document.currentScript;
    var ds = script ? script.dataset : {};
    var apiBase = ds.api || deriveApiBase(script ? script.src : '');
    var mountSel = ds.mount || '#booking-widget';
    var slug = ds.eventType;
    var lang = (ds.lang || 'en').toLowerCase();
    var primary = ds.primary || '#47b2e4';
    var rescheduleToken = ds.rescheduleToken || null;
    var prefill = collectPrefill(ds);

    if (!slug) {
        console.error('[booking-widget] data-event-type is required on the <script> tag');
        return;
    }

    onReady(function () {
        var mount = document.querySelector(mountSel);
        if (!mount) {
            mount = document.createElement('div');
            mount.id = mountSel.replace('#', '');
            (script && script.parentNode || document.body).insertBefore(mount, script ? script.nextSibling : null);
        }
        injectStyles(primary);
        var app = new Widget(mount, {
            api: apiBase,
            slug: slug,
            lang: lang,
            prefill: prefill,
            rescheduleToken: rescheduleToken,
        });
        app.boot();
    });

    function deriveApiBase(src) {
        try {
            var u = new URL(src, window.location.href);
            // src ends with "/booking/widget.js" → API is at "/booking/api"
            var idx = u.pathname.lastIndexOf('/widget.js');
            if (idx >= 0) {
                return u.origin + u.pathname.substring(0, idx) + '/api';
            }
        } catch (e) {}
        return '/booking/api';
    }

    function collectPrefill(ds) {
        var out = {};
        if (ds.name) out.name = ds.name;
        if (ds.email) out.email = ds.email;
        Object.keys(ds).forEach(function (k) {
            if (k.indexOf('fields') === 0 && k.length > 'fields'.length) {
                // dataset converts data-fields-employee_count → fieldsEmployee_count
                // and data-fields-company → fieldsCompany
                var field = k.substring('fields'.length);
                field = field.charAt(0).toLowerCase() + field.substring(1);
                out[field] = ds[k];
            }
        });
        return out;
    }

    function onReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function injectStyles(primary) {
        if (document.getElementById('booking-widget-styles')) return;
        var s = document.createElement('style');
        s.id = 'booking-widget-styles';
        s.textContent = (''
            + '.bw-root{--bw-primary:' + primary + ';font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#1f303a;line-height:1.45;max-width:960px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;display:grid;grid-template-columns:280px 1fr;}'
            + '@media(max-width:720px){.bw-root{grid-template-columns:1fr;}}'
            + '.bw-side{background:var(--bw-primary);color:#fff;padding:24px;}'
            + '.bw-side h2{font-size:18px;margin:0 0 8px;font-weight:700;line-height:1.25;}'
            + '.bw-side p{font-size:13px;opacity:.92;margin:0 0 16px;}'
            + '.bw-meta{font-size:13px;opacity:.95;display:flex;flex-direction:column;gap:6px;}'
            + '.bw-main{padding:24px 28px;}'
            + '.bw-tab-head{display:flex;gap:24px;border-bottom:1px solid #e5e7eb;margin-bottom:18px;}'
            + '.bw-tab{padding:0 0 10px;font-size:14px;color:#6b7280;border-bottom:2px solid transparent;}'
            + '.bw-tab.active{color:var(--bw-primary);border-bottom-color:var(--bw-primary);}'
            + '.bw-h3{font-size:20px;margin:0 0 4px;font-weight:700;}'
            + '.bw-sub{font-size:13px;color:#6b7280;margin:0 0 16px;}'
            + '.bw-cal-head{display:flex;align-items:center;justify-content:space-between;margin:8px 0 12px;}'
            + '.bw-cal-title{font-weight:600;font-size:15px;}'
            + '.bw-nav{display:flex;gap:8px;}'
            + '.bw-icon-btn{width:32px;height:32px;border-radius:50%;border:1px solid #e5e7eb;background:#fff;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;}'
            + '.bw-icon-btn[disabled]{opacity:.4;cursor:not-allowed;}'
            + '.bw-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;}'
            + '.bw-dow{font-size:11px;text-align:center;color:#6b7280;text-transform:uppercase;padding:6px 0;}'
            + '.bw-day{aspect-ratio:1/1;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;color:#9ca3af;cursor:default;}'
            + '.bw-day.bw-bookable{color:#1f303a;background:#fff;border:1px solid #e5e7eb;cursor:pointer;}'
            + '.bw-day.bw-bookable:hover{border-color:var(--bw-primary);}'
            + '.bw-day.bw-selected{background:var(--bw-primary);color:#fff;border-color:var(--bw-primary);}'
            + '.bw-slots{display:flex;flex-direction:column;gap:8px;margin-top:14px;}'
            + '.bw-slot{padding:12px 14px;border:1px solid #e5e7eb;border-radius:8px;text-align:center;cursor:pointer;font-weight:500;background:#fff;}'
            + '.bw-slot:hover{border-color:var(--bw-primary);color:var(--bw-primary);}'
            + '.bw-form{display:flex;flex-direction:column;gap:14px;margin-top:8px;}'
            + '.bw-field label{display:block;font-size:13px;font-weight:600;margin:0 0 4px;}'
            + '.bw-field input,.bw-field textarea{width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font:inherit;color:#1f303a;background:#fff;box-sizing:border-box;}'
            + '.bw-field input:read-only{background:#f3f4f6;color:#4b5563;}'
            + '.bw-field .bw-hint{font-size:11px;color:#9ca3af;margin:4px 0 0;}'
            + '.bw-actions{display:flex;justify-content:space-between;align-items:center;margin-top:6px;}'
            + '.bw-btn{padding:10px 18px;background:var(--bw-primary);color:#fff;border:0;border-radius:8px;font-weight:600;cursor:pointer;font-size:14px;}'
            + '.bw-btn[disabled]{opacity:.5;cursor:not-allowed;}'
            + '.bw-link{background:none;border:0;color:var(--bw-primary);cursor:pointer;font:inherit;padding:0;}'
            + '.bw-error{background:#fef2f2;color:#991b1b;padding:10px 12px;border-radius:8px;margin-bottom:12px;font-size:13px;}'
            + '.bw-success{padding:24px 0;}'
            + '.bw-success h3{margin:0 0 8px;}'
            + '.bw-success .bw-meta-row{padding:8px 0;border-bottom:1px solid #e5e7eb;font-size:14px;}'
        );
        document.head.appendChild(s);
    }

    function Widget(root, opts) {
        this.root = root;
        this.opts = opts;
        this.eventType = null;
        this.state = {
            view: 'calendar', // 'calendar' | 'slots' | 'form' | 'success' | 'cancelled'
            month: new Date(),
            selectedDate: null,
            selectedSlot: null,
            slots: [],
            slotsByDay: {},
            loading: false,
            error: null,
            booking: null,
            formValues: {},
        };
    }

    Widget.prototype.boot = function () {
        var self = this;
        this.root.classList.add('bw-root');
        this.render();
        this.fetchEventType().then(function () {
            return self.loadMonth(self.state.month);
        }).catch(function (e) {
            self.state.error = (e && e.message) || 'Failed to load.';
            self.render();
        });
    };

    Widget.prototype.fetchEventType = function () {
        var self = this;
        return fetch(this.opts.api + '/event-types/' + encodeURIComponent(this.opts.slug), {
            credentials: 'same-origin',
        }).then(function (r) {
            if (!r.ok) throw new Error('Event type not found.');
            return r.json();
        }).then(function (data) {
            self.eventType = data;
            // Initialise form values from prefill
            (data.form_fields || []).forEach(function (f) {
                if (self.opts.prefill[f.name] !== undefined && self.opts.prefill[f.name] !== null) {
                    self.state.formValues[f.name] = self.opts.prefill[f.name];
                }
            });
            self.render();
        });
    };

    Widget.prototype.loadMonth = function (date) {
        var self = this;
        var first = new Date(date.getFullYear(), date.getMonth(), 1);
        var last = new Date(date.getFullYear(), date.getMonth() + 1, 0, 23, 59, 59);
        var from = iso(first);
        var to = iso(last);
        self.state.loading = true;
        self.render();
        return fetch(this.opts.api + '/event-types/' + encodeURIComponent(this.opts.slug) + '/slots?from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to), {
            credentials: 'same-origin',
        }).then(function (r) {
            if (!r.ok) throw new Error('Failed to load slots.');
            return r.json();
        }).then(function (data) {
            self.state.slots = data.slots || [];
            self.state.slotsByDay = {};
            self.state.slots.forEach(function (s) {
                var d = s.start.substring(0, 10);
                (self.state.slotsByDay[d] = self.state.slotsByDay[d] || []).push(s);
            });
            self.state.loading = false;
            self.render();
        }).catch(function (e) {
            self.state.loading = false;
            self.state.error = e.message;
            self.render();
        });
    };

    Widget.prototype.render = function () {
        var html = '<div class="bw-side">'
            + (this.eventType ? this.renderSide() : '<div style="opacity:.7;">Loading…</div>')
            + '</div>'
            + '<div class="bw-main">'
            + (this.state.error ? '<div class="bw-error">' + esc(this.state.error) + '</div>' : '')
            + this.renderMain()
            + '</div>';
        this.root.innerHTML = html;
        this.bind();
    };

    Widget.prototype.renderSide = function () {
        var et = this.eventType;
        return ''
            + '<h2>' + esc(et.title) + '</h2>'
            + (et.description ? '<p>' + esc(et.description) + '</p>' : '')
            + '<div class="bw-meta">'
            + '<div>⏱ ' + et.duration + ' min</div>'
            + (et.location === 'google_meet' ? '<div>📹 Google Meet</div>' : '')
            + (et.timezone ? '<div>🌐 ' + esc(et.timezone) + '</div>' : '')
            + (et.organizer && et.organizer.name ? '<div>👤 ' + esc(et.organizer.name) + '</div>' : '')
            + '</div>';
    };

    Widget.prototype.renderMain = function () {
        switch (this.state.view) {
            case 'success':   return this.renderSuccess();
            case 'cancelled': return this.renderCancelled();
            case 'form':      return this.renderForm();
            case 'slots':     return this.renderSlots();
            default:          return this.renderCalendar();
        }
    };

    Widget.prototype.renderCalendar = function () {
        var t = this;
        var month = t.state.month;
        var title = month.toLocaleString(t.opts.lang === 'pl' ? 'pl-PL' : 'en-US', { month: 'long', year: 'numeric' });
        var first = new Date(month.getFullYear(), month.getMonth(), 1);
        var startWeekday = (first.getDay() + 6) % 7; // Mon=0
        var daysInMonth = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate();
        var today = new Date(); today.setHours(0,0,0,0);

        var dows = t.opts.lang === 'pl'
            ? ['Pon','Wto','Śro','Czw','Pią','Sob','Nie']
            : ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

        var grid = '';
        dows.forEach(function (d) { grid += '<div class="bw-dow">' + d + '</div>'; });
        for (var i = 0; i < startWeekday; i++) grid += '<div class="bw-day"></div>';
        for (var day = 1; day <= daysInMonth; day++) {
            var dt = new Date(month.getFullYear(), month.getMonth(), day);
            var key = ymd(dt);
            var hasSlots = !!(t.state.slotsByDay[key] && t.state.slotsByDay[key].length);
            var cls = 'bw-day' + (hasSlots ? ' bw-bookable' : '');
            grid += '<div class="' + cls + '"' + (hasSlots ? ' data-date="' + key + '"' : '') + '>' + day + '</div>';
        }

        var canPrev = !(month.getFullYear() === today.getFullYear() && month.getMonth() <= today.getMonth());

        return ''
            + '<div class="bw-tab-head"><div class="bw-tab active">1. ' + (t.opts.lang === 'pl' ? 'Termin' : 'Time') + '</div></div>'
            + '<h3 class="bw-h3">' + (t.opts.lang === 'pl' ? 'Wybierz datę' : 'Pick a date') + '</h3>'
            + '<div class="bw-cal-head">'
            + '<div class="bw-cal-title">' + esc(title) + '</div>'
            + '<div class="bw-nav">'
            + '<button class="bw-icon-btn" data-action="prev"' + (canPrev ? '' : ' disabled') + '>‹</button>'
            + '<button class="bw-icon-btn" data-action="next">›</button>'
            + '</div></div>'
            + '<div class="bw-grid">' + grid + '</div>'
            + (t.state.loading ? '<p style="text-align:center;color:#6b7280;margin-top:14px;">…</p>' : '');
    };

    Widget.prototype.renderSlots = function () {
        var t = this;
        var slots = t.state.slotsByDay[t.state.selectedDate] || [];
        var dt = new Date(t.state.selectedDate + 'T00:00:00');
        var heading = dt.toLocaleDateString(t.opts.lang === 'pl' ? 'pl-PL' : 'en-US', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

        var rows = slots.length
            ? slots.map(function (s) {
                var hm = s.start.substring(11, 16);
                return '<button class="bw-slot" data-slot="' + esc(s.start) + '">' + hm + '</button>';
            }).join('')
            : '<p style="color:#6b7280;">' + (t.opts.lang === 'pl' ? 'Brak dostępnych godzin.' : 'No times available.') + '</p>';

        return ''
            + '<div class="bw-tab-head"><div class="bw-tab active">1. ' + (t.opts.lang === 'pl' ? 'Termin' : 'Time') + '</div></div>'
            + '<button class="bw-link" data-action="back-to-calendar">‹ ' + (t.opts.lang === 'pl' ? 'Powrót do kalendarza' : 'Back to calendar') + '</button>'
            + '<h3 class="bw-h3" style="margin-top:14px;">' + esc(heading) + '</h3>'
            + '<div class="bw-slots">' + rows + '</div>';
    };

    Widget.prototype.renderForm = function () {
        var t = this;
        var fields = (t.eventType && t.eventType.form_fields) || [];
        var slot = t.state.selectedSlot;
        var hm = slot ? slot.substring(11, 16) : '';
        var date = slot ? slot.substring(0, 10) : '';

        var rows = fields.map(function (f) {
            var val = t.state.formValues[f.name] || '';
            var locked = t.opts.prefill[f.name] !== undefined && t.opts.prefill[f.name] !== null && t.opts.prefill[f.name] !== '';
            var hint = locked ? '<div class="bw-hint">(' + (t.opts.lang === 'pl' ? 'z poprzedniego kroku' : 'from previous step') + ')</div>' : '';
            var label = esc(f.label || f.name) + (f.required ? ' *' : '');
            var attrs = 'name="' + esc(f.name) + '"' + (f.required ? ' required' : '') + (locked ? ' readonly' : '');
            var input = f.type === 'textarea'
                ? '<textarea ' + attrs + ' rows="3">' + esc(val) + '</textarea>'
                : '<input type="' + esc(f.type || 'text') + '" value="' + esc(val) + '" ' + attrs + ' />';
            return '<div class="bw-field"><label>' + label + '</label>' + input + hint + '</div>';
        }).join('');

        // Honeypot field
        rows += '<div style="position:absolute;left:-9999px;" aria-hidden="true"><label>Company<input type="text" name="hp_company" tabindex="-1" autocomplete="off" /></label></div>';

        return ''
            + '<div class="bw-tab-head"><div class="bw-tab">1. ' + (t.opts.lang === 'pl' ? 'Termin' : 'Time') + '</div><div class="bw-tab active">2. ' + (t.opts.lang === 'pl' ? 'Podsumowanie' : 'Summary') + '</div></div>'
            + '<button class="bw-link" data-action="back-to-slots">‹ ' + (t.opts.lang === 'pl' ? 'Zmień termin' : 'Change time') + '</button>'
            + '<h3 class="bw-h3" style="margin-top:14px;">' + (t.opts.lang === 'pl' ? 'Twoje dane' : 'Your details') + '</h3>'
            + '<p class="bw-sub">' + esc(date) + ' · ' + esc(hm) + '</p>'
            + '<form class="bw-form" data-action="submit">'
            + rows
            + '<div class="bw-actions">'
            + '<span></span>'
            + '<button class="bw-btn" type="submit"' + (t.state.loading ? ' disabled' : '') + '>' + (t.opts.lang === 'pl' ? 'Potwierdź' : 'Confirm') + ' →</button>'
            + '</div></form>';
    };

    Widget.prototype.renderSuccess = function () {
        var t = this;
        var b = t.state.booking;
        var start = b ? new Date(b.start) : null;
        var fmt = start ? start.toLocaleString(t.opts.lang === 'pl' ? 'pl-PL' : 'en-US', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
        return ''
            + '<div class="bw-success">'
            + '<h3 class="bw-h3">' + (t.opts.lang === 'pl' ? 'Rezerwacja potwierdzona ✓' : 'Booking confirmed ✓') + '</h3>'
            + '<p class="bw-sub">' + (t.opts.lang === 'pl' ? 'Otrzymasz e-mail z potwierdzeniem.' : 'A confirmation email is on its way.') + '</p>'
            + '<div class="bw-meta-row"><strong>' + (t.opts.lang === 'pl' ? 'Termin' : 'When') + ':</strong> ' + esc(fmt) + '</div>'
            + (b && b.meet_link ? '<div class="bw-meta-row"><strong>Google Meet:</strong> <a href="' + esc(b.meet_link) + '">' + esc(b.meet_link) + '</a></div>' : '')
            + (b && b.reschedule_url ? '<p style="margin-top:20px;"><a class="bw-link" href="' + esc(b.reschedule_url) + '">' + (t.opts.lang === 'pl' ? 'Zmień termin lub anuluj' : 'Reschedule or cancel') + '</a></p>' : '')
            + '</div>';
    };

    Widget.prototype.renderCancelled = function () {
        var t = this;
        return '<div class="bw-success"><h3 class="bw-h3">' + (t.opts.lang === 'pl' ? 'Spotkanie anulowane' : 'Meeting cancelled') + '</h3></div>';
    };

    Widget.prototype.bind = function () {
        var t = this;
        var root = t.root;
        root.querySelectorAll('[data-action="prev"]').forEach(function (b) {
            b.addEventListener('click', function () {
                t.state.month = new Date(t.state.month.getFullYear(), t.state.month.getMonth() - 1, 1);
                t.loadMonth(t.state.month);
            });
        });
        root.querySelectorAll('[data-action="next"]').forEach(function (b) {
            b.addEventListener('click', function () {
                t.state.month = new Date(t.state.month.getFullYear(), t.state.month.getMonth() + 1, 1);
                t.loadMonth(t.state.month);
            });
        });
        root.querySelectorAll('[data-date]').forEach(function (cell) {
            cell.addEventListener('click', function () {
                t.state.selectedDate = cell.getAttribute('data-date');
                t.state.view = 'slots';
                t.render();
            });
        });
        root.querySelectorAll('[data-action="back-to-calendar"]').forEach(function (b) {
            b.addEventListener('click', function () { t.state.view = 'calendar'; t.render(); });
        });
        root.querySelectorAll('[data-action="back-to-slots"]').forEach(function (b) {
            b.addEventListener('click', function () { t.state.view = 'slots'; t.render(); });
        });
        root.querySelectorAll('[data-slot]').forEach(function (b) {
            b.addEventListener('click', function () {
                t.state.selectedSlot = b.getAttribute('data-slot');
                if (t.opts.rescheduleToken) {
                    t.submitReschedule();
                } else {
                    t.state.view = 'form';
                    t.render();
                }
            });
        });
        var form = root.querySelector('form[data-action="submit"]');
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                t.submitBooking(new FormData(form));
            });
        }
    };

    Widget.prototype.submitBooking = function (fd) {
        var t = this;
        var fields = {};
        var honeypot = '';
        fd.forEach(function (v, k) {
            if (k === 'hp_company') honeypot = v;
            else fields[k] = v;
        });
        t.state.loading = true;
        t.state.error = null;
        t.render();
        fetch(t.opts.api + '/bookings', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
                event_type: t.opts.slug,
                slot: t.state.selectedSlot,
                fields: fields,
                hp_company: honeypot,
                timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            }),
        }).then(function (r) { return r.json().then(function (data) { return { ok: r.ok, status: r.status, data: data }; }); })
          .then(function (resp) {
              t.state.loading = false;
              if (!resp.ok) {
                  t.state.error = (resp.data && resp.data.message) || (resp.data && resp.data.error) || 'Booking failed.';
                  if (resp.status === 409) {
                      t.state.view = 'calendar';
                      t.loadMonth(t.state.month);
                  } else {
                      t.render();
                  }
                  return;
              }
              t.state.booking = resp.data;
              t.state.view = 'success';
              t.render();
          })
          .catch(function (e) {
              t.state.loading = false;
              t.state.error = e.message || 'Network error.';
              t.render();
          });
    };

    Widget.prototype.submitReschedule = function () {
        var t = this;
        t.state.loading = true;
        t.render();
        fetch(t.opts.api + '/bookings/' + encodeURIComponent(t.opts.rescheduleToken) + '/reschedule', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ slot: t.state.selectedSlot }),
        }).then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
          .then(function (resp) {
              t.state.loading = false;
              if (!resp.ok) {
                  t.state.error = (resp.data && resp.data.message) || 'Reschedule failed.';
                  t.render();
                  return;
              }
              t.state.booking = resp.data;
              t.state.view = 'success';
              t.render();
          });
    };

    function iso(d) {
        var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':00';
    }
    function ymd(d) {
        var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    }
    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
})();
