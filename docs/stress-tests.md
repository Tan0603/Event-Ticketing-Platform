# Stress Test Cases

| | |
|---|---|
| **Project** | Multi-tenant Event Ticketing API |
| **Test type** | Concurrency / stress test (real parallel HTTP requests against the running Docker stack) |
| **Tools** | `php artisan stress:checkout`, `php artisan stress:checkin`, `php artisan payments:simulate`, Postman, DB viewer |
| **Environment** | `docker compose up -d` (app, nginx, postgres, redis, horizon, scheduler, mailpit) |
| **Tenant used** | `maxis` (replace with your own tenant id) |
| **Requirements covered** | No oversell under concurrency; event shared capacity; one admission per ticket; unpaid holds expire |

## 1. Summary

| ID | Title | Priority | Expected result | Status |
|---|---|---|---|---|
| ST-01 | 200 buyers compete for 50 seats | High | 50 created, 150 `insufficient_stock`, no oversell | |
| ST-02 | Checkout on a sold-out ticket type | High | 0 created, 200 `insufficient_stock`, no error 500 | |
| ST-03 | Several tickets per order under load | Medium | 16 created (48 seats), 184 refused, 2 seats left | |
| ST-04 | Event shared capacity under load | Medium | 30 created, 70 `event_sold_out` | |
| ST-05 | Preflight blocks a ticket type that is not on sale | Low | Clear message with the fix, no request sent | |
| ST-06 | One ticket scanned 40 times at once | High | 1 admitted, 39 `already_checked_in` | |
| ST-07 | Unpaid holds are released after the hold time | Medium | `held` returns to 0, orders become `expired` | |

Status values: **Pass**, **Fail**, **Blocked** (could not run), **Not run**.

## 2. Common setup

**S-1. Start the stack and check the background workers are up**

```bash
docker compose up -d
docker compose ps
```
`horizon` and `scheduler` must be `running` (needed by ST-06 e-mails and ST-07).

**S-2. Log in as admin** (Postman "Login"), with header `X-Tenant-ID: maxis`. Use the returned token for all `/api/admin/...` calls.

**S-3. Create and publish an event**

`POST /api/admin/events`
```json
{
  "title": "Stress Test Concert",
  "venue_name": "Axiata Arena",
  "starts_at": "2026-12-20 20:00:00",
  "ends_at": "2026-12-20 23:00:00"
}
```
Then `POST /api/admin/events/{event_id}/publish`.

**S-4. Create a ticket type with 50 seats**

`POST /api/admin/events/{event_id}/ticket-types`
```json
{
  "name": "Stress GA",
  "price_minor": 5000,
  "quantity": 50,
  "max_per_order": 10
}
```
Write down the returned `id`. The cases call it `TT`.

**Rules for every run**

- Use a **new** ticket type for each case unless the case says otherwise. Seats from an earlier run stay `held` until those orders expire (ST-07).
- **Wait 1 minute between checkout runs.** Checkout allows 300 requests per minute per IP (`TICKETING_CHECKOUT_IP_LIMIT`), and all requests come from the same container. Without the wait, some requests answer `429`.

**What `stress:checkout` checks after every run (invariants)**

| Invariant | Meaning |
|---|---|
| `sold + held <= quantity` | No oversell |
| `held` grew by exactly (orders created x quantity) | Each `201` holds its seats; each refusal holds nothing |
| `held` = tickets in pending orders | No lost or leaked holds |
| `event reserved <= event capacity` (events with capacity only) | Event limit respected |
| `event reserved` = `sold + held` of all its tiers (events with capacity only) | Event counter matches its tiers |

The command ends with `No oversell.` when all pass, or `INVARIANT BROKEN.` when one fails.

## 3. Test cases

### ST-01: 200 buyers compete for 50 seats

| Field | Detail |
|---|---|
| **Objective** | Prove checkout never sells more seats than exist when many buyers arrive at the same time. |
| **Priority** | High |
| **Preconditions** | Setup S-1 to S-4 done. Ticket type `TT` has `quantity 50`, `sold 0`, `held 0`. |
| **Test data** | tenant `maxis`, ticket type `TT`, 200 requests, 1 ticket each, 50 in flight at a time |

**Steps**

1. Run:
   ```bash
   docker compose exec app php artisan stress:checkout maxis TT --requests=200
   ```
2. Read the preflight line, the outcome table and the invariant table.
3. In the DB viewer, open `ticket_types` for `TT` in the tenant database.

**Expected result**

