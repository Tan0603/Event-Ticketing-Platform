# PLAN.md — Phase-by-phase build plan

> Source of truth: the assignment PDF (to be copied into `docs/REQUIREMENTS.md`), then
> `ARCHITECTURE.md`, then `CLAUDE.md`. Section numbers below like **[R5]** point to the
> requirement section; **[A7]** points to the ARCHITECTURE section.
>
> Timeline: start 16-11-2026 · review **23-11-2026**. Core first, bonus only once core is green.

---

## How to use this file

* Do phases **in order**. Don't start a phase until the previous phase's tests pass.
* Each phase lists: **Goal → Build → Rules to honour → Tests → Done when → Commits**.
* Tick `[ ]` → `[x]` as you go and commit this file with the work.
* Every phase ends with `composer test`, `vendor/bin/pint`, `vendor/bin/phpstan analyse` clean.

## Scoring map (what each phase earns)

| Score area | Points | Phases that earn it |
|---|---|---|
| Multi-Tenancy | 20 (pass ≥ 12) | 1, 2, 3, 5, 7, 9 |
| Correctness | 30 (pass ≥ 18) | 6, 7, 8, 9, 10, 11 |
| Maintainability | 20 | all (Actions, Policies, Enums, config, commits, docs) |
| Efficiency | 15 | 5, 12, 13 |
| Testing | 15 | every phase |
| Bonus | 0–20 | 15 |

**Automatic fail conditions** — check these at the end of every phase:
tenant data leak · registration changes an existing tenant · oversell · duplicate tickets ·
double check-in · total ≠ lines + fees or floats for money · webhook accepted without valid
signature · job / scheduled task outside tenant context.

---

## Suggested schedule

| Day | Date | Phases |
|---|---|---|
| 1 | Tue 17-11 | 0, 1, 2 |
| 2 | Wed 18-11 | 3, 4, 5 |
| 3 | Thu 19-11 | 6 (checkout + stress), 7 |
| 4 | Fri 20-11 | 8, 9, 10, 11 |
| 5 | Sat 21-11 | 12, 13 |
| 6 | Sun 22-11 | 14, bonus (15) |
| 7 | Mon 23-11 | Buffer, final run-through, **review** |

---

## Phase 0 — Environment & scaffold

**Goal:** an empty Laravel 12 API that boots in Docker and runs an empty Pest suite.

**Build**
- [ ] Laravel 12 skeleton merged into the existing folder skeleton (API only — remove Vite/`package.json`/welcome view)
- [ ] `composer require stancl/tenancy:^3.9 laravel/sanctum:^4.0 laravel/horizon endroid/qr-code`
- [ ] `composer require --dev pestphp/pest:^3.0 pestphp/pest-plugin-laravel larastan/larastan laravel/pint`
- [ ] `docker-compose.yml`: `app` (php-fpm 8.3 + pgsql, redis, bcmath, gd), `nginx`, `postgres:16`, `redis:7`, `horizon`, `scheduler`, `mailpit` (1025 / 8025)
- [ ] php-fpm `pm.max_children ≈ 40`; Postgres `max_connections` > FPM workers + Horizon workers
- [ ] `.env.example`: pgsql, redis cache/queue, `MAIL_HOST=mailpit`, `MAIL_PORT=1025`
- [ ] `config/ticketing.php` (hold_minutes=10, reserved_tenant_ids, cache TTLs, rate limits)
- [ ] `config/currencies.php` (MYR 2, SGD 2, IDR 2, …)
- [ ] `composer test` script, `pint.json`, `phpstan.neon` (level 6)
- [ ] `.github/workflows/ci.yml`: pint → phpstan → pest (Postgres + Redis services)
- [ ] Copy assignment PDF content into `docs/REQUIREMENTS.md`; add `ARCHITECTURE.md` + `CLAUDE.md` to repo

**Done when:** `docker compose up -d` is healthy, `GET /up` → 200, `composer test` green (0 tests ok), Pint + Larastan clean.

**Commits:** `chore: scaffold laravel 12 api` · `chore(docker): compose environment` · `chore: add pest, pint, larastan` · `docs: add requirements, architecture, claude rules`

---

## Phase 1 — Tenancy foundation [R12] [A3]

**Goal:** tenant identified safely by header or path; central vs tenant DB split works.

