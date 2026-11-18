# Event Ticketing Platform

Multi-tenant event ticketing **API** (Laravel 12, PostgreSQL, Redis, stancl/tenancy v3, Sanctum, Pest).
Design and reasoning: [ARCHITECTURE.md](ARCHITECTURE.md). Rules for contributors: [CLAUDE.md](CLAUDE.md).

## Quick start

```bash
cp .env.example .env
docker compose up -d
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

| Service | URL / port (host) |
|---|---|
| API (nginx + php-fpm) | http://localhost:8088/api |
| Health check | http://localhost:8088/up |
| Mailpit (e-tickets inbox) | http://localhost:8026 |
| PostgreSQL | localhost:5433 (user `postgres`, password `secret`) |

Host ports are overridable with `NGINX_PORT`, `MAILPIT_UI_PORT`, `POSTGRES_PORT`.
Redis is only reachable inside the Docker network.

```bash
curl http://localhost:8088/api/events -H "X-Tenant-ID: sunsetfest"
```

## API at a glance

The full contract is in [docs/openapi.yaml](docs/openapi.yaml) (OpenAPI 3.1, every route, request fields and error codes; hand-written, check it parses with `python3 -c "import yaml; yaml.safe_load(open('docs/openapi.yaml'))"`). Import [docs/postman/event-ticketing.postman_collection.json](docs/postman/event-ticketing.postman_collection.json) for ready-made requests (every POST/PATCH lists all fields in its description). Every tenant request sends `X-Tenant-ID`; staff requests also send `Authorization: Bearer <token>`. Errors are JSON `{"error": "stable_code", "message": "..."}`.

| area | endpoints | who |
|---|---|---|
| sign-up | `POST /register`, `GET /register/{id}` | public (central) |
| auth | `POST /auth/login`, `POST /auth/logout` | staff |
| shop | `GET /events`, `GET /events/{slug}`, `POST /events/{slug}/checkout`, `GET /orders/{reference}` | public |
| payments | `POST /webhooks/payments/{tenant}` (HMAC signed) | payment provider |
| e-mail links | `GET /public/{tenant}/orders/{reference}/tickets`, `GET /public/{tenant}/tickets/{code}/qr` (signed URLs) | buyers |
| exports | `POST /admin/events/{event}/attendee-exports`, `GET /admin/attendee-exports/{id}`, `GET /public/{tenant}/attendee-exports/{id}/download` (signed URL) | admin |
| promo codes | `GET/POST /admin/promo-codes`, `GET/PATCH/DELETE /admin/promo-codes/{id}` | admin |
| door | `POST /admin/events/{event}/check-ins`, `GET /admin/events/{event}/check-ins/stats` | door staff, admin |
| admin | events, ticket types, orders, refunds, `/admin/settings`, `/admin/dashboard`, `/admin/reports/sales`, `/admin/reports/events/{id}`, `/admin/events/{id}/attendees` | admin |

`GET /api/admin/settings` shows the tenant's webhook URL and secret (the secret is also printed once at `tenant:provision`).

## Tests and quality gates

```bash
docker compose exec app composer test          # full Pest suite
docker compose exec app vendor/bin/pint        # code style
docker compose exec app vendor/bin/phpstan analyse   # Larastan level 6
```

Every test provisions a real tenant database. To keep that fast the suite builds one migrated template database
(`tenanttpl_<hash of the tenant migrations>`, rebuilt automatically when a migration changes) and each tenant database
is cloned from it with `CREATE DATABASE ... TEMPLATE` (`ticketing.tenant_template_database`; empty in production).
The full suite (about 440 tests) runs in about two minutes. Run suites one at a time: they share one test database.

## Tenants

```bash
# Self sign-up (central route, no X-Tenant-ID). Returns 202; a queued job provisions the tenant.
curl -X POST http://localhost:8088/api/register -H "Content-Type: application/json" -d '{
  "name":"Sunset Fest","tenant_id":"sunsetfest","currency":"MYR","timezone":"Asia/Kuala_Lumpur",
  "admin_name":"Aisha","admin_email":"aisha@sunsetfest.test","password":"correct-horse-battery"}'
