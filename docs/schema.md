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