**Build**
- [ ] Central migration `tenants` (id varchar(30) PK, name, status CHECK, currency char(3), timezone, fee_percent_bps, fee_fixed_minor, webhook_secret encrypted, failure_reason, data jsonb)
- [ ] `Models/Central/Tenant` (stancl `HasDatabase`), DB name `tenant_{id}` with `-` → `_`
- [ ] `config/tenancy.php`: Database, Cache, Queue, Filesystem bootstrappers (**not** Redis bootstrapper)
- [ ] Enums: `TenantStatus`
- [ ] Middleware: `IdentifyTenantByHeader` (`X-Tenant-ID`, query param **null**), `IdentifyTenantByPath`, `EnsureTenantIsActive`
- [ ] JSON failure mapping: missing header 400 `tenant_header_missing` · unknown 404 `tenant_not_found` · provisioning/failed 409 `tenant_not_ready`
- [ ] `bootstrap/app.php`: tenancy middleware in priority **before** `auth:sanctum`
- [ ] Routes split: `routes/central.php`, `routes/api.php` (header), `routes/tenant_path.php` (path)
- [ ] `Support/TenantKey` for raw Redis / lock / rate-limit keys
- [ ] Global JSON exception handler (never HTML, never 500 for client errors)
- [ ] `tests/Support/CreatesTenants` trait — create + drop tenant DBs reliably; sweep `tenant_test_*` before the run

**Tests:** missing header → 400 · unknown tenant → 404 · provisioning tenant → 409 · query-string `?tenant=` ignored · TenantKey prefixes keys.

**Done when:** a dummy tenant route returns data from the correct tenant DB only.

**Commits:** `feat(tenancy): central tenants table and model` · `feat(tenancy): header and path identification` · `feat(tenancy): json errors for tenant resolution` · `test(tenancy): identification edge cases`

---

## Phase 2 — Sign-up & provisioning [R1] [A4]

**Goal:** self-registration and the artisan command create a fully isolated tenant, safely and idempotently.

**Build**
- [ ] `RegisterTenantRequest`: tenant_id regex `^[a-z0-9][a-z0-9-]{1,28}[a-z0-9]$`, not reserved, `unique:tenants,id`; currency, timezone (`timezone:all`), admin name/email, password
- [ ] `Actions/Tenancy/RegisterTenant`: `Hash::make` in request → plain `INSERT` (status `provisioning`) → catch PK violation → 422 → dispatch job → **202**
- [ ] `Jobs/ProvisionTenantJob` (central, tries 3, backoff, ShouldBeUnique by id) → calls `ProvisionTenant`
- [ ] `Actions/Tenancy/ProvisionTenant`: lock → skip if active → create DB (ignore "already exists") → migrate → `User::firstOrCreate` admin → webhook secret `??=` → status `active`
- [ ] Job `failed()`: status `failed`, failure_reason, `DROP DATABASE IF EXISTS`
- [ ] `GET /api/register/{tenant_id}` → status
- [ ] `tenant:provision {tenant_id} {name} {currency} {timezone} {admin_email}` — same Action, synchronous, prints admin password + webhook secret
- [ ] `tenant:purge {id}` (operator frees a failed id)
- [ ] Rate limiter `register`: 5/min + 20/day per IP
- [ ] Tenant migrations: `users` (role CHECK), `personal_access_tokens`

**Rules:** never `updateOrCreate`/`firstOrCreate` on `tenants` · plaintext password never in job payload or central DB · failed id not reusable via API (Assumption).

**Tests:** success (202 → active) · existing id → 422 and tenant untouched (no new admin, password unchanged) · reserved / invalid id → 422 · racing duplicate → one 202 + one 422 · job retried after partial run (DB exists, admin exists) → succeeds, still one admin · permanent failure → `failed` + DB dropped · request to not-ready tenant → 409 · job payload contains no plaintext · rate limit → 429 · artisan command uses the same Action.

**Commits:** `feat(tenancy): register endpoint` · `feat(tenancy): idempotent provisioning action and job` · `feat(tenancy): tenant:provision command` · `test(tenancy): registration and provisioning`

---

## Phase 3 — Authentication & roles [R1]

**Goal:** staff log in per tenant; tokens never cross tenants; roles enforced by Policies.

**Build**
- [ ] Enum `UserRole` (admin, door_staff)
- [ ] `POST /auth/login` (token with ability `tenant:{id}`), `POST /auth/logout`
- [ ] `EnsureTokenMatchesTenant` middleware (defence in depth)
- [ ] Sanctum stateful/cookie guard disabled
- [ ] Policies: `EventPolicy`, `TicketTypePolicy`, `OrderPolicy`, `CheckInPolicy`; route `can:` middleware
- [ ] Login rate limit `t:{tenant}:login:{email}:{ip}` 5/min
- [ ] `UserFactory` with `admin()` / `doorStaff()` states

