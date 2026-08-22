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

The jobs are handed to the queue with `Queue::bulk()` in groups of
`dispatch_chunk` rather than dispatched one by one, so a cycle's 34 jobs cost
one Redis round trip instead of 34. This is a small saving in absolute terms --
the dispatcher was never the bottleneck -- but it keeps the scheduler's single
task trivially short, which is the property the whole layering depends on. The
one wrinkle is that a bulk push bypasses the job dispatcher, so the queue name
has to be passed explicitly; the job's own `onQueue()` call is not consulted.

---

## Statuses are written per batch, not per website

`MonitorWebsiteBatchJob` collects each site's outcome in memory, then issues one
`UPDATE ... WHERE id IN (...)` per distinct status. A batch only ever produces
UP or DOWN, so that is at most two writes however large the batch is.

The obvious implementation -- `$website->save()` inside the loop -- costs one
write per website instead. At the target scale that is around 1,650 individual
UPDATE statements every fifteen minutes, all of them tiny, all of them a round
trip to MySQL, to record two distinct values. Grouping them turns the cycle's
write cost from "proportional to the number of websites" into "proportional to
the number of batches", which is the shape that survives growth.

Two consequences worth knowing about. Mass updates bypass Eloquent's timestamp
handling, so `updated_at` is set explicitly in the payload rather than left to
the model. And because the writes are grouped, they now happen *before* any
alert is queued rather than interleaved with them -- which is strictly better:
a mail failure can no longer leave part of the batch's results unrecorded.

---

## `Http::pool()` checks a batch concurrently

`MonitorWebsiteBatchJob` sends all the requests in its batch at once instead of
one after another.

The numbers force it. A batch is 50 websites and the timeout is 10 seconds, so
checking them in sequence is up to 500 seconds -- longer than the job's own
timeout, and slow enough that a few batches of dead sites would push the whole
cycle past fifteen minutes. Sent together, the batch costs roughly one slow
website rather than the sum of all of them.

One more benefit: a pooled request that fails comes back as an exception object
in the results array rather than being thrown. One dead website therefore cannot
abort the rest of its batch -- every site in the batch is still recorded.

---

## Sizing a cycle

`timeout`, `batch_size` and `job_timeout` in `config/monitoring.php` are three
faces of one calculation, and changing any of them in isolation is how you get
jobs killed mid-flight or cycles that overlap. The target scale is the brief's:
hundreds of clients at up to ten websites each, so roughly 1,650 websites.

The chain is:

- **A batch costs about one timeout, not fifty.** `Http::pool()` runs the batch
  concurrently, so 50 sites at a 10-second timeout is ~10 seconds of wall clock
  in the worst case where every one of them hangs, not 500.
- **`job_timeout` (120s) must clear that with room to spare.** It covers the
  10-second tail plus connection setup, the pool's own overhead and the status
  writes. 60 seconds would have worked too; 120 is deliberate slack, because a
  job killed at the timeout records *nothing* and loses the whole batch.
- **The cycle must fit in fifteen minutes.** 1,650 websites at 50 per batch is
  34 jobs. One worker processing them back to back at the ~10-second worst case
  is ~6 minutes -- comfortably inside the window, with the margin absorbing
  slower checks and queue latency.

The worst case is the one worth stating plainly: it assumes *every* site hangs
until timeout. Real cycles are far quicker, because responsive sites answer in
milliseconds and unresolvable ones fail on DNS almost as fast.

Where it stops fitting: at roughly 4,500 websites a single worker's worst case
crosses fifteen minutes, `withoutOverlapping()` starts skipping cycles, and the
answer is workers rather than code -- `docker compose up -d --scale queue=N`
divides the cycle by N. Raising `batch_size` instead trades one problem for
another, since a larger pool means more concurrent sockets in one PHP process
and a longer tail inside a single job timeout.

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
resolve a single path, and the application state is a handful of values --
`clients`, `clientSearch`, `selectedClientId`, `websites` and `dialogTarget` --
which live as refs in `App.vue` and reach the three child components as props. A
store would be indirection around something a single component already owns.
`axios` is likewise omitted in favour of native `fetch` for two unauthenticated
GETs, and the confirmation dialog uses the native `<dialog>` element, whose
`showModal()` gives focus trapping, Esc-to-dismiss and a backdrop without a
modal library.

---

## `GET /api/clients` is paginated and searched server-side

This endpoint used to return every client in a single array, and the test suite
asserted that it did. That was the right call for a demo-sized table and the
wrong one at the brief's scale: hundreds of rows serialised on every page load,
and a `<select>` with hundreds of `<option>`s for the user to scroll through.

