# Decisions

Choices that shaped this codebase, with the reasoning behind them. Anything
recorded here was a fork in the road rather than a default, so the next person
does not have to re-argue it from scratch.

---

## Frontend: a Vue SPA served by Laravel, with no router and no store

The SPA is built by the Vite pipeline that already ships with the skeleton and
served from the Laravel root route as `resources/views/app.blade.php`, rather
than living in a separate frontend project. The brief targets a single LAMP
host, and keeping both halves in one application means one deployment, one
build artifact under `public/build`, and same-origin API calls -- so there is no
CORS layer, no second web server, and no separate base URL to configure per
environment. `vue-router` and Pinia are deliberately absent: the spec defines
exactly one view, so a router would add a dependency and a catch-all route to
resolve a single path, and the entire application state is four values --
`clients`, `selectedClientId`, `websites` and `dialogTarget` -- which live as
refs in `App.vue` and reach the three child components as props. A store would
be indirection around something a single component already owns. `axios` is
likewise omitted in favour of native `fetch` for two unauthenticated GETs, and
the confirmation dialog uses the native `<dialog>` element, whose `showModal()`
gives focus trapping, Esc-to-dismiss and a backdrop without a modal library.

---

## Alerting: one email per transition into down, not one per failed check

The spec says an email goes out "when a website is detected as down", which
read literally means every check that finds a site unreachable. It does not:
`MonitorWebsiteBatchJob::record()` sends only when the status *changes* into
down (`$current === DOWN && $previous !== DOWN`).

The scheduler runs `monitor:dispatch` every fifteen minutes, so the literal
reading produces 96 identical emails per site per day for an outage nobody has
fixed yet, multiplied by every site a client has on the same failing host. The
first email carries everything the client can act on; the remaining 95 are
noise, and a monitoring system that mails at that volume gets its sending
domain classified as spam, which costs the alerts that do matter. It also caps
the blast radius of the failure mode noted in `documents/other/notes.md` --
when *our* container loses DNS or internet, every site flips to down at once,
and transition-only alerting makes that one wave of email rather than a wave
every quarter of an hour.

Both `UP` and `UNKNOWN` count as "not currently down", so a site whose very
first check fails is alerted on. The alert re-arms as soon as a check succeeds;
recovery is implicit, and no "back up" email is sent because the spec does not
ask for one.

What this costs: there is no reminder while an outage continues, so an alert
that is lost is lost until the site recovers and fails again -- and mail
failures are deliberately swallowed in `notifyClient()` so one bad address
cannot fail the batch. If that becomes a problem, the fix is a re-notification
interval (re-alert after N hours of continuous downtime) rather than a return
to per-check mail. The behaviour is pinned by
`tests/Feature/WebsiteDownAlertTest.php`.

---

## The alert sender is a config default, not something `.env` has to supply

`config/mail.php` ships with Laravel's `env('MAIL_FROM_ADDRESS',
'hello@example.com')`. Alerts are supposed to come from
`do-not-reply@example.com`, and that address was only ever set in
`.env.example` and `.env.testing` -- so the requirement held for anyone who
copied the example file and silently broke for anyone whose `.env` predates it
or omits the key. Nothing failed loudly; the mail just went out from
`hello@example.com`.

The default in `config/mail.php` is now the no-reply address, so the correct
sender is a property of the application rather than of a particular
environment file. `MAIL_FROM_ADDRESS` still overrides it, which is what a real
deployment needs in order to send from its own domain.

The alternative was `from:` in `WebsiteDownEmail`'s `Envelope`. It was rejected
because it hardcodes one address into the mailable and takes the per-deployment
override away, to fix what is really a wrong default one layer down. Config
defaults are already how this project handles the same problem elsewhere --
`config/monitoring.php` gives the spec's ten-second timeout as the default for
`MONITOR_TIMEOUT` rather than relying on `.env` to carry it.