**Tests:** login ok / wrong password 422 · logout revokes token · **token from tenant A + `X-Tenant-ID: B` → 401** · door_staff cannot create events (403) · door_staff can reach check-in routes.

**Commits:** `feat(auth): sanctum token login and logout` · `feat(auth): role policies` · `test(auth): roles and cross-tenant token`

---

## Phase 4 — Events & ticket types (admin) [R2] [R3] [A5] [A6]

**Goal:** admins manage events and tiers; money is integer minor units from day one.

**Build**
- [ ] Tenant migrations `events` (UNIQUE slug, CHECK ends>starts, INDEX(status, starts_at)), `ticket_types` (sold, held, **CHECK sold+held ≤ quantity**, price ≥ 0, max_per_order ≥ 1, INDEX(event_id, sort_order))
- [ ] Enums `EventStatus`, `SalesStatus`, `EventPhase`
- [ ] `Domain/Money/Money`, `CurrencyCatalog`, decimal-string price parser (no float)
- [ ] Event CRUD + publish + cancel Actions; computed `phase` (upcoming / live / ended)
- [ ] Delete event blocked if it has orders (FK RESTRICT) → 409/422 "cancel it instead"
- [ ] Ticket type CRUD; `sales_ends_at ≤ event.ends_at`; reduce quantity via `UPDATE … WHERE sold + held <= :new`; delete blocked if it has sales
- [ ] Computed sales status: scheduled / on_sale / sold_out / ended; available = quantity − sold − held
- [ ] Resources return money as `{amount, currency, display}`
- [ ] Factories: `EventFactory`, `TicketTypeFactory`
- [ ] Paginated admin lists

**Tests:** CRUD happy path · ends_at ≤ starts_at → 422 · slug unique per tenant (same slug allowed in another tenant) · quantity below sold+held → 422 · delete tier with sales rejected · delete event with paid orders rejected · price `"19.99"` → 1999 · **tenant A cannot read/modify tenant B events**.

**Commits:** `feat(events): schema and crud` · `feat(events): publish and cancel` · `feat(ticket-types): crud with inventory guards` · `feat(money): money value object and price parsing`

---

## Phase 5 — Storefront API + caching [R4] [R14] [A12]

**Goal:** public, fast, tenant-safe event listing and detail.

**Build**
- [ ] `GET /events` — published + upcoming, paginated, eager-loaded tiers (fixed query count)
- [ ] `GET /events/{slug}` — tiers with price, sales status, available quantity
- [ ] Public Resources expose no internal fields (no sold/held raw, no draft/cancelled)
- [ ] `Support/StorefrontCache`: static part `sf:event:{slug}:v{ver}` (10 min) + availability `sf:avail:{eventId}` (15 s), list `sf:events:v{ver}:p{page}`; `Cache::flexible`
- [ ] Invalidate (after commit) on event / tier changes
- [ ] Headers `Cache-Control: public, max-age=15` + **`Vary: X-Tenant-ID`**

**Tests:** draft/cancelled hidden · **query count same for 5 vs 50 events** · Vary header present · cache keys isolated per tenant (same slug in A and B returns different data) · cache invalidated after tier update.

**Commits:** `feat(storefront): event list and detail` · `perf(storefront): tenant-scoped caching with versioned keys` · `test(storefront): query count and isolation`

---

## Phase 6 — Checkout & reservation holds ★ [R5] [R13] [A7] [A8]

**Goal:** no oversell under 200 concurrent requests; exact money; idempotent.

**Build**
- [ ] Tenant migrations `orders` (UNIQUE reference, payment_reference, idempotency_key; CHECK total = subtotal + fees; partial INDEX(expires_at) WHERE pending; INDEX(paid_at); INDEX(event_id, status)), `order_items` (UNIQUE(order_id, ticket_type_id); line CHECKs)
- [ ] Enum `OrderStatus` with `allowedTransitions()`
- [ ] `Domain/Money/FeeCalculator` — `intdiv(price * bps + 5000, 10000) + fixed`
- [ ] `CheckoutRequest`: name, email, items (≥1, quantity 1..max_per_order, distinct ids)
- [ ] `Actions/Checkout/CreateCheckout` in `DB::transaction(attempts: 3)`, `SET lock_timeout`:
  idempotency lookup → event `FOR SHARE` (published) → INSERT order → per line ascending id: atomic `UPDATE … SET held = held + q WHERE … AND sold + held + q <= quantity RETURNING price` → items + totals
