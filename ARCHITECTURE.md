# Architecture

Multi-tenant event ticketing API. This document explains **how it is put together and why**. The behaviour it has to
meet is in [docs/REQUIREMENTS.md](docs/REQUIREMENTS.md) (that file wins on any disagreement), the tables and indexes are in
[docs/schema.md](docs/schema.md), setup and assumptions are in the [README](README.md), and day-to-day rules for
contributors are in [CLAUDE.md](CLAUDE.md).

## 0. Stack

PHP 8.3, Laravel 12, PostgreSQL 16, Redis 7, stancl/tenancy v3 (database per tenant), Sanctum (API tokens),
Horizon (queue dashboard and supervisor), Pest, Larastan (level 6) and Pint, Mailpit for e-mail in development,
Docker Compose for everything. Runtime packages beyond the framework: `stancl/tenancy`, `laravel/sanctum`,
`laravel/horizon`, `endroid/qr-code` (QR images for tickets). No frontend: Blade is used only for e-mail templates.

## 1. Tenancy layout

```
                    one API domain  ->  /api/...
   X-Tenant-ID: sunsetfest                         path: /webhooks/payments/sunsetfest
          |                                         path: /public/sunsetfest/orders/...  (signed)
          v                                                |
  IdentifyTenantByHeader                          IdentifyTenantByPath
  (stancl request-data, header only)              (stancl path identification)
          \_____________________  _________________________/
                                \/
                  EnsureTenantIsActive  (provisioning/failed -> 409)
                                |
                  auth:sanctum + EnsureTokenBelongsToTenant
                                |
                 tenant database `tenant_sunsetfest`
```

- **Central database `ticketing`** holds only the `tenants` table (id slug, name, status, currency, timezone, fee settings,
  encrypted webhook secret) plus Laravel's own `failed_jobs`/`migrations`. It exists because something has to answer "is this
  tenant real, active, and which database is it?" before any tenant database can be opened.
- **One PostgreSQL database per tenant** (`tenant_<id>`) holds everything else: users, tokens, events, ticket types, orders,
  tickets, refunds, webhook events, notifications. A query can only ever see its own tenant's rows because it is connected to
  that tenant's database. There is no `tenant_id` column to forget in a `WHERE`.
- **Identification is explicit**: the header selects a tenant; it never grants access. Missing header -> 400, unknown tenant
  -> 404, tenant not `active` -> 409, all as JSON. There is no query-string or subdomain fallback. Callers that cannot send
  headers (the payment gateway, links in e-mails) carry the tenant in the URL path.
- **Header switching is harmless**: Sanctum tokens live in the tenant database, so a token issued by tenant A is simply not found
  in tenant B's database, and `EnsureTokenBelongsToTenant` rejects it explicitly as a second guard. Middleware priority is set
  in `bootstrap/app.php` so identification always runs before `auth:sanctum`.
- **Central routes** (`POST /register`, `GET /register/{id}`) never initialise tenancy.
- **Redis** is shared. `RedisTenancyBootstrapper` is *not* enabled; instead every cache key, lock and rate-limiter key is
  built with `App\Support\TenantKey` (`t:<tenant>:...`, central: `c:...`), which throws outside tenant context rather than
  produce an un-namespaced key. A grep audit for raw keys is part of the review checklist.
- **Queued jobs** take ids (the tenant id and the order/event id), never models, and re-enter the tenant with the
  `RunsInTenant` trait. `ProvisionTenantJob` is the only central job.
- **Scheduled tasks** (`routes/console.php`) fan out one job per *active* tenant (`DispatchForActiveTenants`), so each task runs
  in its own tenant context; `withoutOverlapping` + `onOneServer` stop a task overlapping itself.
- **File storage**: QR codes are generated on demand from the ticket code and are not stored.

### Provisioning (sign-up)

`POST /register` and `php artisan tenant:provision` both call the same `ProvisionTenant` action.

1. The request validates input, **hashes the password before it leaves the request** and inserts the `tenants` row with status
   `provisioning`. The primary key on the slug is what makes two racing sign-ups safe: exactly one INSERT wins, the other gets
   a clean 422. It never uses `updateOrCreate`/`firstOrCreate` on `tenants`, so registration cannot modify an existing tenant.
