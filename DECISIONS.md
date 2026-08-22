# Decisions

Choices that shaped this codebase, with the reasoning behind them. Anything
recorded here was a fork in the road rather than a default, so the next person
does not have to re-argue it from scratch.

---

## Why Docker containers

The brief asks for a standard LAMP box with Redis. That is exactly what runs
inside the containers -- Apache with mod_php, MySQL, Redis. Docker is only how
the box is packaged, not a change to the architecture. Nothing in `app/`,
`config/` or `routes/` knows it is in a container, so the same code drops onto
a plain LAMP server unchanged.

It is packaged this way because the application needs PHP 8.3 or newer, plus
Redis and an SMTP catcher, and asking a reviewer to install all of that by hand
on whatever machine they happen to use is slow and easy to get wrong. The brief
asks that the project run "from a clean environment". With Docker that is
`docker compose up -d` and the versions are identical on every machine.

The cost is that Docker has to be installed, and that the image is built for
development rather than production -- there is no `composer install` baked in
and no separate production stage.

---

## One image, three containers: `app`, `queue` and `scheduler`

All three are built from the same image through the `x-app` anchor in
`docker-compose.yml`. Only the command differs: Apache, `queue:work` and
`schedule:work`.

They are split because a real LAMP deployment splits them too. Apache runs
under systemd, the queue worker runs under Supervisor, and the scheduler is one
cron entry. Compose mirrors that one for one, so what is tested locally matches
how it would be deployed. `restart: unless-stopped` does the job Supervisor
does; the `scheduler` container does the job the cron entry does.

Keeping them apart also keeps their failures apart. A stuck queue worker must
not stop the scheduler from queueing the next cycle, and neither should be able
to take Apache down. It also gives an easy way to grow: more workers is
`docker compose up -d --scale queue=4`, with no change to the application.

The rejected alternative was running the worker in the web container with `&`.
It dies whenever Apache restarts, nothing brings it back, and it never receives
a clean shutdown signal. (`pcntl` is installed in the image for that signal
handling, so a worker finishes the check it is running before it exits.)

---

## No `env_file:` in the compose anchor

The project directory is bind-mounted into the containers, so Laravel already
reads `.env` straight off disk. Also injecting it with `env_file:` looks
harmless and causes two real problems.

First, injected values become real process environment variables, and those
outrank the `<env>` settings in `phpunit.xml`. The test suite would quietly run
against the development MySQL database instead of the in-memory SQLite one.

Second, Compose injects the environment when a container is **created**, not
when it starts. After editing `.env`, `docker compose restart` keeps the old
values, and Laravel will not overwrite a variable that already exists in the
process environment -- so the stale value silently wins over the file you just
edited.

Leaving it out means the file on disk is always the single source of truth.
Only `XDEBUG_MODE` and `PHP_IDE_CONFIG` are injected, and neither is a Laravel
config key, so nothing in `config/` can be shadowed.

---

## `bin/artisan` and `bin/shell` instead of `docker compose exec`

The image has no `USER` directive, because Apache needs root to bind port 80.
That makes root the default for `docker compose exec`, and anything it
generates -- migrations, `make:*` output, caches -- lands in the bind-mounted
source tree owned by root and cannot be edited from the host.

`docker/php/Dockerfile` remaps the `www-data` user to the host's UID and GID
(passed in as build args, defaulting to 1000). The two wrappers in `bin/` run
commands as that user, so generated files stay editable on the host:

```bash
./bin/artisan migrate      # instead of docker compose exec app php artisan migrate
./bin/shell                # instead of docker compose exec app bash
```

This is a workaround rather than a fix. The proper fixes -- moving Apache to
port 8080 inside the container, or splitting into php-fpm plus a separate web
container -- both cost more than the papercut is worth for this MVP.

---

## `.env.testing` as a separate file

Laravel loads `.env.testing` **instead of** `.env` when `APP_ENV=testing`, never
both. That is the point: the test configuration is complete on its own and
cannot half-inherit whatever a developer has in their local `.env`. Without it,
a key missing from the test setup falls through to the development value, and
the suite ends up talking to the real MySQL database or a real mail server.

The file guarantees the things that must never be true in a test run:
`DB_CONNECTION=sqlite` with `:memory:`, `MAIL_MAILER=array`,
`QUEUE_CONNECTION=sync`, `CACHE_STORE=array`. Nothing in the suite can reach a
live service.

`phpunit.xml` sets several of the same values, which looks redundant but is
not. PHPUnit puts its `<env>` entries into the process environment before
Laravel boots, and Laravel will not overwrite an existing environment variable
-- so `phpunit.xml` wins for the keys it lists, and `.env.testing` supplies the
rest (`APP_KEY`, `MAIL_FROM_ADDRESS`, `MONITOR_TIMEOUT`, `MONITOR_BATCH_SIZE`).
Two layers, both pointing the same way.