curl http://localhost:8088/api/register/sunsetfest        # provisioning | active | failed

# Manual provisioning (same ProvisionTenant action, runs synchronously)
docker compose exec app php artisan tenant:provision sunsetfest "Sunset Fest" MYR Asia/Kuala_Lumpur aisha@sunsetfest.test
docker compose exec app php artisan tenant:purge sunsetfest   # frees a failed id
```

The `provisioning` queue needs a worker: `docker compose exec app php artisan queue:work --queue=provisioning,default`
(Horizon replaces this in a later phase).

## Authentication

```bash
curl -X POST http://localhost:8088/api/auth/login -H "X-Tenant-ID: sunsetfest" -H "Content-Type: application/json"   -d '{"email":"aisha@sunsetfest.test","password":"correct-horse-battery"}'
# -> {"data":{"token":"1|...","token_type":"Bearer","expires_at":"...","user":{...}}}
curl -X POST http://localhost:8088/api/auth/logout -H "X-Tenant-ID: sunsetfest" -H "Authorization: Bearer 1|..."
```

Tokens are Sanctum personal access tokens stored in the tenant database and bound to their tenant by an
explicit `tenant:{id}` ability. Roles: `admin` (everything) and `door_staff` (check-in only), enforced by Gates.

## Events and ticket types (admin)

All under `/api/admin`, admin role only, `X-Tenant-ID` + `Authorization: Bearer ...`.

| method | path | what |
|---|---|---|
| GET / POST | `/events` | list (paginated; `status`, `q`, `from`, `to`, `sort`, `direction`, `per_page`; rows carry `tickets_capacity` = sum of tier quantities) / create (draft; optional `capacity`, see below) |
| GET / PATCH / DELETE | `/events/{id}` | show with tiers / update / delete (blocked once anything sold or held) |
| POST | `/events/{id}/publish` | needs at least one ticket type |
| POST | `/events/{id}/cancel` | stops all sales |
| GET / POST | `/events/{id}/ticket-types` | tiers of an event / add one |
| GET / PATCH / DELETE | `/ticket-types/{id}` | show / update / delete (blocked once anything sold or held) |

**Event-level shared capacity.** `capacity` (optional, on create and PATCH, `null` = no limit) caps the tickets of ALL ticket
types of an event together: with two tiers of 50 and a capacity of 60, 60 tickets can be sold in total, then checkout answers
`409 event_sold_out` and every tier shows `sold_out`. The counter `events.reserved` (sold + held) is moved by the same atomic
conditional `UPDATE`s as the tier counters, and given back on expiry, failed payment and refund; a late payment finding the
event full becomes `refund_pending`. Lowering `capacity` below what is sold + held is `422`. Events and the storefront show
`capacity` and `capacity_remaining` (null without a limit). Capped events serialise their checkouts on the event row; events
without a capacity are unaffected (ARCHITECTURE section 2).

**Tier progression.** A ticket type may set `unlocks_after_ticket_type_id`: it opens only once that tier (same event, no
loops) is sold out (sold + held >= quantity) or its sales window has ended. The rule is inside the atomic hold `UPDATE`;
until then checkout answers `409 tier_locked`, the tier's `sales_status` is `locked` and the storefront shows
`unlocks_after` (`{id, name}`).

Prices are integer minor units (`5000` = RM 50.00). Times: any ISO-8601 value; one without an offset is read in the tenant timezone, and responses show the tenant timezone.

## Public shop (no login)

`X-Tenant-ID` only, throttled per tenant + IP (`TICKETING_STOREFRONT_RATE_LIMIT`, 120/min).

| method | path | what |
|---|---|---|
| GET | `/api/events` | published events that have not ended, soonest first; `q`, `from`, `to`, `page`, `per_page` |
| GET | `/api/events/{slug}` | one event with its ticket types: price, `available`, `max_per_order`, `sales_status` |

Drafts, cancelled and unknown events all return the same 404. Stock numbers (`sold`, `held`, `quantity`) are never exposed.

**Caching** (Redis, keys built with `TenantKey`): the page's descriptive data for 10 minutes, the sold + held
counters for 15 seconds, the list for 60 seconds. Sales status is derived at read time from the cached data plus
the clock, so a tier flips to `on_sale` on time. Any admin change to an event or ticket type bumps a per-tenant
version, which makes every cached page unreachable at once. The cache is display only: checkout decides stock in PostgreSQL.

## Checkout

`POST /api/events/{slug}/checkout` (public, `X-Tenant-ID`, optional `Idempotency-Key` header)

```bash
curl -X POST http://localhost:8088/api/events/sunset-fest/checkout -H "X-Tenant-ID: sunsetfest" \
  -H "Content-Type: application/json" -H "Idempotency-Key: attempt-0001" \
  -d '{"buyer_name":"Aisha","buyer_email":"aisha@example.com","items":[{"ticket_type_id":1,"quantity":2}]}'