2. `ProvisionTenantJob` (queued, central) calls `ProvisionTenant::run`. Every step is *ensure*, not *create*: create the database
   if missing (cloned from a template in tests), run tenant migrations, `firstOrCreate` the admin on `UNIQUE(email)`, generate
   the webhook secret once, mark `active`. A retry after a partial run therefore never fails on "database exists" and never
   creates a second admin. A cache lock only stops two workers doing the same work at the same time; the database state is the
   real guard.
3. A permanent failure marks the tenant `failed` and drops its database, so nothing is orphaned. **A failed id is not reusable**
   through sign-up (decision recorded in the README): the row stays as a tombstone and an operator can clear it.

## 2. Preventing overselling

This is the part that matters most, so it is deliberately boring.

**Rule: `ticket_types.sold` and `held` change only through one atomic, conditional SQL `UPDATE`** (`App\Domain\Inventory\TicketStock`).
Nothing is ever read into PHP, compared, and written back.

```sql
UPDATE ticket_types tt SET held = tt.held + :qty
  FROM events e
 WHERE tt.id = :tier AND tt.event_id = :event AND e.id = tt.event_id
   AND tt.sold + tt.held + :qty <= tt.quantity          -- the stock check
   AND :qty <= tt.max_per_order
   AND (tt.sales_start_at IS NULL OR tt.sales_start_at <= :now)
   AND COALESCE(tt.sales_end_at, e.ends_at) > :now      -- the sales window
RETURNING tt.id, tt.name, tt.price_minor;                -- price snapshot for the order
```

Why this is safe: PostgreSQL takes a row lock for the `UPDATE`, re-evaluates the `WHERE` after waiting for any concurrent writer
to commit, and either changes the row or matches nothing. Two buyers racing for the last ticket are serialised on that row:
the first gets `held + 1`, the second finds `sold + held + 1 > quantity`, matches zero rows and receives a clean 409
`insufficient_stock`. There is no window between "check" and "write".

**All or nothing.** A checkout holds each line with one such statement inside **one transaction**; if any line fails the
transaction aborts and every earlier hold of that checkout rolls back with it, and no order row survives.

**Defence in depth.** `CHECK (sold + held <= quantity)` (and non-negative checks) are on the table. Even a buggy code path cannot
persist an oversold state; the worst a bug can do is fail loudly. (The bulk seeder and its tests assert this invariant too.)

**State changes of the counters** (all in `TicketStock`, each one conditional `UPDATE`):

| operation | when | effect |
|---|---|---|
| `hold` | checkout | `held += q` if it fits |
| `commitHold` | payment succeeded | `held -= q, sold += q` (`sold + held` unchanged, so the CHECK always holds) |
| `release` | payment failed / hold expired | `held -= q` |
| `sellWithoutHold` | late payment on an expired order | `sold += q` only if it still fits; otherwise the order becomes `refund_pending` |
| `returnSold` | refund of a paid order | `sold -= q` |

**Why not the alternatives.** `SELECT ... FOR UPDATE` then check in PHP would also work but holds locks across application
round trips and invites deadlocks; Redis counters or locks would make Redis a second source of truth that can disagree after a
flush. PostgreSQL is the only source of truth: **flushing Redis can never make an oversell possible**, because no stock decision
reads the cache. The storefront shows availability from a short-lived cache (see section 7) but a checkout never consults it.

**Deadlock avoidance.** A single global lock order is used by every code path:
`event -> order -> ticket_types (ascending id) -> tickets (ascending id)`.
Checkout, the webhook, the expiry job and refunds all lock in that order (the event row is taken `FOR SHARE`, so any number of
checkouts run in parallel while cancelling the event, which takes it `FOR UPDATE`, waits for them). `DB::transaction($cb, 3)`
retries on the rare serialization or deadlock error.

**Proof.** `php artisan stress:checkout {tenant} {ticket_type} --requests=200 --concurrency=50` fires concurrent real HTTP
checkouts at one ticket type and prints held + sold against capacity and the status histogram. With quantity 50 and 200
requests the result is exactly 50 held, every other request a 409, no 500s, and `sold + held == quantity`.
`tests/Feature/Checkout` also covers the primitives directly (it never hands out more than the quantity).

## 3. Other races (hold expiry, late webhooks, refunds, check-in)

