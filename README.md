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