| Check | Expected |
|---|---|
| Preflight | `On sale. Remaining: 50, so expect 50 created and 150 insufficient_stock.` |
| `201 created` | 50 |
| `409 insufficient_stock` | 150 |
| Other outcomes (500, connection error) | 0 |
| Invariants | All PASS, `No oversell.` |
| `ticket_types` row | `held = 50`, `sold = 0` |

| **Actual result** | |
|---|---|
| **Status** | |
| **Tested by / date** | |

---

### ST-02: Checkout on a sold-out ticket type

| Field | Detail |
|---|---|
| **Objective** | Prove every buyer gets a clean refusal when no seats are left: no error 500, no negative stock. |
| **Priority** | High |
| **Preconditions** | ST-01 just finished on `TT` (`held = 50`). At least 1 minute has passed since ST-01. |
| **Test data** | same ticket type `TT`, 200 requests |

**Steps**

1. Run:
   ```bash
   docker compose exec app php artisan stress:checkout maxis TT --requests=200
   ```

**Expected result**

| Check | Expected |
|---|---|
| Preflight | `On sale. Remaining: 0, so expect 0 created and 200 insufficient_stock.` |
| `201 created` | 0 |
| `409 insufficient_stock` | 200 |
| Invariants | All PASS |
| `ticket_types` row | `held` still 50 |

| **Actual result** | |
|---|---|
| **Status** | |
| **Tested by / date** | |

---

### ST-03: Several tickets per order under load

| Field | Detail |
|---|---|
| **Objective** | Prove the stock check works when each order asks for more than one seat, and an order is never partly filled. |
| **Priority** | Medium |
| **Preconditions** | New ticket type `TT2` created as in S-4 (`quantity 50`). |
| **Test data** | ticket type `TT2`, 200 requests, 3 tickets each |

**Steps**

1. Run:
   ```bash
   docker compose exec app php artisan stress:checkout maxis TT2 --requests=200 --quantity=3
   ```
2. Check `ticket_types` for `TT2`.

**Expected result**

| Check | Expected |
|---|---|
| Preflight | `On sale. Remaining: 50, so expect 16 created and 184 insufficient_stock.` |
| `201 created` | 16 (16 x 3 = 48 seats) |
| `409 insufficient_stock` | 184 |
| Invariants | All PASS |
| `ticket_types` row | `held = 48`. 2 seats stay free, because a request for 3 is refused as a whole. |

| **Actual result** | |
|---|---|
| **Status** | |
| **Tested by / date** | |

---

### ST-04: Event shared capacity under load

| Field | Detail |
|---|---|
| **Objective** | Prove the event's total capacity is never exceeded, even when its ticket type still has seats. |
| **Priority** | Medium |
| **Preconditions** | A new event created as in S-3 but with `"capacity": 30`, published. One ticket type `TT3` on it with `quantity 50`. |
| **Test data** | ticket type `TT3`, 100 requests |

**Steps**

1. Run:
   ```bash
   docker compose exec app php artisan stress:checkout maxis TT3 --requests=100
   ```
2. Check `events.reserved` for the event and `ticket_types` for `TT3`.

**Expected result**

| Check | Expected |
|---|---|
| Preflight | `Event shared capacity 30 (reserved 0).` and `Remaining: 30, so expect 30 created and 70 insufficient_stock.` |
| `201 created` | 30 |
| `409 event_sold_out` | 70 ("This event is sold out.") |
| Invariants | All 5 PASS, including `event reserved <= event capacity` |
| DB | `events.reserved = 30`; `TT3` `held = 30` (20 tier seats unused) |

Note: the preflight text always says `insufficient_stock`. The real refusals answer `event_sold_out`, because the event limit is reached before the tier limit.

| **Actual result** | |
|---|---|
| **Status** | |
| **Tested by / date** | |

---

### ST-05: Preflight blocks a ticket type that is not on sale

| Field | Detail |
|---|---|
| **Objective** | Prove the command explains why a run cannot work, and sends no requests. |
| **Priority** | Low |
| **Preconditions** | One ticket type for each row of the table below, set up as described. |
| **Test data** | see table |

**Steps**

1. For each row, run `docker compose exec app php artisan stress:checkout maxis {id} --requests=10`.
2. Check that no new order exists (`GET /api/admin/orders?event_id={event_id}`).

**Expected result**