| race | how it is made safe |
|---|---|
| webhook vs expiry job | Both lock `event (shared) -> order (FOR UPDATE)`, then **re-check the status under the lock**. `OrderStateMachine` writes the transition as `UPDATE ... WHERE status = <expected>`, so the loser changes 0 rows and is told the move is invalid. A payment that arrives after `expires_at` but before the job ran is treated as a normal payment (pending -> paid). |
| late payment after expiry | `expired -> paid` if `sellWithoutHold` succeeds for every line (all or nothing; lines already sold are returned if one fails), otherwise `expired -> refund_pending`: money taken, no tickets, the organizer must refund. |
| duplicate webhook delivery | `webhook_events.event_id` is `UNIQUE`; the row is inserted first, in the same transaction as the effect. A repeat finds it and returns 200 with no side effects. If anything fails the row rolls back with the effect, so the provider's retry is processed normally. |
| two gates scan one ticket | One statement: `UPDATE tickets SET status='checked_in' ... WHERE code=? AND event_id=? AND status='valid' RETURNING id`. The second scanner blocks on the row lock, re-evaluates `status='valid'` after the first commits and gets no row. Only then does it look the ticket up, to explain why (404 / 409 with when and by whom). |
| refund vs check-in | Refund voids tickets with `UPDATE ... WHERE status='valid'` and compares the voided count with the number of seats; if a scan won the race, fewer rows are voided and the whole refund aborts with `tickets_already_used`. Check-in uses the same row lock, so they can never both succeed. |
| refund twice | `refunds.order_id` is `UNIQUE`, and the status move `paid/refund_pending -> refunded` is conditional. |
| one payment, duplicate tickets | `UNIQUE (order_item_id, seq)`; issuing is idempotent and the whole fulfilment runs in the payment transaction. |

## 4. Order state machine

`App\Domain\Orders\OrderStateMachine::transition()` is the **only** code that writes `orders.status` (the model also refuses
other writes to the column; the initial `pending` is written by `begin()`). The table of allowed moves lives in one constant:

```
pending  -> paid | failed | expired
expired  -> paid (late payment, stock available) | refund_pending (late payment, stock gone)
paid | refund_pending -> refunded
failed, refunded -> (final)
```

Every other transition throws `InvalidOrderTransition`; a DB `CHECK` restricts the stored values. Each target status stamps its
own timestamp column (`paid_at`, `expired_at`, `refund_pending_at`, ...).

## 5. Idempotency strategy

| where | key | mechanism |
|---|---|---|
| checkout | `Idempotency-Key` header | `orders.idempotency_key` is `UNIQUE` (NULLs do not collide) and the request body is hashed into `request_fingerprint`. Same key + same body returns the same order (header `Idempotent-Replayed: true`) and makes no second hold; same key + different body is a 422. If two retries race, the loser's transaction (holds included) rolls back on the unique violation and it replays the winner's order. |
| payment webhook | provider's `id` | `webhook_events.event_id` `UNIQUE`, see section 3. Also `succeeded` on an already-paid order is a no-op and `failed` after `succeeded` is ignored. |
| tickets | `(order_item_id, seq)` | `UNIQUE` |
| e-mail | `(order_id, type)` | the `order_notifications` row is **claimed first** (`UNIQUE`), then the job is dispatched `afterCommit`. A job that runs twice, or a webhook delivered twice, can only send once. |
| refunds | `order_id` | `UNIQUE` |
| registration | tenant slug | primary key |

The rule of thumb: uniqueness is enforced by a database constraint, never only by an application check.

## 6. Money

Integer minor units everywhere (`bigint`); no floats, no `round()`. `App\Domain\Money\FeeCalculator` is the only place fee maths
happens. The booking fee is **per ticket**: `intdiv(unit_price * bps + 5000, 10000) + fixed` (half-up as integer maths), a free
ticket has no fee. A line's fee is the ticket fee times the quantity; the order total is the sum of lines plus fees. The
spec's worked example (2 x 45.00 and 3 x 19.99 at 5% + 0.50) totals 159.97 and is a test. Orders and lines **snapshot** price,
fee and tenant fee settings and are never recomputed. `CHECK total = subtotal + fee` and the per-line checks keep every order
consistent in the database too.

## 7. Caching and invalidation

