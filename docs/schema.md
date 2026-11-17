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
