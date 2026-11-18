# Build Plan — Event Ticketing Platform

This is the step-by-step plan for building the project, written in plain language.
The full technical details live in `ARCHITECTURE.md`. The original assignment is the PDF
(to be copied into `docs/REQUIREMENTS.md`).

**Started:** 16 Nov 2026 · **Review:** 23 Nov 2026

---

## The big picture

We are building the back-end (API only, no screens) for a ticket-selling platform like Megatix.

- Many **organizers** (we call them *tenants*) use the same system. Each one gets its own
  private database, so one organizer can never see another's data.
- Organizers create **events** and **ticket tiers** (Early Bird, GA, VIP…).
- **Buyers** don't need an account. They pick tickets, the tickets are held for 10 minutes,
  and they pay. A fake payment gateway tells us when payment succeeds.
- After payment, the buyer gets an **email with QR-code tickets**.
- **Door staff** scan the tickets at the entrance.

What the reviewers care about most:

1. **Never sell more tickets than exist**, even when 200 people buy at the same second.
2. **Never mix up organizers' data.**
3. **Money is always exact**, with no rounding mistakes.
4. **Nothing happens twice** (no duplicate tickets, emails or check-ins).
5. The code is **clean and easy for another developer to extend**.

---

## How to work through this plan

- Do the phases **in order**. Finish one, make sure its tests pass, then move on.
- After every phase, run the tests and the code-style checks, then commit.
- Keep commits small, with clear messages. The reviewers read the history.
- If something in the assignment is unclear, make a sensible choice and write it under
  **Assumptions** in the README.

### Things that make you fail immediately

Check this list at the end of every phase:

- One organizer can see or change another organizer's data
- Signing up with an existing organizer name changes that organizer
- More tickets are sold than exist
- One payment creates duplicate tickets
- A ticket can be scanned in twice
- An order's total doesn't add up, or decimals (floats) are used for money
- A payment message is accepted without checking its signature
- A background job runs for the wrong organizer

## Phase 0 — Set up the project

**What we're doing:** Get an empty Laravel project running, with all the tools we need.

- Install Laravel 12 and the required packages (multi-tenancy, login tokens, testing, QR codes).
- Remove front-end tooling we don't need, since this is API only.
- Set up Docker so everything runs with one command: the app, web server, PostgreSQL
  database, Redis, background workers, scheduler and Mailpit (a fake inbox for viewing emails).
- Create one settings file for all adjustable values: how long a hold lasts, fees, how long
  to cache things, rate limits.
- Set up automatic checks (tests, code style, static analysis) that run on every push.

**Done when:** `docker compose up` starts everything and the (empty) test suite runs green.

---

## Phase 1 — Keep organizers separate

**What we're doing:** Make the system always know *which organizer* a request is for, and
make sure it only ever touches that organizer's database.

- One shared "central" database holds just the list of organizers.
- Each organizer gets their own database for everything else.
- Every API request says which organizer it's for with an `X-Tenant-ID` header.
- The payment gateway and email links can't send headers, so they put the organizer in the URL instead.
- Clear error messages:
  - no organizer given → **400**
  - organizer doesn't exist → **404**
  - organizer still being set up, or setup failed → **409** "not ready"

**How we check it:** Tests for each of those errors, and a test proving the organizer can't
be slipped in through the URL query string instead of the header.

---

## Phase 2 — Organizer sign-up

**What we're doing:** Let an organizer sign themselves up, and let us create one by hand from
the command line. Both use the **same** code.

- The organizer sends their name, chosen ID (like `sunsetfest`), currency, timezone, and
  their admin's name, email and password.
- We check the ID is valid, not reserved (`api`, `admin`, `www`…) and not taken.
- We save the organizer as "provisioning" and reply straight away with **202**.
- In the background, a job creates their database, sets up the tables, creates the admin
  user and a secret key for payments, then marks them "active".
- The organizer can check progress with `GET /register/{id}`.