| cache | key (always `TenantKey`) | TTL | invalidated by |
|---|---|---|---|
| storefront event list | `storefront:v<n>:list:...` | 60 s | version bump |
| storefront event detail (static part) | `storefront:v<n>:event:...` | 10 min | version bump |
| availability counters on the detail | `storefront:v<n>:...:availability` | 15 s | version bump |
| dashboard, sales and event reports | `storefront:v<n>:dashboard` ... | 60 s | version bump |

Invalidation is a **version bump** per tenant: every key contains the tenant's current version, so one `INCR` makes all old
entries unreachable at once (they expire on their own TTL). It works on any driver without cache tags. The bump runs in
`DB::afterCommit`, so a concurrent reader cannot re-cache pre-commit data under the new version. It is triggered by event and
ticket-type changes and by orders being created, paid, failed, expired or refunded.

**Availability on the storefront may be stale for at most 15 seconds.** It is display-only: checkout decides in PostgreSQL.
HTTP responses for tenant data carry `Vary: X-Tenant-ID`. The storefront list is built with a fixed number of queries
(eager aggregation), checked by a test comparing 5 and 50 events.

## 8. Queues and e-mail

Redis queues supervised by Horizon. Confirmation e-mails (with QR codes), reminders and refund notices go through
`SendOrderEmailJob`: it loads by id, runs in the tenant, retries with backoff (10 s, 1 min, 5 min, 15 min) and records permanent
failures on the notification row without blocking other jobs. The buyer opens their order and tickets through **signed,
expiring URLs** that carry the tenant in the path (`/api/public/{tenant}/orders/{reference}/tickets`), because an e-mail link
cannot send a header; the signature covers the tenant segment, so a link cannot be replayed against another tenant.

## 9. Scheduling

| task | cadence | what it does |
|---|---|---|
| `expire-pending-orders` | every minute | per active tenant: each overdue pending order is its own short transaction (lock order, re-check under lock, release stock, `pending -> expired`); batches of 200; failures are reported and skipped |
| `queue-event-reminders` | hourly | per active tenant: paid orders of published events starting within 24 h get one reminder; the `order_notifications` claim makes repeats harmless |

Both are idempotent, so an overlap or a missed run is harmless.

## 10. Rate limiting (Redis, per-tenant keys)

Checkout: per tenant + IP (high, so the 200-request stress test reaches the locking path) and per tenant + e-mail (strict, 10
per minute). Sign-up: per IP per minute and per day (each one creates a database). Also login, storefront reads, the webhook and
check-in (per staff member). Limits live in `config/ticketing.php`.

## 11. Efficiency

- Reports and the dashboard are SQL aggregates (`GROUP BY`, `SUM`, `COUNT ... FILTER`), a fixed number of queries whatever the
  data size, cached for 60 s, in the tenant timezone. Lists are paginated; the report day series and per-type lists are paged
  while totals cover the whole range.
- Indexes follow the queries and are justified one by one in [docs/schema.md](docs/schema.md): reference, ticket code, payment
  reference, webhook id, a partial index for the expiry job (`expires_at WHERE status='pending'`), `paid_at` for report ranges,
  composite indexes for the attendee list and check-in counts.
- Measured with the bulk seed (2 tenants x 40 events x 30 000 orders x ~93 000 tickets), in-process: 3-month sales report
  ~90 ms, dashboard ~65 ms, per-event report ~15 ms, storefront detail 1 ms warm (target < 100 ms).
- `demo:seed-bulk` writes in chunks and set-based SQL (about 27 s per tenant, target < 3 min).

## 12. Trade-offs and what I would change

- **Database per tenant** gives hard isolation and cheap per-tenant backup/drop, at the cost of operational weight: a migration
  must run N times, and cross-tenant analytics need a separate path. Fine for hundreds of tenants, not for hundreds of thousands.
- **Version-bump caching** wastes a little memory (old entries live until their TTL) in exchange for O(1) invalidation and no
  dependency on cache tags.
- **Atomic conditional UPDATE over Redis locks**: slightly more load on hot rows during a flash sale (they serialise), but no
  second source of truth and no lock-expiry edge cases. If one tier became hot enough to matter, the next step would be
  sharding the counter across several rows, not moving it into Redis.
- **Full refunds only, recorded not paid**: matches the scope (no real money moves); partial refunds would need per-line
  accounting and a different stock rule.
- **Bulk seeder bypasses the actions** for speed and recomputes counters instead; its invariants are covered by tests.
- **Reminders run hourly**, so a reminder can arrive up to an hour after the 24 h mark.