- [ ] `Idempotency-Key` required (400); same key + same payload → same order; different payload → 422
- [ ] Rate limits per tenant + IP and tenant + email (config; see open decision D1)
- [ ] Errors: `not_on_sale`, `insufficient_stock`, `max_per_order_exceeded`, `event_not_available`
- [ ] Invalidate availability cache after commit
- [ ] `stress:checkout {tenant} {ticket_type} --requests=200 --concurrency=200` (Http::pool; prints status histogram, p50/p95, quantity/sold/held; exits non-zero on oversell or 5xx)

**Tests:** success (reference, lines, fees, total, expires_at, payment_reference) · **worked example = 15997** · sold out → 4xx · not on sale (before/after window) → 4xx · above max_per_order → 422 · all-or-nothing (second line fails → first not held) · items from two events → 422 · cancelled event → rejected · idempotent replay → same order, held unchanged · key reused with different payload → 422 · arch test: no float/round in Money.

**Manual:** run stress script → exactly 50 held, rest 4xx, no 5xx, no deadlocks. Record output in README.

**Commits:** `feat(money): integer fee calculator` · `feat(checkout): orders schema` · `feat(checkout): hold inventory atomically` · `feat(checkout): idempotency key` · `feat(checkout): stress:checkout command` · `test(checkout): pricing, stock, idempotency`

---

## Phase 7 — Payment webhook & state machine [R6] [A9] [A10]

**Goal:** signed, idempotent webhook; every transition decided in one place.

**Build**
- [ ] `Domain/Orders/OrderStateMachine::transition()` — conditional `UPDATE … WHERE status IN (allowed)`; throws `InvalidOrderTransition` (only writer of `orders.status`)
- [ ] Tenant migration `webhook_events` (id PK, type, payment_reference, payload, outcome)
- [ ] `POST /webhooks/payments/{tenant}` (path tenancy)
- [ ] Verify `X-Signature` = HMAC-SHA256(raw body, tenant secret) with `hash_equals` **before** JSON parse → 401
- [ ] Validate payload → 422; unknown payment_reference → 404; amount/currency mismatch → 422 + log + outcome `mismatch`
- [ ] `Actions/Payments/HandlePaymentWebhook` in transaction: insert webhook_event `ON CONFLICT DO NOTHING` → duplicate → 200 no-op; lock order; route by (type, status):
  - pending + succeeded → paid (held→sold), issue tickets
  - pending + failed → failed (release held)
  - expired + succeeded + stock → paid (sold += q conditional)
  - expired + succeeded + no stock → refund_pending (savepoint, no tickets)
  - paid + succeeded → no-op · paid + failed → ignored
- [ ] `GET /admin/settings/webhook` (admin) to read the secret
- [ ] `payments:simulate {tenant} {order_reference} {succeeded|failed}` with `--id=` and `--amount=`
- [ ] Rate limiter `webhook` 300/min per tenant

**Tests:** invalid/missing signature → 401, nothing changes · **signed with A's secret sent to B's URL → 401** · duplicate id → 200, processed once · amount mismatch → 422, order unchanged · succeeded on paid → no extra tickets · failed after succeeded → ignored · late payment with stock → paid · late payment without stock → refund_pending, no tickets · invalid transition rejected by state machine (unit test).

**Commits:** `feat(orders): central order state machine` · `feat(payments): signed webhook endpoint` · `feat(payments): idempotent webhook handling` · `feat(payments): payments:simulate command` · `test(payments): webhook edge cases`

---

## Phase 8 — Tickets & delivery [R7] [R14] [A8.3] [A8.4] [A11]

**Goal:** one ticket per admission, never duplicated; exactly one confirmation email.

**Build**
- [ ] Tenant migrations `tickets` (**UNIQUE(order_item_id, seq)**, UNIQUE(code), INDEX(event_id, ticket_type_id, status), INDEX(order_id)), `order_notifications` (UNIQUE(order_id, type))
- [ ] Enums `TicketStatus`, `NotificationType`
- [ ] `Actions/Tickets/IssueTickets`: bulk insert seq 1..qty `ON CONFLICT DO NOTHING`; code = 128-bit random Crockford base32
- [ ] Claim `order_notifications` row → dispatch `SendTicketEmailJob` `afterCommit()` (ShouldBeUnique, tries 5, backoff, `failed()` records failure)
- [ ] `TicketConfirmationMail` + Blade template; QR PNG via `endroid/qr-code` embedded as CID; ticket code as text; signed order link
- [ ] `GET /tenants/{tenant}/orders/{reference}` — `signed` + path tenancy → order + tickets Resource

