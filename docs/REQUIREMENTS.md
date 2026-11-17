# Event Ticketing Platform: original requirements

Source: "Pey Qi's Onboarding Assignment" (Lark doc). Discussion/start 16-11-2026, review 23-11-2026.
This is a transcription of the assignment so it lives next to the code. If something here disagrees with
`ARCHITECTURE.md` or `CLAUDE.md`, this file wins.

## Stack
Laravel 12+, REST/JSON API only (API Resources), Sanctum tokens for staff, Redis (cache, queue, locks, rate limits),
PostgreSQL (source of truth incl. inventory), stancl/tenancy v3 (database per tenant, one domain, tenant in the
`X-Tenant-ID` header), Pest. Bonus: Docker, Horizon.

## Overview
Multi-tenant ticketing platform. Tenant = event organizer with a slug id (e.g. `sunsetfest`), one API domain,
every request names its tenant in `X-Tenant-ID`. Organizers self-register, manage events, ticket tiers, orders,
tickets and door check-in. Buyers are guests (name + email). One currency and one timezone per tenant (timezone is
used for date boundaries in dashboard and reports). Strict isolation per tenant.
Focus: correctness (no overselling, no duplicate tickets/emails/check-ins, exact money), multi-tenancy safety,
maintainability, efficiency.

## 1. Sign-up, provisioning, authentication
- `POST /api/register` (central, no header): organizer name, tenant_id, currency, timezone, admin name, admin email,
  password. tenant_id: lowercase letters/digits/hyphens, 3-30 chars, unique, not reserved (api, admin, www, register,
  central). Creates tenant with status `provisioning`, queues the provisioning job, returns 202 with id and status.
- `GET /api/register/{tenant_id}`: provisioning / active / failed.
- Provisioning job: create DB, run tenant migrations, create first admin, generate webhook secret, mark `active`.
- Existing id -> 422 and the existing tenant is never touched. Racing sign-ups: exactly one wins, other gets clean 422.
  Plaintext password never in job payload or central DB. Retry-safe (no "database exists", no second admin).
  Permanent failure -> `failed` and drop the DB; whether a failed id is reusable is our call (Assumptions).
  Request for a provisioning/failed tenant -> 409. Sign-up rate-limited per IP.
- `php artisan tenant:provision {tenant_id} {name} {currency} {timezone} {admin_email}` shares the same Action.
- Login/logout with Sanctum. Roles: `admin` (everything in the tenant), `door_staff` (check in tickets, view check-in
  stats). Authorization through Policies/Gates.

## 2. Events (admin)
CRUD. Fields: title, slug (unique per tenant), description, venue_name, venue_address, starts_at, ends_at,
status draft/published/cancelled. ends_at > starts_at. Only published events on the storefront. Cancelling stops new
checkouts immediately; existing orders stay, refunds are manual. An event with paid orders cannot be deleted.
Timing phase (upcoming/live/ended) is computed from starts_at/ends_at, not stored.

## 3. Ticket types (admin)
CRUD under an event. Fields: name, price, quantity, sales_starts_at, sales_ends_at (<= event ends_at),
max_per_order (default 10), sort_order. quantity cannot go below sold + held. Orders snapshot prices. A tier with
sales cannot be deleted. Sales status computed: scheduled / on_sale / sold_out / ended.
available = quantity - sold - active holds.

## 4. Storefront (public)
List published upcoming events (paginated); event detail with ticket types (price, sales status, available).
Never exposes draft/cancelled events, internal fields or other tenants' data. If HTTP cache headers are sent they
include `Vary: X-Tenant-ID`.

## 5. Checkout and holds
1. Buyer submits name, email, items `[{ticket_type_id, quantity}]`.
2. Order created `pending`, inventory held 10 minutes (configurable).
3. Response: order reference, line items, fees, total, `expires_at`, `payment_reference`.
4. Payment confirmed by webhook, which issues tickets.
5. Unpaid by expires_at -> `expired`, held inventory released.

Pricing:
- line subtotal = unit_price x quantity
- booking fee **per ticket** = fee_percent of unit_price + fee_fixed, rounded **half-up** to the smallest unit
  (tenant settings, e.g. 5% + 0.50)
- order total = sum(line subtotals) + sum(line fees)
- amounts snapshotted on order and lines, never recomputed.

Worked example (MYR, 5% + 0.50 per ticket):