**What must be true:**

- If the ID already exists → **422**, and the existing organizer is **not touched at all**.
- If two people try the same ID at the same moment → one wins, the other gets a clean 422 (not a crash).
- The password is scrambled (hashed) immediately. The plain password is never stored or passed to the background job.
- If the background job fails halfway and retries, it carries on safely. No "database already exists" error, no second admin.
- If it fails for good, the organizer is marked "failed" and their database is deleted, so nothing is left behind.
- Sign-ups are limited per IP address, because each one creates a database.

**How we check it:** Tests for success, duplicate ID, bad ID, a retry after partial failure,
and a permanent failure.

---

## Phase 3 — Staff login and roles

**What we're doing:** Let organizer staff log in and control what each role can do.

- Staff log in with email and password and get a login token.
- Two roles:
  - **Admin:** can do everything for their organizer.
  - **Door staff:** can only scan tickets and see check-in numbers.
- Permission rules live in one place (Laravel "Policies"), not scattered through the code.

**What must be true:** A token from organizer A **must not work** when the request says
organizer B, even if someone changes the header.

**How we check it:** Login, logout, door staff blocked from creating events, and a test that
uses A's token against B.

---

## Phase 4 — Events and ticket tiers (admin)

**What we're doing:** Let admins create and manage their events and ticket tiers.

- **Events** have a title, web-friendly name (slug), description, venue, start and end time,
  and a status: draft, published or cancelled.
- **Ticket tiers** have a name, price, how many exist, when sales open and close, the maximum per order, and a display order.
- Prices are stored as whole cents (e.g. 45.00 is stored as 4500), never as decimals.

**Rules:**

- An event must end after it starts.
- Only published events show up in the public shop.
- Cancelling an event stops new purchases immediately.
- An event that has orders can't be deleted. Cancel it instead.
- You can't lower a tier's quantity below what's already sold or held.
- You can't delete a tier that has sales.
- Changing a price never changes orders that already exist.
- "Upcoming / live / ended" and "on sale / sold out" are **worked out from the dates**, not saved.

**How we check it:** Create, edit and delete tests for each rule, plus a test that organizer
A can't see or edit B's events.

---

## Phase 5 — The public shop

**What we're doing:** Let anyone browse an organizer's events without logging in.

- A page listing upcoming published events, in pages.
- An event detail page showing each tier's price, whether it's on sale, and how many are left.
- Never show drafts, cancelled events, internal numbers or another organizer's data.
- Save (cache) these pages for a short time so they load fast. Clear the saved copy when the
  event or tiers change.
- Tell browsers and proxies that the page depends on the organizer, so one organizer's page is never shown for another.

**How we check it:** The event list uses the **same number of database queries** for 5 events
as for 50, which proves we aren't loading things one by one.

---

## Phase 6 — Checkout and holding tickets ★ (most important)

**What we're doing:** Let a buyer reserve tickets and get an order to pay for.

1. The buyer sends their name, email and the tickets they want.
2. We create an order marked "pending" and **hold** those tickets for 10 minutes.
3. We reply with the order reference, each line, fees, total, expiry time and a payment reference.

**How the price is worked out** (example: fee 5% + RM 0.50 per ticket):

| Tier | Price | Qty | Subtotal | Fee per ticket | Fees |
|---|---|---|---|---|---|
| GA | 45.00 | 2 | 90.00 | 2.25 + 0.50 = 2.75 | 5.50 |
| Early Bird | 19.99 | 3 | 59.97 | 1.00 + 0.50 = 1.50 | 4.50 |
| **Total** | | | **149.97** | | **10.00** → **159.97** |

The percentage is always rounded **half-up** to the nearest cent, using whole-number maths only.

**What must be true:**

- **Never oversell.** We reserve stock with a single database instruction that says
  "add these to the held count, *only if* there are enough left". The database handles
  everyone standing in line, so two buyers can never both get the last ticket. A database
  rule also refuses any change that would oversell, as a second safety net.
