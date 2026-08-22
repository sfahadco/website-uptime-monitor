# Uptime Monitor

Periodically checks a list of client websites, records whether each one is up or
down, and emails the client when a site goes down. A small Vue dashboard shows
the current state.

Built with Laravel 13 (PHP 8.5), Vue 3, MySQL 8.4 and Redis 8, all in Docker.

## Quickstart

The only thing you need installed is **Docker** with the Compose v2 plugin --
[Docker Desktop](https://docs.docker.com/get-started/get-docker/) on macOS and
Windows includes it, and on Linux it comes with
[Docker Engine](https://docs.docker.com/engine/install/). PHP, Composer, Node
and MySQL all run in containers; nothing else is installed on your machine.

`./bin/setup` checks for both before it does anything, and tells you what is
missing if either is absent.

```bash
git clone https://github.com/sfahadco/website-uptime-monitor.git && cd website-uptime-monitor
./bin/setup
```

Then open **<http://localhost:8000>**.

The script takes a few minutes on a cold start -- it builds the PHP image,
installs dependencies, migrates and seeds the database, and compiles the
frontend. It is safe to re-run at any point.

| | |
|---|---|
| `./bin/setup` | bring everything up (idempotent) |
| `./bin/setup --fresh` | same, but wipe the database and re-seed |
| `./bin/setup --down` | stop everything and remove the volumes |

If a port is already taken, the script says which one and which variable in
`.env` to change, before it builds anything.

## Seeing the monitoring run

The scheduler queues a pass on the quarter hour (:00, :15, :30, :45). To run one
straight away rather than waiting:

```bash
./bin/artisan monitor:dispatch      # queue a pass now
docker compose logs -f queue        # watch it run
```

A pass over the full seed data takes a minute or two on one worker. Most of the
seeded hosts will never resolve, and those failures are what produce the alert
emails in Mailpit.

> **Note:** the dashboard lists each client's websites but does not yet display
> their up/down status -- `/api/clients/{client}/websites` returns only `id` and
> `url`. The statuses are recorded correctly in the database and can be seen
> with `./bin/artisan tinker` or in Mailpit's alerts.

## What you get

**<http://localhost:8000>** -- the dashboard. Pick a client to see the websites
being monitored for them, and click one to open it after a confirmation prompt.

**<http://localhost:8025>** -- [Mailpit](https://mailpit.axllent.org). Every
outgoing email is caught here instead of being delivered, so you can read the
down-alerts without configuring a mail provider.

The seed data ([`MonitoringSeeder`](database/seeders/MonitoringSeeder.php)) is
sized to the brief's target scale -- **303 clients and 1,657 websites**, from a
mix of two sources:

- **Three demo clients** with seven hand-picked, real URLs between them. A
  deliberate mix of reachable hosts and hosts that will never resolve, so both
  the up and down paths are exercised on the first pass and a real alert email
  lands in Mailpit.
- **300 generated clients** with one to ten websites each, under the reserved
  `.example.com` domain so nothing can ever point a check at a real server.
  These exist to put realistic volume behind the dispatcher, the batch jobs and
  the paginated client list. They are seeded already `down`, which is both true
  and quiet: alerts fire on a transition *into* down, so the first cycle
  confirms them rather than mailing you about all 1,650.

Re-seeding is a no-op: every generated row is derived from its client index, so
`./bin/setup` can run repeatedly without piling up duplicate clients. Set
`MONITOR_SEED_CLIENTS` lower for a faster setup, or higher for a load test.

## How the monitoring works

Three layers, described in full in [DECISIONS.md](DECISIONS.md#monitoring-runs-in-three-layers-scheduler-dispatcher-batched-jobs):

1. The **scheduler** container runs `monitor:dispatch` every fifteen minutes.
2. **`monitor:dispatch`** chunks the websites table and queues one
   `MonitorWebsiteBatchJob` per batch. It performs no HTTP itself.
3. The **queue** container runs the batches, checking each batch concurrently
   with `Http::pool()` and writing the results back.

Alerts go out only on a transition *into* down, not on every failed check, and
they ride a separate `alerts` queue that is drained ahead of `monitoring`.

## API

| Method | Path | Returns |
|---|---|---|
| `GET` | `/api/clients` | a page of clients, as `id` and `email`, plus a `meta` block |
| `GET` | `/api/clients/{client}/websites` | that client's websites, as `id` and `url` |
| `GET` | `/up` | Laravel health check |

`/api/clients` is paginated and searchable, because at hundreds of clients
neither a full response nor a `<select>` of every option is workable:

| Query | Default | Meaning |
|---|---|---|
| `search` | – | case-insensitive substring match on the email |
| `page` | `1` | page number |
| `per_page` | `50` | rows per page, capped at 100 |

`/api/clients/{client}/websites` is deliberately *not* paginated -- the brief
says a client has up to ten websites, so the list is short. Note that nothing in
the schema enforces that ten; it is a product rule, not a constraint. Neither
endpoint returns the monitored `status` -- see the note above.

## Tests

```bash
./bin/artisan test
```

The suite runs against an in-memory SQLite database with the queue and mailer
faked, so it needs no extra setup and cannot touch your development data or a
live service -- see [DECISIONS.md](DECISIONS.md#envtesting-as-a-separate-file).

## Working in the project

`bin/artisan` and `bin/shell` run inside the app container as `www-data`, which
is remapped to your host user so generated files stay editable. Use them rather
than `docker compose exec`, which gives you a root shell and leaves root-owned
files in the source tree.

```bash
./bin/artisan migrate            # any artisan command
./bin/shell                      # a shell in the app container
docker compose logs -f queue     # queue worker output
docker compose restart queue     # after editing a Job class
```

That last one matters: queue workers hold the application in memory and will not
pick up an edited job class until they are restarted.

Frontend assets are built once by `bin/setup`. For live reloading while working
on the Vue components, run Vite on the host with `npm run dev`.

## Configuration

`.env` is created from `.env.example` on first setup. The settings most worth
knowing:

| Variable | Default | Meaning |
|---|---|---|
| `MONITOR_TIMEOUT` | `10` | seconds before a check counts as down |
| `MONITOR_BATCH_SIZE` | `50` | websites checked concurrently per queued job |
| `MONITOR_JOB_TIMEOUT` | `120` | seconds a batch job may run before it is killed |
| `REDIS_QUEUE_RETRY_AFTER` | `180` | seconds before an unfinished job is retried; must exceed the job timeout |
| `MONITOR_SEED_CLIENTS` | `300` | generated clients the seeder creates |
| `MONITOR_SEED_MAX_WEBSITES` | `10` | most websites any generated client gets |
| `APP_PORT` | `8000` | host port for the dashboard |
| `MAILPIT_UI_PORT` | `8025` | host port for Mailpit |

`MONITOR_BATCH_SIZE` and `MONITOR_JOB_TIMEOUT` are linked -- a bigger batch means
a longer tail inside one job. See
[Sizing a cycle](DECISIONS.md#sizing-a-cycle) before changing either.

## Design notes

[DECISIONS.md](DECISIONS.md) records why the project is built the way it is --
the container layout, the queue split, the alerting rule, the accepted
limitations, and the trade-offs behind each.
