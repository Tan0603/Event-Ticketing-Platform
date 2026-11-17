# Database schema

Money columns are `bigint` minor units. Timestamps are `timestamptz` (UTC).
Every index and constraint has a one-line justification here.

## Central database (`ticketing`)

Holds only the list of tenants (and permanently failed jobs).

### `tenants`

| column | type | notes |
|---|---|---|
| id | varchar(30) PK | the slug (e.g. `sunsetfest`) |
| name | varchar | |
| status | varchar(20) | `provisioning` / `active` / `failed` |
| currency | char(3) | immutable after creation |
| timezone | varchar(64) | IANA name; used for dashboard/report day boundaries |
| fee_percent_bps | int | basis points, 500 = 5.00 % |
| fee_fixed_minor | int | minor units, 50 = 0.50 |
| webhook_secret | text | encrypted cast; generated during provisioning |
| failure_reason | text null | set when provisioning fails permanently |
| data | jsonb null | stancl virtual column (internal keys such as db name) |
| created_at, updated_at | timestamptz | |

| index / constraint | why |
|---|---|
| PRIMARY KEY (id) | uniqueness of the slug is what makes two racing sign-ups safe: exactly one INSERT wins |
| INDEX (status) | the scheduler fans out one job per `active` tenant |
| CHECK status IN (...) | a status outside the enum can never be stored |
| CHECK id ~ `^[a-z0-9][a-z0-9-]{1,28}[a-z0-9]$` | database-level backstop for the 3-30 char slug rule |
| CHECK fee_percent_bps >= 0 AND fee_fixed_minor >= 0 | negative fees are impossible |

Tenant database names: `tenant_` + id with `-` replaced by `_`. Ids cannot contain `_`,
so the mapping is collision-free and needs no quoted identifiers.

### `failed_jobs`

Permanent queue failures (Redis is the queue driver). `uuid` is UNIQUE so a failure is recorded once.

## Tenant databases (`tenant_{id}`)

Added feature by feature (users, events, ticket types, orders, tickets, ...).

### `users` (tenant database)

| column | type | notes |
|---|---|---|
| id | bigint PK | |
| name | varchar | |
| email | varchar | stored lowercase |
| password | varchar | bcrypt hash; never plaintext |
| role | varchar(20) | `admin` / `door_staff` |
| created_at, updated_at | timestamptz | |

| index / constraint | why |
|---|---|
| UNIQUE (email) | login lookup, and a retried provisioning can never create a second admin |
| CHECK role IN ('admin','door_staff') | a role outside the enum can never be stored |
| CHECK email = lower(email) | case-variants of one address cannot become two accounts |

### `personal_access_tokens` (tenant database)

Sanctum tokens. They live in the tenant database, so a token issued by tenant A does not exist in tenant B.
The table is created by a tenant migration only; Sanctum's own migration is never published to the central database.

| index / constraint | why |
|---|---|
| UNIQUE (token) | every authenticated request looks the token up by its SHA-256 hash |
| INDEX (tokenable_type, tokenable_id) | revoke / list all tokens of a user |
| INDEX (expires_at) | `sanctum:prune-expired` |

### `events` (tenant database)

| column | type | notes |
|---|---|---|
| id | bigint PK | |
| title, venue | varchar | |
| slug | varchar(120) | public URL key |
| description | text null | |
| starts_at, ends_at | timestamptz | stored in UTC, shown in the tenant timezone |
| status | varchar(20) | `draft` / `published` / `cancelled` |
| published_at, cancelled_at | timestamptz null | |

| index / constraint | why |
|---|---|
| UNIQUE (slug) | public lookup by slug; two events can never share one, even when two requests race |
| INDEX (status, starts_at) | public list (published, by start date) and the admin status filter |
| CHECK status IN (...) | a status outside the enum can never be stored |
| CHECK ends_at > starts_at | an event cannot end before it starts |

The timing phase (`upcoming` / `ongoing` / `ended`) is computed on read, never stored, so it cannot go stale.

### `ticket_types` (tenant database)

| column | type | notes |
|---|---|---|
| id | bigint PK | |
| event_id | bigint FK -> events | `ON DELETE RESTRICT` |
| name, description | varchar / text | |
| price_minor | bigint | integer minor units, never a float |
| quantity | int | total capacity |
| sold, held | int | changed ONLY by atomic conditional UPDATE |
| max_per_order | int | |
| sales_start_at, sales_end_at | timestamptz null | window; no end means "until the event ends" |
| sort_order | int | display order |

| index / constraint | why |
|---|---|
| INDEX (event_id, sort_order, id) | listing the tiers of an event in display order; also serves the FK |
| CHECK sold + held <= quantity | the last defence against overselling: even a buggy code path cannot break it |
| CHECK quantity >= 0 AND sold >= 0 AND held >= 0 | Postgres has no unsigned integers |
| CHECK price_minor >= 0, max_per_order >= 1 | no negative prices, at least one ticket per order |
| CHECK sales_end_at > sales_start_at (when both set) | a window cannot be empty or reversed |
| FK event_id RESTRICT | an event with tiers cannot vanish underneath them (the delete action removes tiers explicitly) |

The sales status (`on_sale` / `upcoming` / `ended` / `sold_out` / ...) is computed on read from the window, the
counters and the event state.

### `orders` (tenant database)