- **All or nothing.** If one line can't be filled, nothing is held.
- All tickets must come from one published event.
- Clear errors for: not on sale yet or anymore, quantity below 1 or above the per-order
  limit, and not enough stock.
- **No double orders.** The buyer's app sends a unique `Idempotency-Key`. If the same request
  is retried, they get the *same* order back. If the same key is used for a *different*
  request, it's rejected.
- Redis (the fast cache) is never trusted for stock. Only the database decides.
- Purchases are limited per organizer, IP address and email.

**Stress test:** a command that fires **200 purchases at once** at a tier with 50 tickets.
Expected result: exactly 50 held, every other request politely refused, no crashes.

**How we check it:** Tests for success, the worked example (= 159.97), sold out, not on sale,
over the limit, all-or-nothing and repeated requests, then run the stress test by hand.

---

## Phase 7 — Payments

**What we're doing:** Accept "payment succeeded / failed" messages from the fake gateway.

- The gateway calls a URL that includes the organizer ID.
- Each message is signed with the organizer's secret key. We check the signature **before
  doing anything else**.
- There's a command, `payments:simulate`, that sends a correctly signed message so reviewers can test easily.

**What happens to an order:**

| Order is… | Message says… | Result |
|---|---|---|
| pending | succeeded | **paid**, tickets created |
| pending | failed | **failed**, held tickets released |
| pending | (time ran out) | **expired**, held tickets released |
| expired | succeeded, tickets still available | **paid** |
| expired | succeeded, tickets gone | **refund pending**, no tickets, organizer must refund |
| paid / refund pending | (admin refunds) | **refunded** |

Every other change is not allowed, and that is checked in **one single place** in the code.

**What must be true:**

- Bad or missing signature → **401**, nothing changes.
- Signed with organizer A's key but sent to B's URL → **401**.
- The same message delivered twice → processed once, the second gets a polite "OK".
- The amount or currency doesn't match the order → **422**, the order is untouched, and we log it.
- "Succeeded" for an already-paid order → nothing happens (no extra tickets).
- "Failed" after "succeeded" → ignored.
- Reply quickly. Slow work like emails happens in the background.

---

## Phase 8 — Tickets and email

**What we're doing:** Create the tickets and email them to the buyer.

- One ticket per person (3 × GA = 3 tickets).
- Each ticket has a random, unguessable code, shown as a QR code.
- Ticket status: valid, checked in or void.
- The email is sent in the background and contains the QR codes and a secure link to view the order.
- The link carries the organizer in the URL and is signed, so it can't be tampered with.

**What must be true:**

- **No duplicate tickets**, even if payment is processed twice. The database itself refuses duplicates.
- **Exactly one confirmation email** per paid order, even if the job or the message runs twice.
- If sending fails, it retries with a growing delay. If it fails for good, we record it and
  move on without blocking other emails.