**Tests:** 3 × GA → 3 tickets · issuing twice → still 3 · webhook delivered twice → email queued once · job run twice → sent once · signed URL works; tampered tenant/signature → 403 · ticket codes unique and not numeric IDs.

**Commits:** `feat(tickets): idempotent ticket issuance` · `feat(tickets): queued e-ticket email with qr codes` · `feat(orders): signed buyer order link` · `test(tickets): issuance and email exactly once`

---

## Phase 9 — Hold expiry & scheduling [R5] [R15] [A14]

**Goal:** holds expire every minute, per active tenant, safely alongside late webhooks.

**Build**
- [ ] `orders:expire-holds` (central) → `ExpireHoldsJob` per **active** tenant
- [ ] Job: `SELECT … WHERE status='pending' AND expires_at <= now() LIMIT 500 FOR UPDATE SKIP LOCKED` → release held (ascending tier id) → transition `expired`; `WithoutOverlapping` per tenant
- [ ] `orders:send-reminders` (hourly) → `SendEventReminderJob` per active tenant; paid orders with event starting in (now+23h, now+24h]; claim `order_notifications` reminder row
- [ ] `routes/console.php` schedule: `everyMinute()` / `hourly()`, `withoutOverlapping()->onOneServer()`
- [ ] `--tenant=` option to trigger on demand

**Tests:** expiry releases inventory · non-expired holds untouched · job runs in the correct tenant DB (two tenants, only own orders expire) · provisioning/failed tenants skipped · expiry vs late webhook → consistent final state · reminder sent exactly once if job runs twice.

**Commits:** `feat(orders): expire holds per tenant` · `feat(orders): 24h event reminders` · `test(orders): expiry and reminders`

---

## Phase 10 — Door check-in [R8] [A11]

**Goal:** exactly one successful scan per ticket.

**Build**
- [ ] `POST /admin/events/{event}/check-ins` (admin, door_staff): `{code, gate?}`
- [ ] `Actions/CheckIn/CheckInTicket`: single `UPDATE tickets … WHERE code AND event_id AND status='valid' RETURNING` → on 0 rows, one SELECT to explain
- [ ] Responses: ok → 200 · already checked in → **409** with checked_in_at + staff name · void → 422 · other event / other tenant / unknown → 404
- [ ] `GET /admin/events/{event}/check-ins/stats` — total, checked in, remaining per ticket type (one GROUP BY)

**Tests:** success · duplicate → 409 with who/when · void → rejected · wrong event → 404 · tenant B code in tenant A → 404 · door_staff allowed · stats correct.

**Commits:** `feat(check-in): single-statement ticket check-in` · `feat(check-in): stats per ticket type` · `test(check-in): duplicates, void, wrong event`

---

## Phase 11 — Refunds [R9] [A9.1]

**Goal:** full refunds that can't race a check-in.

**Build**
- [ ] Tenant migration `refunds` (UNIQUE(order_id), INDEX(created_at))
- [ ] `POST /admin/orders/{order}/refund` (admin)
- [ ] `Actions/Refunds/RefundOrder`: lock order → `SELECT tickets … FOR UPDATE` (ascending id) → reject if any checked_in → void tickets → for `paid`: `sold -= q` → insert refund → transition `refunded`
- [ ] Invalidate availability + dashboard cache

**Tests:** refund paid → refunded, tickets void, inventory released · refund refund_pending → refunded, inventory untouched · rejected after check-in · rejected for pending/expired/failed · refund twice → second rejected.

**Commits:** `feat(refunds): full order refund` · `test(refunds): success and checked-in rejection`

---

## Phase 12 — Dashboard & reports [R10] [R11] [A16]

**Goal:** fast SQL aggregates with tenant-timezone boundaries.