| Line | Unit | Qty | Subtotal | Fee per ticket | Line fees |
|---|---|---|---|---|---|
| GA | 45.00 | 2 | 90.00 | 2.25 + 0.50 = 2.75 | 5.50 |
| Early Bird | 19.99 | 3 | 59.97 | 0.9995 -> 1.00, + 0.50 = 1.50 | 4.50 |

Order total = 149.97 + 10.00 = **159.97**.

Rules: integer minor units (or DECIMAL with BCMath), never floats. MUST NOT oversell (sold + held <= quantity, also
concurrently). All-or-nothing. All items belong to one published event. 4xx with a clear error when: tier not on sale,
quantity < 1 or > max_per_order, not enough stock. `Idempotency-Key` header: same key returns the same order and no
second hold; same key with a different payload is rejected. PostgreSQL is the source of truth; flushing Redis must
never allow an oversell.

## 6. Payments (simulated webhook)
`POST /api/webhooks/payments/{tenant_id}`. Header `X-Signature` = hex HMAC-SHA256 of the raw body with the tenant's
webhook secret. Payload:

```json
{
  "id": "whk_01JD8X3K2Q",
  "type": "payment.succeeded",
  "payment_reference": "pay_7f3a9c",
  "amount": 15997,
  "currency": "MYR",
  "occurred_at": "2026-11-20T10:15:00Z"
}
```
`type` is `payment.succeeded` or `payment.failed`; `amount` in minor units.
Helper: `php artisan payments:simulate {tenant} {order_reference} {succeeded|failed}`.

| From | To | Trigger |
|---|---|---|
| pending | paid | payment.succeeded |
| pending | failed | payment.failed (inventory released) |
| pending | expired | hold expired (inventory released) |
| expired | paid | late payment.succeeded and stock still available |
| expired | refund_pending | late payment.succeeded but stock is gone: money taken, no tickets, organizer must refund |
| paid / refund_pending | refunded | admin refund |

Every other transition is invalid and rejected in one place.
Webhook rules: bad/missing signature -> 401, nothing changes; signed with tenant A's secret but sent to B's URL -> 401;
same webhook id twice -> processed once, repeat returns 200 with no side effects; amount/currency mismatch -> 422,
order unchanged, mismatch logged; succeeded for an already-paid order -> no-op; failed after succeeded -> ignored;
respond quickly (verify + persist in the request, queue slow work such as e-mail).

## 7. Tickets and delivery
One ticket per admission. Unique non-guessable code rendered as QR. Ticket statuses `valid` / `checked_in` / `void`.
E-ticket email with QR codes through the queue. Buyer views order and tickets through a signed URL with the tenant id
in the path (e.g. `/api/tenants/{tenant_id}/orders/{reference}?signature=...`).

## 8. Door check-in (admin, door_staff)
Scan a ticket code for a specific event. Valid ticket for this event -> `checked_in` with checked_in_at, staff user and
optional gate name. Already checked in -> 409 saying when and by whom. Void -> rejected. Ticket of another event or
another tenant -> rejected as not found. Two gates at the same moment -> exactly one succeeds.
Check-in stats per event: total tickets, checked in, remaining, broken down by ticket type.

## 9. Refunds (admin)
Full-order only; allowed for `paid` and `refund_pending`; rejected if any ticket is already checked in; voids all the
order's tickets; for paid orders inventory goes back to the ticket types. No real money moves: record refund + status.

## 10. Dashboard (admin)
Gross sales today and this month (tenant timezone); tickets sold vs capacity per upcoming event; active holds (pending
orders not yet expired); check-in rate for today's events. Tenant-scoped and cached.

## 11. Reports (admin)
Paginated, SQL-aggregated, tenant-timezone boundaries.
- Sales report (date range): per day number of orders, tickets sold, ticket revenue, fees, refunds.
- Event sales report: per ticket type quantity sold, held, remaining, plus revenue.
- Attendee list per event: filterable by ticket type and check-in status.

## Suggested API
POST /register, GET /register/{tenant_id}, POST /auth/login, POST /auth/logout, /admin/events (CRUD),
POST /admin/events/{event}/publish|cancel, /admin/events/{event}/ticket-types (CRUD), GET /events, GET /events/{slug},
POST /checkout (Idempotency-Key), GET /tenants/{tenant_id}/orders/{reference} (signed),
POST /webhooks/payments/{tenant_id}, POST /admin/events/{event}/check-ins, GET /admin/events/{event}/check-ins/stats,
POST /admin/orders/{order}/refund, GET /admin/dashboard, GET /admin/reports/sales,
GET /admin/reports/events/{event}, GET /admin/events/{event}/attendees. Paths may change, behaviour may not.