# 201 -> {"data":{"reference":"ORD-...","status":"pending","total_minor":11050,"expires_at":"...", ...}}
```

The tickets are held for `TICKETING_HOLD_MINUTES` (10). Lines are held all-or-nothing inside one transaction, with
one atomic conditional `UPDATE` per ticket type (PostgreSQL decides if the stock is there; the cache is never used).
A repeated request with the same `Idempotency-Key` returns the same order (`200`, `Idempotent-Replayed: true`) and
holds nothing twice; the same key with a different body is `422 idempotency_key_reused`.
`GET /api/orders/{reference}` shows an order to whoever holds its reference.

Errors: `404` unknown/unpublished event, `409` `insufficient_stock` / `event_sold_out` / `tier_locked` / `sales_not_started` / `sales_ended` /
`event_not_on_sale` / `promo_code_exhausted`, `422` validation (also a ticket type of another event, or above max per order) /
`idempotency_key_reused` / `promo_code_invalid` / `promo_code_not_started` / `promo_code_expired`, `429` throttled.

### Promo codes

Optional `promo_code` in the checkout body (any letter case). A code takes a percentage (`percent_bps`, half-up integer maths)
or a fixed amount (`amount_minor`) off the **ticket subtotal**, never more than the subtotal. **Fees stay those of the
original ticket prices**, so `total = subtotal - discount + fees` (the spec example with a 10 % code: 149.97 - 15.00 + 10.00 =
144.97). The order shows `discount_minor` and `promo_code` (admin and buyer views); the webhook amount must be the discounted
total. One use is taken per order by one atomic conditional `UPDATE ... WHERE used < max_uses` inside the checkout transaction
(after the ticket types in the lock order), so two orders racing for the last use cannot both win. The use is given back when
the order expires or its payment fails.

Admin (`/api/admin/promo-codes`, admin role): `GET` list (paginated; `event_id`, `is_active`, `q`), `POST`, `GET/PATCH /{id}`,
`DELETE /{id}` (only while `used = 0`, else `409 promo_code_in_use`: PATCH `is_active=false`). A code can be limited to one
`event_id`, to `max_uses`, and to a `starts_at`..`ends_at` window (bare times are read in the tenant timezone).
The event report and the sales report show `discounts_minor` next to the (discounted) gross.

**Fees:** `fee = round-half-up(subtotal x fee_bps / 10000) + fee_fixed`, all integer maths in `App\Domain\Money\FeeCalculator`;
a free order (subtotal 0) has no fee. Example: 2 x 5000 -> subtotal 10000, fee 500 + 50, total 10550.

### Proving it does not oversell

```bash
docker compose exec app php artisan stress:checkout {tenant} {ticket_type_id} --requests=200 --concurrency=50
```

It sends real concurrent HTTP checkouts at one ticket type (default via the `nginx` container), prints the outcome
counts and then audits the database: `sold + held <= quantity`, `held` grew by exactly the orders created, and
`held` equals the tickets in pending orders. Example with a 50-ticket tier: 50 x `201`, 150 x `409 insufficient_stock`, all
invariants PASS. (Absolute req/s depends on the machine; Docker Desktop on Windows is the slow case.)

## Payments (webhook)

`POST /api/webhooks/{tenant}/payments` is called by the payment provider. It cannot send our header, so the tenant is
in the path, and the request is authenticated with `X-Signature` = hex HMAC-SHA256 of the **raw body**, keyed with the
webhook secret of the tenant **in the URL** (shown once by `tenant:provision`), compared in constant time, before the
body is read.

```json
{"id":"evt_123","type":"payment.succeeded","data":{"order_reference":"ORD-...","amount_minor":10550,"currency":"MYR"}}
```

| result | meaning |
|---|---|
| `200 {"status":"paid"}` | pending order paid, the hold became a sale |
| `200 {"status":"duplicate"}` | this event id was already processed (retry-safe, exactly once) |
| `200 {"status":"failed"}` | pending order failed, tickets released |
| `200 {"status":"paid_late"}` / `late_payment_no_stock` | the hold had already expired: honoured if stock is left, otherwise recorded for a refund |
| `422 amount_mismatch` | amount or currency differs from the order total: the order is NOT paid |
| `401 invalid_signature` | missing or wrong signature |

To play the provider locally:

```bash
docker compose exec app php artisan payments:simulate {tenant} {order_reference} succeeded   # or failed
# --event-id=evt_1 (reuse to see duplicate handling)   --amount=1 (see the mismatch guard)
```

After pulling new code: `php artisan migrate` (central) and `php artisan tenants:migrate` (every tenant database).

## Tickets and e-mail

When an order becomes paid (webhook, late payment or a free order) the same transaction issues one ticket
per seat (`tickets`, unique `(order_item_id, seq)`, random `code`) and claims the confirmation e-mail
(`order_notifications`, unique `(order_id, type)`). The job is dispatched `afterCommit` on the `mail` queue,
so nothing is sent for a rolled-back payment, and a duplicate webhook cannot send a second mail.

The e-mail holds the order summary, one inline QR code per ticket and a signed link:

- `GET /api/public/{tenant}/orders/{reference}/tickets`: tickets of a paid or refunded order, no login.
- `GET /api/public/{tenant}/tickets/{code}/qr`: PNG of one QR code.

Both links are signed and expire after `ticketing.ticket_link_days` (30). The tenant is in the signed path,
so a link cannot be replayed against another tenant. Mails are delivered by Horizon (`docker compose up -d`
starts it); open Mailpit at http://localhost:8026 to read them. A failed send is retried (10 s, 1 m, 5 m, 15 m),
then recorded as `failed` on the notification row.

## Check-in (door staff and admins)

`POST /api/admin/events/{event}/check-ins` with `{"code": "...", "gate_name": "Gate A"}` (`gate_name` optional).
The decision is one statement, `UPDATE tickets SET status='checked_in' ... WHERE code=? AND event_id=? AND status='valid'
... RETURNING`, so of two gates racing on one code exactly one wins; there is no read-then-write in PHP.
`GET /api/admin/events/{event}/check-ins/stats` (door staff and admins) returns total / checked_in / remaining per event
and per ticket type; `GET /api/admin/events/{event}/attendees?ticket_type_id=&status=&per_page=` (admins) is the
paginated attendee list.

| result | status | `error` |
|---|---|---|
| admitted | 200 | |
| unknown code, a ticket of another event, or another tenant's code | 404 | `ticket_not_found` |
| scanned before | 409 | `already_checked_in` (body has `checked_in_at`, `checked_in_by`, `gate_name`) |
| refunded | 409 | `ticket_void` |
| event cancelled | 409 | `event_cancelled` |

Codes are trimmed and upper-cased, so a hand-typed code works.
Proof under real concurrency: `php artisan stress:checkin demo-org <valid-code> door@demo.test password --requests=40`
sends 40 simultaneous scans of one ticket and checks that exactly one is admitted (the rest answer `already_checked_in`). Throttled per staff member
(`ticketing.rate_limits.checkin_per_minute`, 120).

## Orders and refunds (admin)

- `GET /api/admin/orders` (paginated, newest first): filters `event_id`, `status`, `email`, `reference`, `per_page`.
- `GET /api/admin/orders/{reference}`: items, tickets and the refund.
- `POST /api/admin/orders/{reference}/refund` `{"reason": "..."}`: full refund of a paid order.
- `POST /api/admin/events/{event}/refund-orders`: refunds every paid order of a **cancelled** event, one queued job per order (202).

A refund is one transaction (lock order event -> order -> ticket types -> tickets): the sold counters go back to
stock, every ticket becomes `void`, the order moves to `refunded` through the state machine, a `refunds` row is
written (UNIQUE `order_id`) and the buyer gets an e-mail. If any ticket was already scanned at the door the whole
refund is refused (`tickets_already_used`) and nothing changes. Not paid: `invalid_order_state`.

## Attendee CSV export (admin)

`POST /api/admin/events/{event}/attendee-exports` answers `202` with an export (`pending`) and queues a tenant job that streams
the event's tickets (ticket code, ticket type, status, checked_in_at, order reference, buyer name, buyer e-mail) to
`exports/{tenant id}/{uuid}.csv` on the `exports` disk (`storage/app/private`), then marks it `ready` and e-mails the requesting
admin a temporary signed download link (`TICKETING_EXPORT_LINK_MINUTES`, 30). `GET /api/admin/attendee-exports/{id}` shows the
status and, when ready, a fresh `download_url`; the link (`/api/public/{tenant}/attendee-exports/{id}/download`) needs no login,
returns `text/csv`, and answers `403 invalid_signature` when tampered or expired. A failure is recorded on the export
(`status=failed`, `error`). Throttled per staff member (`TICKETING_EXPORT_RATE_LIMIT`, 10/min).

## Dashboard and reports (admin)

All figures are SQL aggregates (`GROUP BY` / `SUM` / `COUNT ... FILTER`), integer minor units, cached per tenant for
`ticketing.cache.dashboard_ttl` (60 s) and dropped when an order is paid, refunded or expired. Pending orders and
holds can lag by up to the TTL.

- `GET /api/admin/dashboard`: gross sales **today** and **this month** (tenant timezone; orders paid in the window, plus the refunds made in it) and all-time, tickets **sold vs capacity per upcoming event** (next 10), **active holds** (pending orders whose hold has not expired, and their tickets), the **check-in rate today** (events running today, integer basis points), orders by status and the top 5 events.
- `GET /api/admin/reports/sales?from=YYYY-MM-DD&to=YYYY-MM-DD&page=&per_page=`: one row per day in the tenant timezone with orders, **tickets sold**, ticket revenue (prices only), gross (incl. fees), fees, refunds and net (default: last 30 days, at most 366; the day list is paginated, `totals` cover the whole range). **A refunded order counts as a sale on the day it was paid and as a refund on the day it was refunded** (your decision 6), so a day can have negative net.
- `GET /api/admin/reports/events/{event}` (alias `/admin/events/{event}/report`): per ticket type capacity, sold, held, remaining, revenue, refunded, checked in, awaiting, void, plus totals; paginated by ticket type.
- `GET /api/admin/events/{event}/attendees`: see Check-in.

## Load data (spec section 18)

`php artisan demo:seed-bulk alpha-events beta-tickets` (tenants must exist and be active; `tenant:provision` them first)
loads, per tenant, 40 events (past, live, upcoming, a few drafts/cancelled), 3-5 ticket types each, 30 000 orders in every
status, about 93 000 tickets, refunds, and check-ins for about 80 % of the tickets of past events. Options:
`--events=30..50`, `--orders=20000..50000`, `--seed`. It takes about 25-30 s per tenant because it writes in bulk: orders and
order lines in chunks of 1 000 rows; tickets, refunds, check-ins and the stock counters as set-based SQL in PostgreSQL. It does
not call the checkout actions (30 000 transactions would take far longer); instead `sold` and `held` are **recomputed from the
generated orders**, money comes from `FeeCalculator`, and `BulkSeedTest` checks that counters, tickets, fees and constraints
agree. Safe to run twice. Measured on a laptop with the data above (in-process, warm connection): 3-month sales report
about 90 ms, dashboard about 65 ms, per-event report 15 ms, storefront event detail 1 ms from cache (5 ms cold). The wall-clock
numbers through Docker on Windows add 150-300 ms of framework and bind-mount overhead on top.

## Demo data

`php artisan tenant:provision demo-org "Demo Org" MYR Asia/Kuala_Lumpur admin@demo.test --password=password`, then
`php artisan demo:seed demo-org --orders=60`. It creates 4 events (published, free + paid tiers, draft, cancelled), orders
in every state, tickets, refunds and check-ins, and a door-staff login (`door@demo.test` / `password`). It runs through
the real actions (`PlaceOrder`, `ProcessPaymentWebhook`, `RefundOrder`, `CheckInTicket`), in one transaction, so stock
counters, tickets and refunds stay consistent; only order timestamps are moved back over two weeks so the reports have
history. Running it twice does nothing the second time.

## Scheduled tasks

The `scheduler` container runs `schedule:work`. Each task only dispatches **one job per active tenant**
(tenants that are `provisioning` or `failed` are skipped), so tenants run in parallel and one broken tenant
cannot block the others. The per-tenant jobs are idempotent and run on the Horizon queue.

| task | every | what it does |
|---|---|---|
| `expire-pending-orders` | minute | Pending orders past `expires_at` become `expired` and their held tickets go back to stock (`ReleaseOrder`, one short transaction per order, lock order event -> order -> ticket types). Orders paid in the meantime are skipped after re-checking under the lock. |
| `queue-event-reminders` | hourly | Paid orders of published events starting within `ticketing.reminder_hours_before` (24 h) get one reminder e-mail. The `order_notifications` UNIQUE `(order_id, type)` claim makes repeated runs harmless. |

After a deploy run `php artisan horizon:terminate` so the workers pick up new code.

## Assumptions

- **A failed tenant id is not reusable through sign-up.** `POST /api/register` with a `failed` id returns 422,
  like any existing id. An operator frees it with `php artisan tenant:purge {id}`. Reusing it would force the
  public endpoint to delete a row and possibly drop a leftover database, which re-opens the
  "registration touches an existing tenant" risk.
- **Public status endpoint hides failure details.** `GET /api/register/{id}` returns only `failed` plus a
  generic message; the internal reason is kept in `tenants.failure_reason` for operators.
- **The hashed password travels in the job payload** (allowed by the spec) instead of being parked in the
  central database, so the central DB never holds credentials.
- **Sign-up rate limit:** 5 per minute and 20 per day per IP (`config/ticketing.php`), because each sign-up creates a database.

Ambiguities in the assignment are recorded here as they are resolved.

- **Platform PHP is pinned to 8.3** (`config.platform.php`) so dependencies resolve for the required
  PHP version even when the developer machine runs a newer one.
- **Dev database settings** (`fsync=off`, `synchronous_commit=off`) are used in `docker-compose.yml`
  to keep tests and the stress test fast. They are for local development only.
- **Token lifetime is 12 hours** (`SANCTUM_EXPIRATION_MINUTES`). Logout revokes only the token that was used.
- **Login is throttled** at 5/min per tenant + email + IP (`TICKETING_LOGIN_RATE_LIMIT`); the counter is tenant-scoped.
- **Events with sales cannot be deleted** (any ticket sold or held): cancel them instead. Once orders exist this widens to "has any orders".
- **The slug of a published event is frozen** because its public link would break. Drafts can change it.
- **A tier's sales window must end by the event end;** with no end set it stops selling when the event ends.
- **Quantity can shrink only down to sold + held** (checked atomically, backed by a CHECK constraint).
- **Cancelled events disappear from the public shop** (404, same as a draft). Their buyers are handled through orders and refunds, not the public page.
- **Availability on the public page may lag by up to 15 seconds** (`TICKETING_CACHE_AVAILABILITY_TTL`). A buyer who sees tickets left can still be told "sold out" at checkout; PostgreSQL is the source of truth.

- **A free ticket carries no fee** (the percentage and fixed fee are charged per paid ticket; a free order totals 0).
- **A `refund_pending` order is not counted as a sale** in reports (money was taken but no tickets exist); it counts as a refund once refunded.
- **Route paths differ from the PDF's suggestions** (`/events/{slug}/checkout`, `/public/{tenant}/...` signed URLs, per-event check-in under `/admin`); the PDF allows other paths. Behaviour and status codes follow the PDF.
- **Architecture and reasoning** are in [ARCHITECTURE.md](ARCHITECTURE.md).
- **Per-IP checkout limit is high (300/min) and the per-e-mail limit is strict (10/min)**, so offices behind one NAT can still buy and the stress test reaches the locking path.
- **Checkout of a cancelled/draft event answers 404**, an event that has ended answers `409 event_not_on_sale`.
- **Containers log to stderr** (`LOG_CHANNEL=stderr`): php-fpm and artisan run as different users and cannot share one log file.

- **A payment after `expires_at` but before the expiry job ran is a normal payment** (the order is still pending).
- **A payment after the job released the hold** is honoured only if the tickets are still free; otherwise the order stays expired and the notification is recorded as `late_payment_no_stock` for a refund.
- **An amount mismatch is answered 422 and never marks the order paid.** The event id is recorded, so the provider must send a corrected notification under a new id.
- **An unknown order reference is answered 200** (`unknown_order`) so the provider does not retry forever.
- **A free order (total 0) is paid immediately at checkout.**
- **Refunds are full and recorded, not paid out.** There is no payment-provider API in this assignment, so a refund
  records the amount (`refunds.amount_minor` = the order total, fee included) and returns the tickets; moving the
  money is out of scope. Partial refunds are not supported.
- **An order with a scanned ticket cannot be refunded** (`tickets_already_used`): the buyer already used part of it.
- **Cancelling an event does not refund by itself.** It stops sales at once; an admin then calls
  `POST /api/admin/events/{event}/refund-orders` to queue the refunds.
- **Check-in has no time window.** A valid ticket of a published event is accepted at any time; the scanner can pass
  `event_id` to refuse tickets of another event.
- **Reminders are sent once per order**, 24 hours before the event (`ticketing.reminder_hours_before`), to paid orders only.
- **Promo codes: a refund does not give the use back** (the buyer did use the code); expiry and a failed payment do. A late
  payment on an expired order takes the use again when the code still has one, otherwise the order keeps the price it was quoted
  (`used` never passes `max_uses`). A code applied to a free order (subtotal 0) still takes a use.
- **A promo discount never touches fees**: they are charged per ticket on the original price, so a 100 % code leaves the fees to pay.
- **Tier progression: "done" means sold + held >= quantity, or the sales window ended.** Holds count as gone, so a tier opens as soon
  as its predecessor is fully reserved; if those holds expire the successor locks again for new buyers (existing orders unaffected).
- **Event capacity serialises checkouts of that event** (row lock), and events without a capacity are unchanged. The admin list field
  that used to be `capacity` (sum of tier quantities) is now `tickets_capacity`; `capacity` is the event-level limit.
- **Export files are not deleted automatically**; only the signed link expires (30 minutes). A new export can be requested at any time.
- **Invalid or expired signed links answer `403 invalid_signature`** (they used to answer a generic `forbidden`; the status is unchanged).
- **Payment after `expires_at` but before the expiry task ran counts as a normal payment** (your decision 4): the expiry task re-checks the status under the lock.
