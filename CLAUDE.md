# CLAUDE.md

Multi-tenant event ticketing **API** (onboarding assignment, review 2026-11-23).
Full design and reasoning: `ARCHITECTURE.md`. Original requirements: `docs/REQUIREMENTS.md`.
When this file and those disagree, **REQUIREMENTS.md wins**, then ARCHITECTURE.md, then this file. Flag the conflict instead of picking silently.

## Stack (do not substitute or add frameworks)
PHP 8.3 · Laravel 12 · PostgreSQL 16 · Redis 7 · stancl/tenancy v3 (database-per-tenant, `X-Tenant-ID` header) · Sanctum (tokens only) · Pest · Docker · Horizon · Mailpit
API only: no frontend, no Vite, no Breeze/Jetstream. Blade is used only for email templates.
Ask before adding any composer package not listed in ARCHITECTURE.md §0.

## Commands
- `docker compose up -d`: app, nginx, postgres, redis, horizon, scheduler, mailpit (UI http://localhost:8025)
- `composer test`: full Pest suite (must pass before every commit)
- `vendor/bin/pint && vendor/bin/phpstan analyse`: must be clean before every commit
- `php artisan stress:checkout {tenant} {ticket_type} --requests=200`
- `php artisan payments:simulate {tenant} {order_reference} {succeeded|failed}`
- `php artisan tenant:provision {tenant_id} {name} {currency} {timezone} {admin_email}`

## Hard rules: never break these
These map to the spec's automatic-fail conditions.

**Money**
- NEVER use float, `round()`, `(float)` or `decimal` casts for money. Store and calculate integer minor units (`bigint`).
- All fee and total math goes through `App\Domain\Money\FeeCalculator`. Half-up rounding is integer math: `intdiv(x * bps + 5000, 10000)`.
- Orders and order_items snapshot their prices. Never recompute from `ticket_types`.

**Inventory**
- Change `ticket_types.sold` and `held` ONLY with atomic conditional `UPDATE … WHERE sold + held + :q <= quantity`. Never read, check in PHP, then write.
- Never decide stock from cache or Redis. PostgreSQL is the only source of truth.
- Lock order everywhere: event → order → ticket_types (ascending id) → tickets (ascending id).
- Keep the `CHECK (sold + held <= quantity)` constraint. Never remove or loosen it.

**Order status**
- Only `App\Domain\Orders\OrderStateMachine::transition()` may write `orders.status`. No `$order->update(['status' => …])` anywhere else.
- Statuses are PHP enums. No string literals.

**Multi-tenancy**
- Tenant-scoped data lives only in tenant DBs. The central DB holds only `tenants`.
- Never add a query-string or subdomain tenant fallback.
- Tenancy middleware MUST run before `auth:sanctum` (check `$middlewarePriority`).
- Build every raw Redis, lock or rate-limit key with `App\Support\TenantKey`. No hand-written key strings.
- Jobs take IDs, not models. Tenant jobs must run in tenant context. `ProvisionTenantJob` is the only central job.
- Scheduled tasks fan out one job per **active** tenant.
- Webhooks verify `X-Signature` (HMAC-SHA256 of the raw body, `hash_equals`) with the secret of the tenant **in the URL**, before parsing JSON.

**Uniqueness and idempotency**
- Enforce it with DB constraints (UNIQUE, CHECK, FK), not just application checks: tickets `(order_item_id, seq)`, webhook id, idempotency key, `order_notifications (order_id, type)`, refunds `order_id`.
- Check-in is a single `UPDATE … WHERE status = 'valid' RETURNING`.
- Emails: claim an `order_notifications` row first, dispatch `afterCommit()`.

**Provisioning**
- `POST /register` and `tenant:provision` call the same `ProvisionTenant` action.
- Never `updateOrCreate` or `firstOrCreate` on `tenants`. Registration must never modify an existing tenant.
- Hash the password inside the request. Plaintext never reaches a job payload or the central DB.

## Code conventions
- Controllers stay thin: FormRequest → `authorize` (Policy) → Action → API Resource. No business logic and no `DB::` in controllers.
- Business operations go in `app/Actions/**`. Authorization goes in Policies, not role `if`s.
- Tunable values go in `config/ticketing.php` (hold minutes, fees, TTLs, rate limits, reserved IDs). No magic numbers.
- Every list endpoint is paginated. Aggregate in SQL (`GROUP BY` / `SUM` / `COUNT`). No PHP loops over rows. No N+1: eager-load.
- Every new FK, lookup column or filter column gets an index, and `docs/schema.md` gets a one-line justification.
- Errors are JSON with a stable `error` code. Never return a 500 for a client mistake.

## Workflow
1. Work on one feature at a time in the order in ARCHITECTURE.md. Don't start the next one until tests for the current one pass.
2. Each feature ships with Pest feature tests covering the happy path, the spec's edge cases and tenant isolation.
3. Make small, logical commits (Conventional Commits, e.g. `feat(checkout): hold inventory atomically`). Reviewers read the history.
4. If the requirements are ambiguous, don't guess silently. Add an entry under **Assumptions** in `README.md` and mention it in your reply.
5. If a change touches a hard rule, stop and explain before editing.
6. If you change a design decision, update `ARCHITECTURE.md` in the same commit.

## Who does what (AI sessions)
- **Opus** (main session): plans, decides, reviews subagent output against REQUIREMENTS.md and the hard rules, then commits and merges.
- **Sonnet** subagent: writes code and the complex changes (features, fixes, non-trivial tests).
- **Haiku** subagent: simple tasks: small edits, running tests/linters, searching code and docs.
- One-line answers and quick lookups are done directly, without a subagent.
- Postman collection: every POST/PATCH body lists every field the FormRequest accepts (optional ones with example values); the request's description says, per field, required/optional, units (e.g. minor units = sen) and rules.

## Definition of done (per feature)
- [ ] Behaviour matches REQUIREMENTS.md, including status codes
- [ ] Feature tests pass, including an isolation test (tenant A cannot see tenant B)
- [ ] Pint and Larastan clean
- [ ] Indexes added and documented
- [ ] Cache invalidation added if the feature changes events, ticket types or orders
- [ ] README / ARCHITECTURE updated if behaviour or a decision changed