So the endpoint now takes `search`, `page` and `per_page`, defaults to 50 rows,
and caps `per_page` at 100 -- the cap matters, or a caller can simply ask for
the whole table and undo the point of paginating. The response gained a `meta`
block alongside `data` because the client needs the full `total` to tell the
user the list it is showing is not the whole list.

Filtering is server-side rather than in the browser, and that follows directly
from paginating: the frontend deliberately never holds the full set, so a
client-side filter could only ever filter the page it already has. The search
input is debounced by 300ms and shares the same in-flight-request guard as the
website list, since a debounced search can still have two requests racing and
the slower one must not overwrite the newer.

Two smaller notes. The query orders by `email` -- pagination is only coherent
over a total, stable sort, and `email` is unique so it makes one on its own.
And the search term has its `%`, `_` and `\` escaped before it reaches `LIKE`,
or a search for `%` would match every client rather than none.

`GET /api/clients/{client}/websites` is deliberately *not* paginated. The brief
caps a client at ten websites, so that response is bounded by the data model
itself and a page size would be ceremony around a list that cannot grow.

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

---

## `bin/setup` as the single entry point for a fresh clone

The brief asks that the project run from a clean environment. `docker compose up
-d` gets the containers running but not the application: `vendor/` and
`node_modules/` are gitignored, `.env` does not exist, `APP_KEY` is empty, the
database is unmigrated, and `public/build` has no compiled assets. That is six
more commands, two of which have to run as the right user inside the right
container, and one of which cannot run in any container at all because the PHP
image has no Node.

`bin/setup` is that sequence written down once. Docker stays the only
prerequisite -- the asset build borrows a throwaway `node:22-bookworm-slim`
container rather than asking for Node on the host, which keeps the promise made
in "Why Docker containers" that versions are identical on every machine.

Two details are worth knowing. It re-seeds on every run, which is why
`MonitoringSeeder` is written to be idempotent -- see the next section. And it
checks the published ports before building anything, because a
host that already has MySQL on 3306 is common and the alternative is an opaque
bind error several minutes into the build.

The cost is a script that duplicates knowledge already present in
`docker-compose.yml` and `composer.json`, and that will drift if the setup steps
change without it. It is worth that: the reviewer's first five minutes are the
ones most likely to end in a wrong conclusion about the project.


---

## Seed data: a few real clients, then hundreds of generated ones

`MonitoringSeeder` writes two kinds of data, because they answer two different
questions.

Three **demo clients** carry hand-written, genuinely resolving URLs
(`laravel.com`, `vuejs.org`) alongside genuinely unresolvable ones. They exist
so the first cycle produces real UP *and* DOWN rows and puts a real alert in
Mailpit -- proof the monitor works, which a table of uniform failures would not
give. At seven rows they are written with `firstOrCreate`, one at a time; the
extra queries cost nothing and the readability is worth having.

Then ~300 **generated clients** with one to ten websites each, ~1,650 rows in
total, matching the brief's stated scale. These exist to prove the system works
*at volume*: they are what puts a realistic number of rows behind the
dispatcher's `chunkById`, the batch jobs, and the paginated client list. Without
them every scale decision above is an untested assertion.

Three choices in there are load-bearing:

**Everything is derived from the client's index, not from Faker.** This is what
makes re-seeding a no-op. `bin/setup` re-seeds on every run, and the same run
produces the same emails and the same URLs, so `insertOrIgnore` collides with
the rows already there and writes nothing. Random data would instead add a fresh
three hundred clients every time anyone ran setup twice.

**The generated hosts live under `.example.com`,** which RFC 2606 reserves.
Nothing the seeder invents can resolve to somebody's real server, so a seeded
database can never point 1,650 concurrent checks at a third party. The
consequence is that the monitor marks every generated site down, which is the
intended outcome rather than a defect.

**The writes are bulk `insertOrIgnore` in chunks,** not `firstOrCreate` per row.
Row-at-a-time would be some 1,950 round trips; chunked it is a handful, and the
whole seed lands in about 250ms. The chunk sizes are set below SQLite's bind
parameter limit so the seeder behaves identically in the test suite and against
MySQL.

Volume is configurable via `MONITOR_SEED_CLIENTS` and
`MONITOR_SEED_MAX_WEBSITES` for anyone who wants a faster `bin/setup` or a
larger load test, but it defaults to full scale -- demo data that quietly
understates the target scale is how scale problems reach production.