`.env.testing` is committed, `APP_KEY` included. It is not a secret: it exists
only to encrypt data in a throwaway in-memory database, and committing it is
what lets the suite run from a fresh clone with no setup.

---

## Monitoring runs in three layers: scheduler, dispatcher, batched jobs

Nothing is checked by the scheduler itself. `routes/console.php` runs
`monitor:dispatch` every fifteen minutes; that command splits the websites into
batches and queues a `MonitorWebsiteBatchJob` for each; the queue workers do the
actual HTTP checks.

The scheduler is a single process running one task at a time. If it checked the
sites itself, hundreds of websites at up to ten seconds each would run well past
the fifteen-minute window, and `withoutOverlapping()` would start skipping
cycles altogether. Dispatching instead costs only a few cheap database reads, so
the scheduler is always finished long before the next cycle is due.

Splitting it this way also means the checking capacity is just the number of
workers. More websites is more queue containers, with no change to the code.

`chunkById()` is used to build the batches rather than loading every ID at once,
so memory stays flat no matter how large the table grows. `withoutOverlapping()`
stops a slow cycle from stacking on top of the next one.

---

## `Http::pool()` checks a batch concurrently

`MonitorWebsiteBatchJob` sends all the requests in its batch at once instead of
one after another.

The numbers force it. A batch is 25 websites and the timeout is 10 seconds, so
checking them in sequence is up to 250 seconds -- longer than the job's own
60-second timeout, and slow enough that a few batches of dead sites would push
the whole cycle past fifteen minutes. Sent together, the batch costs roughly one
slow website rather than the sum of all of them.

`batch_size` and `timeout` live in `config/monitoring.php` and are linked: the
batch is sized to comfortably fit inside the job timeout when run concurrently.
Raising the batch size without understanding that is how you get jobs timing
out.

One more benefit: a pooled request that fails comes back as an exception object
in the results array rather than being thrown. One dead website therefore cannot
abort the rest of its batch -- every site in the batch is still recorded.

---

## Two queues, with `alerts` ahead of `monitoring`

The checks go on the `monitoring` queue and the emails go on `alerts`, and the
worker is started with `--queue=alerts,monitoring,default`. That order is a
priority list: the worker always empties `alerts` before it looks at
`monitoring`.

With one shared queue, an alert queued at the start of a cycle would sit behind
every remaining check job, so the email a client actually needs arrives after
minutes of work that could have waited. Alerts are rare and fast; checks are
many and slow. Putting the rare fast thing first costs nothing and keeps alerts
prompt even when there is a backlog.

Separate queues also make the two workloads independently scalable later --
a dedicated mail worker needs no code change, only a different `--queue` flag.

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
the blast radius of our own worst failure mode -- if this application loses DNS
or internet access, every website looks down at once, and transition-only
alerting makes that one wave of email rather than a wave every quarter of an
hour.

Both `UP` and `UNKNOWN` count as "not currently down", so a site whose very
first check fails is alerted on. The alert re-arms as soon as a check succeeds;
recovery is implicit, and no "back up" email is sent because the spec does not
ask for one.

What this costs: there is no reminder while an outage continues, so an alert
that is lost is lost until the site recovers and fails again. If that becomes a
problem, the fix is a re-notification interval (re-alert after N hours of
continuous downtime) rather than a return to per-check mail. The behaviour is
pinned by `tests/Feature/WebsiteDownAlertTest.php`.

---

## Alerts are queued, and a failed send never fails the batch

`notifyClient()` uses `Mail::to(...)->queue(...)` rather than `->send(...)`, and
wraps it in a `try/catch` that logs the problem and moves on.

Queueing keeps the mail server off the monitoring job's clock. `send()` holds
the job open for the SMTP handshake and delivery, so a slow or unreachable mail
server would make the checks themselves slow -- the one thing that has to finish
inside fifteen minutes. Queued, the job hands the email over and immediately
carries on with the next website.

The `try/catch` is there because a batch checks up to 25 websites belonging to
different clients. One malformed address, or a mail service that is briefly
down, would otherwise throw and abandon the rest of the batch -- so a mail
problem would turn into a monitoring problem. Swallowing it means the failure is
recorded in the log and every remaining website is still checked.

Note that the site's status is saved **before** the email is attempted, so a
lost email never leaves the database out of step with what was actually
observed. The trade-off is that a swallowed failure is only visible in the logs;
at a larger scale this should page someone instead.

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
