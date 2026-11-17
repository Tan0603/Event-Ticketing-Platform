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

## Tests and quality gates

```bash
docker compose exec app composer test          # full Pest suite
docker compose exec app vendor/bin/pint        # code style
docker compose exec app vendor/bin/phpstan analyse   # Larastan level 6
```

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
| GET / POST | `/events` | list (paginated; `status`, `q`, `from`, `to`, `sort`, `direction`, `per_page`) / create (draft) |
| GET / PATCH / DELETE | `/events/{id}` | show with tiers / update / delete (blocked once anything sold or held) |
| POST | `/events/{id}/publish` | needs at least one ticket type |
| POST | `/events/{id}/cancel` | stops all sales |
| GET / POST | `/events/{id}/ticket-types` | tiers of an event / add one |
| GET / PATCH / DELETE | `/ticket-types/{id}` | show / update / delete (blocked once anything sold or held) |

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

Errors: `404` unknown/unpublished event, `409` `insufficient_stock` / `sales_not_started` / `sales_ended` /
`event_not_on_sale`, `422` validation (also a ticket type of another event, or above max per order), `429` throttled.

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

- **A free order carries no fee** (the fixed fee applies only when the subtotal is above zero).
- **Per-IP checkout limit is high (300/min) and the per-e-mail limit is strict (10/min)**, so offices behind one NAT can still buy and the stress test reaches the locking path.
- **Checkout of a cancelled/draft event answers 404**, an event that has ended answers `409 event_not_on_sale`.
- **Containers log to stderr** (`LOG_CHANNEL=stderr`): php-fpm and artisan run as different users and cannot share one log file.

- **A payment after `expires_at` but before the expiry job ran is a normal payment** (the order is still pending).
- **A payment after the job released the hold** is honoured only if the tickets are still free; otherwise the order stays expired and the notification is recorded as `late_payment_no_stock` for a refund.
- **An amount mismatch is answered 422 and never marks the order paid.** The event id is recorded, so the provider must send a corrected notification under a new id.
- **An unknown order reference is answered 200** (`unknown_order`) so the provider does not retry forever.
- **A free order (total 0) is paid immediately at checkout.**
