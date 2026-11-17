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