## 12. Multi-tenancy
stancl v3 database-per-tenant, one domain. Header only, no query-string fallback; webhook and e-mail links carry the
tenant in the path; central routes never initialise tenancy. Missing header -> 400, unknown tenant -> 404 (clean JSON).
A token from tenant A sent with tenant B's id is rejected. Central DB: tenants only. Isolation for Eloquent, cache
keys, raw Redis keys, queued jobs, scheduled tasks, webhook, file storage.

## 13. Concurrency and integrity
No overselling; no duplicate tickets (DB constraints); exactly one check-in per ticket; hold expiry, late webhooks and
refunds must not corrupt each other; DB transactions; justify the locking strategy in `ARCHITECTURE.md`.
Stress script: concurrent checkouts at one ticket type printing held + sold vs capacity. Reviewers fire 200 concurrent
checkouts at a type with quantity 50: exactly 50 held, every other request a clean 4xx, no 500s or deadlocks.

## 14. Queues, caching, rate limiting
E-mails async, exactly one confirmation per paid order, retry with backoff, permanent failures recorded. Cache the
storefront event detail and dashboard per tenant; invalidate on event/ticket-type changes and order created/paid/
expired/refunded; state the TTL; cache never decides a checkout. Rate-limit checkout per tenant, IP and email
(e.g. 10/min) and sign-ups per IP.

## 15. Scheduling
Every minute: expire pending orders past expires_at (safe against a late webhook). **Hourly**: remind buyers of paid
orders 24 h before the event, exactly once per order. Every active tenant in its own context, no overlap.

## 16. Testing
Required feature tests: registration (success, existing id, reserved/invalid id, retried partial provisioning, failure
cleanup, not-ready tenant), auth and roles, tenant isolation, tenant identification (400/404/cross-tenant token),
checkout (success, sold out, not on sale, > max_per_order, all-or-nothing), pricing incl. the worked example,
idempotency, webhook (bad signature, other tenant's secret, duplicate, amount mismatch, late payment with/without
stock), hold expiry, check-in (success, duplicate, void, wrong event), refund (success, rejected after check-in),
e-ticket queued exactly once, storefront list query count identical for 5 and 50 events.
Rules: model factories, refresh databases between tests, one documented command for the suite.

## 17. Performance
No N+1; indexes for FKs, order reference, ticket code, payment reference, webhook id, (status, expires_at), report date
ranges; availability in SQL or counters; SQL aggregation; every list paginated.
Targets: storefront detail warm < 100 ms, checkout p95 < 300 ms, 3-month sales report < 1 s, seeding one tenant < 3 min.

## 18. Seeding
At least 2 tenants; per tenant 30-50 events (past/live/upcoming), 3-5 ticket types per event, 20,000-50,000 orders
across all statuses, 40,000-100,000 tickets, check-ins for ~80% of tickets at past events. Chunked/bulk inserts.

## Out of scope
Frontend, real gateways/payouts, seat maps, buyer accounts/transfers/resale/waitlists, multi-currency per tenant, PDF
tickets, cross-tenant marketplace, sign-up verification/approval/billing. Ambiguities go under Assumptions in README.

## Evaluation
Core 100 (Multi-tenancy 20, Correctness 30, Maintainability 20, Efficiency 15, Testing 15); pass needs
Multi-tenancy >= 12, Correctness >= 18, Core >= 60, no critical failure. Bonus 0-20: promo codes +5, tier progression
+3, event-level shared capacity +3, refund/check-in race +2, queued CSV attendee export with signed link +2, Docker +2,
Larastan 6+ and Pint clean +2, OpenAPI +1.
Automatic fail: tenant leak (DB, cache, Redis, job, webhook, header switching); registration modifies an existing
tenant; overselling; duplicate tickets from one payment; double check-in; total != lines + fees or floats for money;
webhook accepted without a valid signature; job/scheduled task outside the right tenant context.

## Deliverables
Git repo with small commits; README (setup, tests, stress script, assumptions); ARCHITECTURE.md (tenancy layout,
overselling, idempotency, caching/invalidation, trade-offs); ERD/schema docs with indexes; Postman collection or
OpenAPI; seeders; stress script; `payments:simulate`.