An order snapshots everything it needs: names, prices, fee settings and totals are copied when it is created and
never recomputed from `ticket_types` or the tenant settings.

| column | type | notes |
|---|---|---|
| id | bigint PK | |
| reference | varchar(20) | public, unguessable (`ORD-` + 12 random chars); the buyer's capability to see the order |
| event_id | bigint FK -> events | `ON DELETE RESTRICT` |
| buyer_name, buyer_email | varchar | e-mail stored lowercase |
| status | varchar(20) | `pending` / `paid` / `failed` / `expired` / `refunded`; written ONLY by `OrderStateMachine` |
| currency | char(3) | tenant currency at purchase time |
| subtotal_minor, fee_minor, total_minor | bigint | integer minor units |
| fee_percent_bps, fee_fixed_minor | int | the fee settings used, kept for audit and refunds |
| expires_at | timestamptz | end of the hold |
| paid_at, failed_at, expired_at, refunded_at | timestamptz null | stamped by the matching transition |
| idempotency_key | varchar(100) null | the buyer's `Idempotency-Key` |
| request_fingerprint | char(64) null | hash of the request body: a key reused with another body is refused |

| index / constraint | why |
|---|---|
| UNIQUE (reference) | looking an order up by its reference |
| UNIQUE (idempotency_key) | a retried checkout can never create a second order, even when two retries race (NULLs do not collide) |
| INDEX (event_id, status) | orders of an event by status: dashboard, reports and the "has orders" check |
| INDEX (buyer_email) | orders of one buyer |
| PARTIAL INDEX (expires_at) WHERE status = 'pending' | the expiry job only looks at pending orders: a tiny, always-hot index |
| CHECK status IN (...) | a status outside the enum can never be stored |
| CHECK total = subtotal + fee, no negatives | the money of an order always adds up |
| CHECK buyer_email = lower(buyer_email) | one e-mail, one spelling |

### `order_items` (tenant database)

| column | type | notes |
|---|---|---|
| id | bigint PK | |
| order_id | bigint FK -> orders | `ON DELETE CASCADE` |
| ticket_type_id | bigint FK -> ticket_types | `ON DELETE RESTRICT`: a tier that was ever bought cannot be deleted |
| ticket_type_name | varchar | snapshot |
| unit_price_minor | bigint | snapshot |
| quantity | int | > 0 |
| line_total_minor | bigint | = unit price x quantity |

| index / constraint | why |
|---|---|
| INDEX (order_id) | items of an order (every order read) |
| INDEX (ticket_type_id) | orders of a ticket type: audit of held counters, sales per tier |
| UNIQUE (order_id, ticket_type_id) | one line per ticket type per order |
| CHECK quantity > 0, line_total = unit_price x quantity | no empty or inconsistent lines |

### `webhook_events` (tenant database)

One row per payment notification we accepted, kept as an audit trail.

| column | type | notes |
|---|---|---|
| id | bigint PK | |
| event_id | varchar(100) | the provider's own id for the notification |
| type | varchar(40) | `payment.succeeded` / `payment.failed` |
| order_id | bigint FK -> orders null | `ON DELETE SET NULL` |
| outcome | varchar(40) | what we did: `paid`, `paid_late`, `failed`, `already_paid`, `amount_mismatch`, `late_payment_no_stock`, `unknown_order`, `ignored` |
| payload | jsonb | the verified body |

| index / constraint | why |
|---|---|
| UNIQUE (event_id) | exactly-once: the row is inserted first, in the same transaction as the effect, so a retry or a simultaneous duplicate finds it and does nothing |
| INDEX (order_id) | everything the provider told us about one order |

### `tickets` (tenant database)

One row per seat, created when an order becomes paid.

| column | type | notes |
|---|---|---|
| id | bigint PK | |
| order_id, order_item_id, ticket_type_id, event_id | bigint FK | all `RESTRICT`; `event_id` is copied so check-in needs no join |
| seq | int | 1..quantity within the order line |
| code | varchar(32) | random, shown in the QR code |
| status | varchar(16) | `valid` / `used` / `void` |
| checked_in_at, checked_in_by | timestamptz, FK users null | set together with `status = used` |

| index / constraint | why |
|---|---|
| UNIQUE (order_item_id, seq) | issuing twice (webhook retry, duplicate event) cannot create a second set |
| UNIQUE (code) | a QR code identifies exactly one ticket |
| INDEX (order_id) | tickets of an order (ticket page, e-mail) |
| INDEX (event_id, status) | attendee lists and check-in counts per event |
| INDEX (ticket_type_id) | tickets per tier (reports) |
| CHECK status IN (...) | no invalid status |
| CHECK (status = 'used') = (checked_in_at IS NOT NULL) | a used ticket always has a check-in time and vice versa |

### `order_notifications` (tenant database)

The claim that guarantees each e-mail is sent once per order and type.

| column | type | notes |
|---|---|---|
| order_id | bigint FK | `CASCADE` |
| type | varchar(30) | `confirmation`, `reminder`, `refund`, `event_cancelled` |
| status | varchar(16) | `pending` / `sent` / `failed` |
| attempts, sent_at, last_error | | delivery bookkeeping |

| index / constraint | why |
|---|---|
| UNIQUE (order_id, type) | the claim: the first insert wins, every later attempt is a no-op |
