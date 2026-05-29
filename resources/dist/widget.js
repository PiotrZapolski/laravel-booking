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
    // Attribution strings the host page can inject so the `booking:confirmed`
    // CustomEvent carries them through to whichever pixel / ads tag fires.
    var firstChannel = ds.firstChannel || null;
    var lastSource = ds.lastSource || null;
    var prefill = collectPrefill(ds);

    if (!slug) {
        console.error('[booking-widget] data-event-type is required on the <script> tag');
        return;
    }

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
        // The widget inherits font/color from the host. All concrete values
        // are CSS variables a host can override at .bw-root scope.
        s.textContent = (''
            + '.bw-root{'
            + '--bw-accent:' + primary + ';'
            + '--bw-muted:rgba(0,0,0,0.55);'
            + '--bw-border:rgba(0,0,0,0.10);'
            + '--bw-surface:transparent;'
            + '--bw-radius:0;'
            + 'font:inherit;color:inherit;background:var(--bw-surface);'
            + 'border:0;border-radius:var(--bw-radius);'
            + 'max-width:100%;width:100%;margin:0;line-height:1.5;'
            + 'box-sizing:border-box;display:block;'
            + '}'
            + '.bw-root *{box-sizing:border-box;}'

            // Header strip: title + inline meta (replaces colored sidebar)
            + '.bw-head{display:flex;flex-wrap:wrap;align-items:baseline;gap:12px 22px;'
            + 'padding-bottom:18px;margin-bottom:24px;border-bottom:1px solid var(--bw-border);}'
            + '.bw-head h2{font:inherit;font-weight:700;font-size:clamp(20px,2.2vw,28px);line-height:1.2;margin:0;flex:1 1 auto;}'
            + '.bw-meta{display:flex;flex-wrap:wrap;gap:6px 18px;color:var(--bw-muted);font-size:14px;}'
            + '.bw-meta span{white-space:nowrap;}'
            + '.bw-desc{flex-basis:100%;color:var(--bw-muted);margin:0;font-size:15px;line-height:1.55;}'

            // Tab strip (de-emphasised)
            + '.bw-tabs{display:flex;gap:24px;margin:0 0 22px;font-size:13px;color:var(--bw-muted);}'
            + '.bw-tab{padding:0 0 8px;border-bottom:2px solid transparent;}'
            + '.bw-tab.active{color:inherit;border-bottom-color:var(--bw-accent);font-weight:500;}'

            // Step heading
            + '.bw-h3{font:inherit;font-weight:700;font-size:clamp(18px,1.8vw,22px);margin:0 0 6px;line-height:1.25;}'
            + '.bw-sub{color:var(--bw-muted);margin:0 0 20px;font-size:15px;}'

            // Calendar layout
            + '.bw-stack{display:grid;gap:24px;}'
            + '@media (min-width:900px){.bw-stack.bw-stack-2{grid-template-columns:minmax(360px,1.2fr) minmax(280px,1fr);align-items:start;}}'
            + '.bw-cal-head{display:flex;align-items:center;justify-content:space-between;margin:0 0 14px;}'
            + '.bw-cal-title{font-weight:600;font-size:17px;}'
            + '.bw-nav{display:flex;gap:10px;}'
            + '.bw-icon-btn{width:40px;height:40px;border-radius:50%;border:1px solid var(--bw-border);background:transparent;color:inherit;font:inherit;font-size:18px;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:border-color .15s,background .15s;}'
            + '.bw-icon-btn:hover{border-color:var(--bw-accent);}'
            + '.bw-icon-btn[disabled]{opacity:.35;cursor:not-allowed;}'
            + '.bw-icon-btn[disabled]:hover{border-color:var(--bw-border);}'

            + '.bw-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:8px;}'
            + '.bw-dow{font-size:12px;text-align:center;color:var(--bw-muted);padding:4px 0 8px;font-weight:500;letter-spacing:.02em;}'
            + '.bw-day{min-height:52px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:16px;color:var(--bw-muted);cursor:default;font-weight:500;}'
            + '.bw-day.bw-bookable{color:inherit;background:transparent;border:1px solid var(--bw-border);cursor:pointer;transition:border-color .12s,background .12s,color .12s;}'
            + '.bw-day.bw-bookable:hover{border-color:var(--bw-accent);color:var(--bw-accent);}'
            + '.bw-day.bw-selected{background:var(--bw-accent);color:#fff;border-color:var(--bw-accent);box-shadow:0 0 0 4px color-mix(in srgb,var(--bw-accent) 22%,transparent);}'

            // Slots (multi-column on wide screens)
            + '.bw-slots-panel{}'
            + '.bw-slots-heading{font-weight:600;font-size:15px;margin:0 0 14px;color:inherit;}'
            + '.bw-slots{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:10px;}'
            + '.bw-slot{padding:14px 12px;border:1px solid var(--bw-border);border-radius:10px;text-align:center;cursor:pointer;font-weight:600;font-size:15px;color:inherit;background:transparent;font:inherit;transition:border-color .12s,color .12s,transform .12s;}'
            + '.bw-slot:hover{border-color:var(--bw-accent);color:var(--bw-accent);transform:translateY(-1px);}'

            // Form
            + '.bw-form{display:grid;grid-template-columns:1fr;gap:18px;}'
            + '@media (min-width:640px){.bw-form{grid-template-columns:1fr 1fr;}.bw-field.bw-field-full{grid-column:1 / -1;}}'
            + '.bw-field label{display:block;font-size:13px;font-weight:600;margin:0 0 6px;letter-spacing:.01em;}'
            + '.bw-field input,.bw-field textarea{width:100%;padding:13px 14px;border:1px solid var(--bw-border);border-radius:10px;font:inherit;font-size:16px;color:inherit;background:transparent;transition:border-color .12s,box-shadow .12s;}'
            + '.bw-field input:focus,.bw-field textarea:focus{outline:0;border-color:var(--bw-accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--bw-accent) 18%,transparent);}'
            + '.bw-field textarea{min-height:96px;resize:vertical;}'

            // Actions row + buttons
            + '.bw-actions{display:flex;justify-content:flex-end;align-items:center;gap:14px;margin-top:8px;grid-column:1 / -1;}'
            + '.bw-btn{padding:14px 26px;background:var(--bw-accent);color:#fff;border:0;border-radius:10px;font-weight:600;cursor:pointer;font:inherit;font-size:16px;transition:filter .12s,transform .12s;}'
            + '.bw-btn:hover{filter:brightness(1.05);}'
            + '.bw-btn[disabled]{opacity:.7;cursor:progress;}'
            + '.bw-spinner{display:inline-block;width:14px;height:14px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:bw-spin .7s linear infinite;vertical-align:-2px;margin-right:8px;}'
            + '@keyframes bw-spin{to{transform:rotate(360deg);}}'
            + '.bw-link{background:transparent;border:0;color:var(--bw-accent);cursor:pointer;font:inherit;padding:0;font-size:14px;font-weight:500;text-decoration:underline;text-underline-offset:3px;}'
            + '.bw-link:hover{text-decoration-thickness:2px;}'

            // States
            + '.bw-error{background:color-mix(in srgb,#dc2626 8%,transparent);color:#b91c1c;padding:12px 14px;border-radius:10px;margin-bottom:18px;font-size:14px;}'
            + '.bw-success{padding:8px 0 24px;text-align:center;}'
            + '.bw-success .bw-check{font-size:48px;color:var(--bw-accent);margin:0 0 12px;line-height:1;}'
            + '.bw-success h3{font:inherit;font-weight:700;font-size:clamp(22px,2vw,28px);margin:0 0 8px;}'
            + '.bw-success p{color:var(--bw-muted);margin:0 0 22px;}'
            + '.bw-success-card{max-width:520px;margin:0 auto;text-align:left;border:1px solid var(--bw-border);border-radius:14px;padding:20px 22px;}'
            + '.bw-meta-row{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid var(--bw-border);font-size:15px;}'
            + '.bw-meta-row:last-child{border-bottom:0;}'
            + '.bw-meta-row strong{font-weight:600;}'
            + '.bw-meta-row a{color:var(--bw-accent);word-break:break-all;}'
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
            // Auto-advance is armed on boot. If the landing month has zero
            // bookable days, boot() walks the calendar forward (one month at a
            // time, up to max_advance_days) until it finds a month with slots.
            // Any user-initiated month nav click disarms this so the visitor
            // can revisit empty months without the widget yanking them back.
            autoAdvanceArmed: true,
        };
    }

    Widget.prototype.boot = function () {
        var self = this;
        this.root.classList.add('bw-root');
        this.render();
        this.fetchEventType().then(function () {
            return self.loadMonth(self.state.month);
        }).then(function () {
            if (self.state.autoAdvanceArmed) {
                return self.findFirstNonEmptyMonth();
            }
        }).catch(function (e) {
            self.state.error = (e && e.message) || 'Failed to load.';
            self.render();
        });
    };

    // Walk state.month forward one month at a time, calling loadMonth, until
    // slotsByDay has at least one non-empty entry or we exceed the event
    // type's max_advance_days cap. Keeps state.loading true between iterations
    // so the visitor sees the spinner, not flashes of empty grids.
    Widget.prototype.findFirstNonEmptyMonth = function () {
        var self = this;
        var maxAdvance = (self.eventType && self.eventType.max_advance_days) || 90;
        var today = new Date();
        today.setHours(0, 0, 0, 0);

        function hasSlots() {
            var byDay = self.state.slotsByDay || {};
            for (var k in byDay) {
                if (Object.prototype.hasOwnProperty.call(byDay, k) && byDay[k] && byDay[k].length) {
                    return true;
                }
            }
            return false;
        }

        function step() {
            if (hasSlots()) {
                return Promise.resolve();
            }
            var next = new Date(self.state.month.getFullYear(), self.state.month.getMonth() + 1, 1);
            var advancedDays = Math.ceil((next - today) / 86400000);
            if (advancedDays > maxAdvance) {
                return Promise.resolve();
            }
            self.state.month = next;
            self.state.loading = true;
            return self.loadMonth(self.state.month).then(step);
        }

        return step();
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
            // Group slots by *viewer-local* day (not organiser day) so a slot
            // at 22:00 in Warsaw appearing as 16:00 in New York lands on the
            // correct day on the New-York-based visitor's calendar.
            self.state.slots.forEach(function (s) {
                var d = ymdInTz(new Date(s.start), userTz());
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
        var html = ''
            + this.renderHead()
            + (this.state.error ? '<div class="bw-error">' + esc(this.state.error) + '</div>' : '')
            + this.renderMain();
        this.root.innerHTML = html;
        this.bind();
    };

    Widget.prototype.renderHead = function () {
        var et = this.eventType;
        if (!et) return '<div class="bw-head"><h2>…</h2></div>';
        var meta = '<div class="bw-meta">'
            + '<span>' + et.duration + ' min</span>'
            + (et.location === 'google_meet' ? '<span>· Google Meet</span>' : '')
            + (et.timezone ? '<span>· ' + esc(et.timezone) + '</span>' : '')
            + (et.organizer && et.organizer.name ? '<span>· ' + esc(et.organizer.name) + '</span>' : '')
            + '</div>';
        return '<div class="bw-head">'
            + '<h2>' + esc(et.title) + '</h2>'
            + meta
            + (et.description ? '<p class="bw-desc">' + esc(et.description) + '</p>' : '')
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

        var tzLine = (t.opts.lang === 'pl' ? 'Godziny w Twojej strefie czasowej: ' : 'Times shown in your timezone: ') + esc(userTz());

        return ''
            + '<div class="bw-tabs"><div class="bw-tab active">1. ' + (t.opts.lang === 'pl' ? 'Termin' : 'Time') + '</div><div class="bw-tab">2. ' + (t.opts.lang === 'pl' ? 'Podsumowanie' : 'Summary') + '</div></div>'
            + '<h3 class="bw-h3">' + (t.opts.lang === 'pl' ? 'Wybierz datę' : 'Pick a date') + '</h3>'
            + '<p class="bw-sub">' + (t.opts.lang === 'pl' ? 'Najpierw wybierz dzień, potem godzinę.' : 'Pick a day, then a time.') + ' <span style="color:var(--bw-muted);font-size:13px;">· ' + tzLine + '</span></p>'
            + '<div class="bw-cal-head">'
            + '<div class="bw-cal-title">' + esc(title) + '</div>'
            + '<div class="bw-nav">'
            + '<button class="bw-icon-btn" data-action="prev" aria-label="Previous month"' + (canPrev ? '' : ' disabled') + '>‹</button>'
            + '<button class="bw-icon-btn" data-action="next" aria-label="Next month">›</button>'
            + '</div></div>'
            + '<div class="bw-grid">' + grid + '</div>'
            + (t.state.loading ? '<p style="text-align:center;color:var(--bw-muted);margin-top:18px;">…</p>' : '');
    };

    Widget.prototype.renderSlots = function () {
        var t = this;
        var slots = t.state.slotsByDay[t.state.selectedDate] || [];
        var dt = new Date(t.state.selectedDate + 'T12:00:00');
        var heading = dt.toLocaleDateString(t.opts.lang === 'pl' ? 'pl-PL' : 'en-US', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

        var rows = slots.length
            ? slots.map(function (s) {
                var hm = fmtTimeInTz(new Date(s.start), userTz(), t.opts.lang);
                return '<button class="bw-slot" data-slot="' + esc(s.start) + '">' + hm + '</button>';
            }).join('')
            : '<p style="color:var(--bw-muted);">' + (t.opts.lang === 'pl' ? 'Brak dostępnych godzin.' : 'No times available.') + '</p>';

        return ''
            + '<div class="bw-tabs"><div class="bw-tab active">1. ' + (t.opts.lang === 'pl' ? 'Termin' : 'Time') + '</div><div class="bw-tab">2. ' + (t.opts.lang === 'pl' ? 'Podsumowanie' : 'Summary') + '</div></div>'
            + '<button class="bw-link" data-action="back-to-calendar">‹ ' + (t.opts.lang === 'pl' ? 'Powrót do kalendarza' : 'Back to calendar') + '</button>'
            + '<h3 class="bw-h3" style="margin-top:14px;">' + esc(heading) + '</h3>'
            + '<p class="bw-sub">' + (t.opts.lang === 'pl' ? 'Wybierz dostępną godzinę.' : 'Pick an available time.') + '</p>'
            + '<div class="bw-slots">' + rows + '</div>';
    };

    Widget.prototype.renderForm = function () {
        var t = this;
        var fields = (t.eventType && t.eventType.form_fields) || [];
        var slot = t.state.selectedSlot;
        var slotDt = slot ? new Date(slot) : null;
        var hm = slotDt ? fmtTimeInTz(slotDt, userTz(), t.opts.lang) : '';
        var date = slotDt ? slotDt.toLocaleDateString(t.opts.lang === 'pl' ? 'pl-PL' : 'en-US', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: userTz() }) : '';

        var rows = fields.map(function (f) {
            var val = t.state.formValues[f.name] || '';
            var label = esc(f.label || f.name) + (f.required ? ' *' : '');
            var attrs = 'name="' + esc(f.name) + '"' + (f.required ? ' required' : '');
            var input = f.type === 'textarea'
                ? '<textarea ' + attrs + ' rows="4">' + esc(val) + '</textarea>'
                : '<input type="' + esc(f.type || 'text') + '" value="' + esc(val) + '" ' + attrs + ' />';
            var fullWidth = f.type === 'textarea' || f.name === 'description';
            var cls = 'bw-field' + (fullWidth ? ' bw-field-full' : '');
            return '<div class="' + cls + '"><label>' + label + '</label>' + input + '</div>';
        }).join('');

        // Honeypot field
        rows += '<div class="bw-field bw-field-full" style="position:absolute;left:-9999px;" aria-hidden="true"><label>Company<input type="text" name="hp_company" tabindex="-1" autocomplete="off" /></label></div>';

        return ''
            + '<div class="bw-tabs"><div class="bw-tab">1. ' + (t.opts.lang === 'pl' ? 'Termin' : 'Time') + '</div><div class="bw-tab active">2. ' + (t.opts.lang === 'pl' ? 'Podsumowanie' : 'Summary') + '</div></div>'
            + '<button class="bw-link" data-action="back-to-slots">‹ ' + (t.opts.lang === 'pl' ? 'Zmień termin' : 'Change time') + '</button>'
            + '<h3 class="bw-h3" style="margin-top:14px;">' + (t.opts.lang === 'pl' ? 'Twoje dane' : 'Your details') + '</h3>'
            + '<p class="bw-sub">' + esc(date) + ' · ' + esc(hm) + '</p>'
            + '<form class="bw-form" data-action="submit">'
            + rows
            + '<div class="bw-actions">'
            + '<button class="bw-btn" type="submit"' + (t.state.loading ? ' disabled' : '') + '>'
            + (t.state.loading
                ? '<span class="bw-spinner" aria-hidden="true"></span>' + (t.opts.lang === 'pl' ? 'Rezerwuję…' : 'Booking…')
                : (t.opts.lang === 'pl' ? 'Potwierdź' : 'Confirm') + ' →')
            + '</button>'
            + '</div></form>';
    };

    Widget.prototype.renderSuccess = function () {
        var t = this;
        var pl = t.opts.lang === 'pl';
        var b = t.state.booking;
        var start = b ? new Date(b.start) : null;
        var fmt = start ? start.toLocaleString(pl ? 'pl-PL' : 'en-US', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: userTz() }) : '';
        var email = b && b.fields && b.fields.email ? b.fields.email : '';

        var subtitle = pl
            ? ('Wysłaliśmy potwierdzenie' + (email ? ' na <strong>' + esc(email) + '</strong>' : '') + '. Sprawdź też folder Spam.')
            : ('We\'ve sent a confirmation' + (email ? ' to <strong>' + esc(email) + '</strong>' : '') + '. If you don\'t see it, check your spam folder.');

        var calendarInvite = pl
            ? 'Otrzymasz też zaproszenie z Google Calendar — zaakceptuj je, aby dodać spotkanie do swojego kalendarza.'
            : 'You\'ll also receive a separate Google Calendar invite — accept it to add this meeting to your calendar.';

        return ''
            + '<div class="bw-success">'
            + '<div class="bw-check">✓</div>'
            + '<h3>' + (pl ? 'Rezerwacja potwierdzona' : 'Booking confirmed') + '</h3>'
            + '<p>' + subtitle + '</p>'

            + '<div class="bw-success-card">'
            + '<div class="bw-meta-row"><strong>' + (pl ? 'Termin' : 'When') + '</strong><span>' + esc(fmt) + '</span></div>'
            + (b && b.meet_link ? '<div class="bw-meta-row"><strong>Google Meet</strong><a href="' + esc(b.meet_link) + '" target="_blank" rel="noopener" style="color:var(--bw-accent);">' + (pl ? 'Link do spotkania' : 'Meeting link') + ' →</a></div>' : '')
            + '</div>'

            + (b && b.meet_link
                ? '<p style="margin-top:18px;"><a href="' + esc(b.meet_link) + '" target="_blank" rel="noopener" class="bw-btn" style="display:inline-block;text-decoration:none;">' + (pl ? 'Dołącz do spotkania' : 'Join Google Meet') + '</a></p>'
                : '')

            + '<p style="margin:18px auto 0;max-width:520px;color:var(--bw-muted);font-size:13px;line-height:1.55;">' + calendarInvite + '</p>'

            + (b && b.reschedule_url
                ? '<p style="margin-top:20px;"><a class="bw-link" href="' + esc(b.reschedule_url) + '">' + (pl ? 'Zmień termin lub anuluj' : 'Reschedule or cancel') + '</a></p>'
                : '')
            + '</div>';
    };

    Widget.prototype.renderCancelled = function () {
        var t = this;
        return '<div class="bw-success"><div class="bw-check">✓</div><h3>' + (t.opts.lang === 'pl' ? 'Spotkanie anulowane' : 'Meeting cancelled') + '</h3></div>';
    };

    Widget.prototype.bind = function () {
        var t = this;
        var root = t.root;
        root.querySelectorAll('[data-action="prev"]').forEach(function (b) {
            b.addEventListener('click', function () {
                t.state.autoAdvanceArmed = false;
                t.state.month = new Date(t.state.month.getFullYear(), t.state.month.getMonth() - 1, 1);
                t.loadMonth(t.state.month);
            });
        });
        root.querySelectorAll('[data-action="next"]').forEach(function (b) {
            b.addEventListener('click', function () {
                t.state.autoAdvanceArmed = false;
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
        // Guard against double-submit if the click handler fires twice while
        // the spinner is up (e.g. impatient user retapping the button).
        if (t.state.loading) return;
        var fields = {};
        var honeypot = '';
        fd.forEach(function (v, k) {
            if (k === 'hp_company') honeypot = v;
            else fields[k] = v;
        });
        // Persist into formValues so re-render with the spinner doesn't blank
        // the inputs behind the loading button.
        t.state.formValues = Object.assign({}, t.state.formValues, fields);
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
                lang: t.opts.lang,
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

              // Notify the host page that a booking just landed so it can
              // fire its own analytics conversion (Google Ads, Meta Pixel,
              // etc.). The widget intentionally does not own these — every
              // host has its own tag IDs.
              try {
                  var ev = new CustomEvent('booking:confirmed', {
                      bubbles: true,
                      detail: {
                          eventType:   t.opts.slug,
                          payload:     resp.data,
                          firstChannel: t.opts.firstChannel || null,
                          lastSource:   t.opts.lastSource || null,
                      },
                  });
                  window.dispatchEvent(ev);
              } catch (err) {
                  // CustomEvent unsupported (very old IE) — quietly skip.
              }
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
    // Viewer's timezone resolved from the browser. Cached so we don't ask Intl
    // for it on every render. Falls back to UTC if Intl isn't available.
    var _userTz = null;
    function userTz() {
        if (_userTz) return _userTz;
        try {
            _userTz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
        } catch (e) {
            _userTz = 'UTC';
        }
        return _userTz;
    }
    // YYYY-MM-DD for the given Date, projected into the supplied IANA TZ.
    // Used to bucket slots into days as the viewer sees them, not as the
    // organiser's server sees them.
    function ymdInTz(d, tz) {
        try {
            var parts = new Intl.DateTimeFormat('en-CA', {
                timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit',
            }).formatToParts(d);
            var y = '', m = '', day = '';
            for (var i = 0; i < parts.length; i++) {
                if (parts[i].type === 'year') y = parts[i].value;
                else if (parts[i].type === 'month') m = parts[i].value;
                else if (parts[i].type === 'day') day = parts[i].value;
            }
            return y + '-' + m + '-' + day;
        } catch (e) {
            return ymd(d);
        }
    }
    // HH:MM in viewer's TZ for the slot button labels + the form summary.
    // Uses the viewer's locale (pl-PL / en-US) so PL gets 24h format and EN
    // gets whatever their browser conventionally renders.
    function fmtTimeInTz(d, tz, lang) {
        try {
            return d.toLocaleTimeString(lang === 'pl' ? 'pl-PL' : 'en-US', {
                hour: '2-digit', minute: '2-digit', timeZone: tz,
            });
        } catch (e) {
            return d.toISOString().substring(11, 16);
        }
    }
    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // Boot must run AFTER all the Widget.prototype.* assignments above have
    // executed, so we issue it at the very bottom of the IIFE. With a `defer`
    // script, the document is already parsed by the time we get here, so
    // onReady() invokes its callback synchronously.
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
            firstChannel: firstChannel,
            lastSource: lastSource,
        });
        app.boot();
    });
})();