**How we check it:** Open the email in Mailpit (http://localhost:8025) and see the QR codes.

---

## Phase 9 — Automatic timers

**What we're doing:** Set up tasks that run on their own.

- **Every minute:** find pending orders whose 10 minutes are up, mark them expired and
  release their tickets. This must not clash with a payment arriving at the same moment.
- **Every hour:** email a reminder to buyers whose event starts in about 24 hours, **once only** per order.
- Each task runs separately for **every active organizer**, inside that organizer's own
  database, and never overlaps with its own previous run.

**How we check it:** Tests that expired holds give their tickets back, that each organizer
only affects its own orders, and that reminders are never sent twice.

---

## Phase 10 — Door check-in

**What we're doing:** Let staff scan tickets at the entrance.

- Valid ticket for this event → checked in, recording the time, the staff member and the gate.
- Already checked in → **409**, saying when and by whom.
- Void ticket → rejected.
- Ticket for another event or another organizer → "not found".
- Two gates scan the same ticket at the same moment → **exactly one succeeds**.
- A stats screen per event: total tickets, checked in and remaining, for each tier.

---

## Phase 11 — Refunds

**What we're doing:** Let admins refund an order.

- Whole orders only. No partial refunds.
- Only for orders that are paid or refund pending.
- Refused if any ticket in the order has already been scanned in.
- All tickets in the order become void. For paid orders, the tickets go back on sale.
- No real money moves. We just record the refund and change the status.
- A refund and a scan happening at the same moment can't both succeed.

---

## Phase 12 — Dashboard and reports

**What we're doing:** Give admins numbers about their sales.

**Dashboard** (saved for a short time so it loads fast):

- Sales today and this month, in the organizer's own timezone
- Tickets sold compared with capacity, for each upcoming event
- How many orders are currently being held
- Check-in rate for today's events

**Reports** (all in pages, all calculated by the database, all using the organizer's timezone):

- **Sales report** for a date range: per day, the number of orders, tickets sold, ticket money, fees and refunds
- **Event report:** per tier, how many sold, held and left, and money made
- **Attendee list** per event, filterable by tier and by whether they've checked in

---

## Phase 13 — Test data and speed

**What we're doing:** Fill the system with realistic data and make sure it stays fast.

- Create at least **2 organizers**, each with:
  - 30–50 events (past, happening now and upcoming)
  - 3–5 tiers per event
  - 20,000–50,000 orders in every status
  - 40,000–100,000 tickets
  - about 80% of past-event tickets checked in
- Insert the data in big batches, not one row at a time, so it takes **under 3 minutes**.
- Write the database diagram and explain **why each index exists**.

**Speed targets:**

| What | Target |
|---|---|
| Event page (already cached) | under 0.1 s |
| Checkout during the stress test (95% of requests) | under 0.3 s |
| Sales report for 3 months | under 1 s |
| Filling one organizer with data | under 3 min |

---

## Phase 14 — Final documents and check

- **README:** how to set up, run the tests, run the stress test and simulate a payment, plus all **Assumptions** and the measured speeds.
- **ARCHITECTURE.md:** updated with anything that changed while building.
- **Postman collection** (or an OpenAPI file) so reviewers can try every endpoint.
- One last full run: all tests, code style, stress test and seeding time.
- Go through the "fail immediately" list one more time.

---

## Phase 15 — Extra points (only after everything above works)

Cheapest first:

| Extra | Points | Note |
|---|---|---|
| Docker setup | +2 | done in Phase 0 |
| Clean static analysis and code style | +2 | kept clean every phase |
| A refund and a scan at the same moment can't both succeed | +2 | built in Phase 11, just add a test |
| OpenAPI document | +1 | needs one extra package, so ask first |
| Promo codes (% or fixed, valid dates, usage limit that can't be exceeded) | +5 | |
| One shared capacity for the whole event, across tiers | +3 | |
| Next tier opens automatically when the previous one sells out | +3 | |
| Attendee list exported to CSV in the background, with a secure download link | +2 | |

---

## Decisions still to confirm

| # | Question | My suggestion | Needed before |
|---|---|---|---|
| 1 | The purchase limit per IP would block the reviewers' 200-at-once test (they'd only get 10 through, not 50) | Keep the strict limit per email, make the per-IP limit high (e.g. 300 a minute), and explain it in Assumptions | Phase 6 |
| 2 | Can a failed organizer ID be used again? | Not through sign-up. An admin command frees it | Phase 2 |
| 3 | Can an event with unpaid orders be deleted? | No. Block deleting if it has any orders (stricter than the assignment) | Phase 4 |
| 4 | Payment arrives after 10 minutes but before the timer ran | Treat it as a normal payment | Phase 7 |
| 5 | How does the organizer get their payment secret key? | Shown after setup, and on an admin settings page | Phase 7 |
| 6 | How does a refunded order show in the sales report? | As a sale on the day it was paid, and as a refund on the day it was refunded | Phase 12 |