**Build**
- [ ] `GET /admin/dashboard`: gross sales today + this month (tenant tz), sold vs capacity per upcoming event, active holds, today's check-in rate — cached `dash:{localDate}` 60 s, invalidated on paid/refund/expire/check-in
- [ ] `GET /admin/reports/sales?from&to`: per local day — orders, tickets, ticket revenue, fees, refunds (CTEs, paginated)
- [ ] `GET /admin/reports/events/{event}`: per tier sold / held / remaining / revenue
- [ ] `GET /admin/events/{event}/attendees?ticket_type_id&status` (cursor paginated)
- [ ] Local date → UTC range conversion in PHP; `date_trunc('day', paid_at AT TIME ZONE tz)`

**Tests:** timezone boundary (order at 23:30 local counts on correct day) · refund appears on refund day · attendee filters · all paginated · arch test: no PHP loops over rows in reports (code review).

**Commits:** `feat(dashboard): cached tenant metrics` · `feat(reports): sales report by local day` · `feat(reports): event sales and attendee list`

---

## Phase 13 — Seeding & performance [R17] [R18] [A17]

**Goal:** realistic data in < 3 min per tenant, and the performance targets met.

**Build**
- [ ] `DatabaseSeeder`: 2 tenants (provisioned via `ProvisionTenant`)
- [ ] Per tenant: 30–50 events (past/live/upcoming), 3–5 tiers each, 20k–50k orders all statuses, 40k–100k tickets, ~80 % check-ins for past events
- [ ] Bulk `DB::table()->insert()` in chunks of 1 000, query log & events disabled
- [ ] Rebuild `sold`/`held` counters in one statement; `ANALYZE`
- [ ] `docs/schema.md`: ERD (central + tenant) with every index justified
- [ ] Measure and record: storefront detail warm < 100 ms · checkout p95 < 300 ms in stress · sales report 3 months < 1 s · seeding < 3 min
- [ ] `EXPLAIN ANALYZE` the sales report and expiry query; adjust indexes

**Commits:** `feat(seed): bulk tenant seeder` · `docs(schema): erd and index justification` · `perf: verify targets on seeded data`

---

## Phase 14 — Deliverables & polish

- [ ] **README:** setup (Docker), `composer test`, stress script, `payments:simulate`, Mailpit check, **Assumptions**, measured performance
- [ ] **ARCHITECTURE.md:** updated with any decisions changed during build
- [ ] **Postman collection** `docs/postman_collection.json` (or OpenAPI)
- [ ] Final run: full suite, Pint, Larastan, stress test, seed timing
- [ ] Walk the **automatic fail list** once more
- [ ] Clean commit history review

**Commits:** `docs: readme setup and assumptions` · `docs: postman collection`

---

## Phase 15 — Bonus (only after core is green) [A19]

| Order | Item | Points | Notes |
|---|---|---|---|
| 1 | Docker setup | +2 | already done in Phase 0 |
| 2 | Larastan 6+ & Pint clean | +2 | already enforced each phase |
| 3 | Refund vs check-in race | +2 | already designed in Phase 11 — add a test |
| 4 | OpenAPI spec | +1 | `dedoc/scramble` (**ask before adding the package**) |
| 5 | Promo codes | +5 | `promo_codes` + conditional `UPDATE used = used + 1 WHERE used < max_uses` in checkout tx |
| 6 | Event-level capacity | +3 | `events.capacity/sold/held` + CHECK, event row updated before tiers |
| 7 | Tier progression | +3 | `unlocks_after_id`; predecessor sold-out condition in checkout WHERE |
| 8 | Queued CSV attendee export | +2 | job streams cursor to tenant disk → signed download link |

---

## Open decisions (resolve before the phase that needs them)

| # | Decision | Needed by | Proposal |
|---|---|---|---|
| D1 | Checkout rate limit vs 200-request stress test from one IP | Phase 6 | per-email 10/min; per-IP default high (e.g. 300/min) in config so the review stress test can hold exactly 50. Document in Assumptions |
| D2 | Can a `failed` tenant id be reused? | Phase 2 | No via API; `tenant:purge` frees it |
| D3 | Delete event with any (not only paid) order | Phase 4 | Block on any order (FK RESTRICT); stricter than spec, documented |
| D4 | Payment after `expires_at` but before expiry job ran | Phase 7 | Treat as normal payment (still pending) |
| D5 | Where the admin gets the webhook secret | Phase 7 | Printed by `tenant:provision` + `GET /admin/settings/webhook` |
| D6 | Refunded order in sales report | Phase 12 | Sale on paid day, refund on refund day |
| D7 | Postgres for local tests | Phase 0 | Docker Compose `postgres` service; CI uses a Postgres service container |