| # | Set up first | Expected message (exit code FAILURE, nothing sent) |
|---|---|---|
| a | Tier on an event that is **not published** | `... belongs to a draft event (not published). Publish it first: POST /api/admin/events/{id}/publish` |
| b | Tier with `sales_start_at` in the future | `... is not on sale yet (...). Open it first: PATCH /api/admin/ticket-types/{id} {"sales_start_at": null}` |
| c | Tier with `sales_end_at` in the past | `... sales have ended (...). Extend it first: PATCH /api/admin/ticket-types/{id} {"sales_end_at": null}` |
| d | Tier with `unlocks_after_ticket_type_id` pointing to a tier that is not sold out | `... is locked until "..." sells out or ends. Unlock it first: PATCH ... {"unlocks_after_ticket_type_id": null}` |
| e | Ticket type id that does not exist (e.g. `999999`) | `Unknown ticket type.` |

Automated test: `tests/Feature/Checkout/StressCheckoutPreflightTest.php`.

| **Actual result** | a: &nbsp; b: &nbsp; c: &nbsp; d: &nbsp; e: |
|---|---|
| **Status** | |
| **Tested by / date** | |

---

### ST-06: One ticket scanned 40 times at once

| Field | Detail |
|---|---|
| **Objective** | Prove a ticket is admitted exactly once when many gates scan it at the same moment. |
| **Priority** | High |
| **Preconditions** | One **paid** order with a ticket whose status is `valid`. An admin or door-staff login for the tenant. |
| **Test data** | ticket code, staff email and password, 40 scans |

**Steps**

1. Place one checkout in Postman (`POST /api/events/{slug}/checkout`) and note the `reference`.
2. Pay it:
   ```bash
   docker compose exec app php artisan payments:simulate maxis {order_reference} succeeded
   ```
3. Get a ticket code from `GET /api/admin/orders/{order_reference}` (field `tickets[].code`), or from the `tickets` table.
4. Run:
   ```bash
   docker compose exec app php artisan stress:checkin maxis {ticket_code} {staff_email} {staff_password} --requests=40
   ```
5. Check the `tickets` row in the DB viewer.

**Expected result**

| Check | Expected |
|---|---|
| `200` admitted | 1 |
| `409 already_checked_in` | 39 |
| Other outcomes | 0 |
| `tickets` row | `status = checked_in`, one `checked_in_at` value |

If the ticket is not `valid` before the run, the command stops with "nothing to race for". Use another ticket.

| **Actual result** | |
|---|---|
| **Status** | |
| **Tested by / date** | |

---

### ST-07: Unpaid holds are released after the hold time

| Field | Detail |
|---|---|
| **Objective** | Prove seats held by unpaid orders go back on sale after the hold time. |
| **Priority** | Medium |
| **Preconditions** | ST-01 finished on `TT` (`held = 50`, 50 pending orders). `scheduler` and `horizon` containers running. |
| **Test data** | hold time `TICKETING_HOLD_MINUTES` (default 10) |

**Steps**

1. Note the time. Check `ticket_types` for `TT`: `held = 50`.
2. Wait about 11 minutes (10 minutes hold + up to 1 minute for the scheduler).
3. Check `ticket_types` for `TT` again.
4. `GET /api/admin/orders?event_id={event_id}&status=expired`.
5. Run ST-01's command again.

**Expected result**

| Check | Expected |
|---|---|
| After step 3 | `held = 0`, `sold = 0` |
| After step 4 | 50 orders with status `expired` |
| After step 5 | Preflight shows `Remaining: 50`, and ST-01's results repeat |

If `held` is still 50 after 12 minutes, check `docker compose ps` and `docker compose logs scheduler horizon`.

| **Actual result** | |
|---|---|
| **Status** | |
| **Tested by / date** | |

## 4. How it works (for reviewers)

- **Checkout (ST-01 to ST-04):** each hold is one conditional `UPDATE ticket_types SET held = held + :q WHERE sold + held + :q <= quantity ...` in `App\Domain\Inventory\TicketStock::hold`. PostgreSQL row-locks the tier, so a waiting request re-checks the condition with the new numbers. When the event has a capacity, `events.reserved` is checked the same way first. The `CHECK (sold + held <= quantity)` constraint is the last safety net.
- **Check-in (ST-06):** one `UPDATE tickets SET status = 'checked_in' WHERE ... AND status = 'valid' RETURNING` in `App\Actions\Tickets\CheckInTicket`. Only one scan can change the row.
- **Expiry (ST-07):** `ExpirePendingOrdersJob` runs every minute, one job per active tenant, moves overdue orders `pending -> expired` through `OrderStateMachine` and releases their holds.
- Every checkout request in the command has its own buyer e-mail and `Idempotency-Key`, so the per-e-mail rate limit and idempotent replay do not affect the result.

## 5. Quick demo order

Run **ST-01, ST-02 and ST-06**. They cover the three race conditions reviewers ask about most (overselling, sold out, double scan) and take about 3 minutes including the 1-minute wait.
