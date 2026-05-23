# Scouter Public API — Design & Plan

> Status: **PROPOSAL** (no code yet). This document is the spec we agree on
> before implementation. Goal: a small, serious, secure REST API to drive the
> essentials — list projects, list a project's crawls, and run read-only SQL
> against a crawl — authenticated with per-user API keys generated from the
> admin Settings page. Designed so an **MCP server** can sit on top later
> (each endpoint maps cleanly to an MCP tool).

---

## 1. Principles

- **Token auth, not sessions.** The public API never uses cookies. Clients send
  `Authorization: Bearer <token>`. This is what an MCP server / scripts need.
- **Keys act as their owner.** A key inherits the role + permissions of the user
  who created it. Admin key → sees everything (all projects). A non-admin key
  (future) → only that user's projects. We reuse the EXISTING authorization
  (`Auth::requireCrawlAccess`, role checks) by "logging in as the key's owner"
  for the duration of the request — zero new authz logic to get wrong.
- **Read-only & safe by default.** No mutations in v1 (no crawl launch, no
  deletes). The SQL endpoint reuses the SQL Explorer engine verbatim:
  SELECT/WITH only, table whitelist, forbidden keywords/functions, READ ONLY
  transaction, statement timeout, project-scoped `crawl_categories`, partition
  substitution. **Nothing the SQL Explorer forbids becomes possible here.**
- **Versioned.** All public routes live under `/api/v1/…` so we can evolve
  without breaking the MCP server.
- **Boring, predictable JSON.** Consistent envelope, correct HTTP status codes,
  no stack traces, never leak tokens.

---

## 2. Authentication & key management

### 2.1 Token format

```
sctr_<43 url-safe base64 chars>      e.g. sctr_8mK2…_qZ
```

- `sctr_` prefix → instantly recognizable in logs/configs, easy to grep/scan
  for accidental leaks (GitHub secret scanning friendly).
- Body = 32 bytes from a CSPRNG (`random_bytes(32)`), base64url-encoded, no
  padding → 256 bits of entropy. Unguessable.

### 2.2 Storage — HASHED, never reversible

API keys are **hashed**, not encrypted (unlike the OpenRouter key, which we must
replay to OpenRouter and therefore encrypt). We only ever need to *verify* a
presented token, never to read it back — so we treat it like a password.

New table **`api_keys`**:

| column         | type                    | notes                                            |
|----------------|-------------------------|--------------------------------------------------|
| `id`           | SERIAL PK               |                                                  |
| `user_id`      | INT FK → users(id)      | ON DELETE CASCADE. The key acts as this user.    |
| `name`         | VARCHAR(100)            | human label ("MCP server", "n8n", …)            |
| `prefix`       | VARCHAR(16) (indexed)   | first chars of the token, for O(1) lookup + UI display |
| `token_hash`   | CHAR(64)                | `hash('sha256', token)` — the verification value |
| `last_used_at` | TIMESTAMP NULL          | updated (throttled) on each successful call      |
| `created_at`   | TIMESTAMP               |                                                  |
| `revoked_at`   | TIMESTAMP NULL          | soft-revoke; `NULL` = active                     |

- **Lookup**: extract `prefix` from the presented token → `SELECT … WHERE prefix = ? AND revoked_at IS NULL` → `hash_equals($row.token_hash, sha256(presented))` (constant-time). Prefix makes it indexed/fast; the hash compare makes it secure.
- SHA-256 (not bcrypt) is the right call for high-entropy random tokens: 256-bit
  random input isn't brute-forceable, and we want fast per-request verification.
  (bcrypt is for low-entropy human passwords.)
- The **plaintext token is shown exactly once**, at creation. After that, only
  the prefix + name + dates are retrievable. Lost key → revoke + create new.

### 2.3 Management endpoints (session-auth, admin-only, used by the Settings UI)

These are NOT part of the public token API — they're the existing internal API
(cookie session + `admin => true`), called from the Settings page.

| Method | Path                 | Action                                             |
|--------|----------------------|----------------------------------------------------|
| `GET`    | `/api/keys`        | List the admin's keys (metadata only, no token)    |
| `POST`   | `/api/keys`        | Create a key `{ name }` → returns the plaintext **once** |
| `DELETE` | `/api/keys/{id}`   | Revoke a key (sets `revoked_at`)                   |

- **Regenerate** = revoke old + create new (no separate endpoint needed).
- Settings UI: a "API keys" card → "Generate key" (modal shows the token once
  with a copy button + warning), a table of existing keys (name, prefix `sctr_8mK2…`,
  created, last used) with a "Revoke" button.
- Scope for v1: only **admins** can create keys (matches your need). The schema
  is per-user, so opening it to editors later is trivial.

### 2.4 Request authentication flow (public API)

A new router option `'token' => true` (instead of `'auth' => true`):

1. Read `Authorization: Bearer <token>`. Missing/malformed → `401`.
2. Parse `prefix`; look up active key; `hash_equals` compare → no match → `401`.
3. Load the key's user; **set the Auth context to that user** for this request
   (so `Auth::requireCrawlAccess`, role checks, etc. all work unchanged).
4. Update `last_used_at` (throttled to ≤ once/minute to avoid a write per call).
5. Proceed to the handler.

Failures return a clean `401` with `WWW-Authenticate: Bearer`. Never reveal
whether the prefix existed.

---

## 3. Endpoints (v1)

Base URL: `https://<host>/api/v1`. All require `Authorization: Bearer <token>`.

### 3.1 `GET /api/v1/projects`

List projects accessible to the key's owner (admin → all; otherwise owned +
shared). Reuses the role-based logic already in `ProjectController::index`.

Query params: `?limit=50&offset=0` (pagination; defaults below).

```json
{
  "data": [
    { "id": 32, "domain": "example.com", "name": "Example",
      "crawl_count": 12, "last_crawl_at": "2026-05-20T18:42:11Z" }
  ],
  "meta": { "limit": 50, "offset": 0, "total": 7 }
}
```

### 3.2 `GET /api/v1/projects/{id}/crawls`

List the crawls of one project. `403` if the user can't access the project,
`404` if it doesn't exist. Pagination same as above.

```json
{
  "data": [
    { "id": 542, "status": "finished", "crawl_type": "spider",
      "urls": 12847, "crawled": 12412, "compliant": 9981,
      "started_at": "2026-05-20T09:42:11Z", "finished_at": "2026-05-20T11:08:39Z" }
  ],
  "meta": { "limit": 50, "offset": 0, "total": 12, "project_id": 32 }
}
```

### 3.3 `POST /api/v1/crawls/{id}/query` — read-only SQL, **paginated**

Run a **read-only** SQL query scoped to one crawl. This is the powerhouse —
everything in a crawl is reachable via SQL, so this single endpoint covers
"extract anything". `403`/`404` on access/existence as above.

**Pagination is server-owned.** A crawl can have millions of rows, so the API —
not the caller — controls the page size. The client provides a plain `SELECT`
(no `LIMIT`/`OFFSET` needed) plus `page` / `page_size`, and **loops over pages**
until it has everything.

Request:
```json
{
  "query": "SELECT url, code FROM pages WHERE code >= 400 ORDER BY inlinks DESC",
  "page": 1,
  "page_size": 500
}
```

- `page` (default `1`, ≥ 1).
- `page_size` (default `100`, **max `1000`** — clamped server-side).
- `count` (optional, default `true`): set `false` to SKIP the total-count query
  (faster when the caller only wants to stream pages — see the cost note below).

**How it runs (server side):** after the query passes the same validation +
transformation as the SQL Explorer, it is wrapped:
- total (when `count=true`): `SELECT COUNT(*) FROM ( <validated query> ) _c`
- page: `SELECT * FROM ( <validated query> ) _p LIMIT :page_size OFFSET :offset`
  with `offset = (page - 1) * page_size`.

This makes pagination work for **any** SELECT regardless of its own ORDER BY /
GROUP BY. If the caller does include their own `LIMIT`, it acts as an inner cap
inside the subquery (predictable, documented). The whole thing runs in a single
`READ ONLY` transaction with a statement timeout.

Validation = **exactly** the SQL Explorer rules, reusing `App\AI\SqlExecutor`:
- Starts with `SELECT` or `WITH … SELECT`. No `WITH RECURSIVE`.
- Forbidden keywords (INSERT/UPDATE/DELETE/DROP/…) + forbidden functions
  (`pg_sleep`, `pg_read_file`, `dblink`, `lo_import`, …).
- Table whitelist: `pages`, `links`, `crawl_categories`, `duplicate_clusters`,
  `page_schemas`, `redirect_chains` (+ `…@<id>` cross-crawl within the SAME
  project). System tables (`users`, `crawls`, `information_schema`, …) blocked.
- Virtual names auto-substituted to the crawl's partitions (`pages` → `pages_<id>`).
- `crawl_categories` auto-scoped to the project; a user-defined CTE of that name
  is rejected (reserved).

Response:
```json
{
  "data": {
    "columns": ["url", "code"],
    "rows": [ { "url": "https://…", "code": 404 } ]
  },
  "meta": {
    "crawl_id": 542,
    "page": 1,
    "page_size": 500,
    "returned": 500,
    "total": 8734,
    "total_pages": 18,
    "has_more": true
  }
}
```

- When `count=false`: `total`/`total_pages` are `null`, and `has_more` is derived
  from whether `returned == page_size` (i.e. a full page came back).
- The client loops: request `page=1,2,…` until `has_more` is `false`.

**Cost note (documented trade-off):** `COUNT(*)` over a multi-million-row result
is not free — Postgres still has to evaluate the underlying query. That's why
`page_size` is capped and `count=false` is offered (compute the total once on
page 1, reuse it client-side; or skip it entirely for pure streaming). For the
typical "export everything" loop, counting once and paging is the right balance.
*(Keyset/cursor pagination would be cheaper on huge tables but can't be applied
generically to an arbitrary user-supplied ORDER BY — a possible future opt-in,
not v1.)*

Errors (validation/SQL) → `422 Unprocessable Entity` with the SQL Explorer
reason (`{ "success": false, "error": "Only SELECT or WITH … SELECT statements are allowed." }`).

### 3.4 `GET /api/v1/crawls/{id}/schema`

Returns the queryable tables + their columns for a crawl, so a client (or an MCP
/ Claude) can write valid SQL without guessing. Read-only, no risk.

```json
{
  "data": {
    "tables": {
      "pages":   [ { "name": "url", "type": "text" }, { "name": "code", "type": "integer" }, … ],
      "links":   [ … ],
      "crawl_categories": [ … ],
      "duplicate_clusters": [ … ],
      "page_schemas": [ … ],
      "redirect_chains": [ … ]
    },
    "notes": "Use `pages`, `links`, … (virtual names). Cross-crawl in the same project via `pages@<id>`. SELECT-only."
  },
  "meta": { "crawl_id": 542 }
}
```

### 3.5 `GET /api/v1/crawls/{id}` — crawl metadata

Single crawl details (status, type, counts, dates, key config flags). Tidy for
the MCP to introduce a crawl before querying it.

```json
{
  "data": {
    "id": 542, "project_id": 32, "domain": "example.com", "status": "finished",
    "crawl_type": "spider", "urls": 12847, "crawled": 12412, "compliant": 9981,
    "started_at": "2026-05-20T09:42:11Z", "finished_at": "2026-05-20T11:08:39Z"
  }
}
```

### 3.6 Categorization — `GET` / `PUT /api/v1/crawls/{id}/categorization`

Categories are the unit of SEO analysis (page templates: homepage / product /
category / blog_post / legal …). They are defined by a **YAML** rule set stored
at the project level and applied to every crawl of the project.

**`GET`** returns the current rules (`config`), the categories actually applied
to *this* crawl (`name` `null` = uncategorized bucket), and a `deployment` block
(state of the most recent project-wide propagation). Requires project access.

```json
{
  "data": {
    "crawl_id": 542, "project_id": 32,
    "config": "homepage:\n  dom: example.com\n  include:\n    - ^/?$\n  color: '#4ecdc4'\n…",
    "categories": [
      { "name": "product",  "color": "#6bd899", "count": 8123 },
      { "name": "category", "color": "#d8bf6b", "count": 1442 },
      { "name": null,        "color": null,      "count": 57 }
    ],
    "deployment": { "status": "completed", "job_id": 991, "progress": 100 }
  },
  "meta": { "crawl_id": 542 }
}
```

**`PUT`** sets (replaces) the rules. It saves at project level, applies them
**synchronously** to the target crawl (so its `categories` are correct the moment
the call returns), then queues an async job to re-categorize the project's
**other** crawls. Requires project **management**. Body:

| field | type | notes |
|---|---|---|
| `yaml` | string (required) | The rule set. Each category: `include` (regex list on URL **path**), optional `exclude`, `color`, optional `dom`. **First match wins → order matters.** Patterns are PostgreSQL POSIX (`~*`, case-insensitive). |
| `deploy_to_project` | bool (default `true`) | `false` = apply to this crawl only, no project-wide deploy. |

`dom` may be **omitted** — Scouter fills it with the crawl's domain (the `{dom}`
placeholder is also accepted). An explicit `dom` is honoured as-is. Invalid YAML →
`400`; a bad regex pattern → `422`.

```jsonc
// PUT body
{
  "yaml": "homepage:\n  include:\n    - ^/?$\n  color: '#4ecdc4'\nproduct:\n  include:\n    - ^/p/[0-9]+\n  color: '#6bd899'\nother:\n  include:\n    - .*\n  color: '#cccccc'",
  "deploy_to_project": true
}
// → response
{
  "data": {
    "crawl_id": 542, "project_id": 32, "categorized_count": 9622,
    "deploy_to_project": true,
    "deployment": { "status": "running", "job_id": 991, "progress": 0, "other_crawls": 3 }
  }
}
```

Poll `GET …/categorization` and watch `deployment.status`
(`running` → still propagating; `completed` / `idle` → done; `failed`) to know
when the project-wide deploy has finished.

---

## 4. Conventions

- **Status codes**: `200` ok, `400` bad request (missing/invalid params),
  `401` missing/invalid token, `403` authenticated but no access, `404` not
  found, `422` invalid SQL, `429` rate-limited, `500` server error.
- **Envelope**: success `{ "data": …, "meta": … }`; error `{ "success": false, "error": "…" }`.
  (Aligns with the existing `Response::error`; success uses a `data` wrapper for
  a cleaner public contract. We can also keep `Response::success`'s shape if you
  prefer one consistent style — your call.)
- **Pagination**: `limit` (default 50, max 200) + `offset`; `meta.total` for the count.
- **Timestamps**: ISO-8601 UTC.
- **Content type**: `application/json` in and out.
- **CORS**: off by default (server-to-server / MCP). Can allowlist later.

---

## 5. Security checklist

- [x] 256-bit CSPRNG tokens, `sctr_` prefixed.
- [x] Hashed at rest (SHA-256), constant-time compare, prefix-indexed lookup.
- [x] Shown once; revocable; `last_used_at` tracked.
- [x] Read-only everywhere; SQL endpoint reuses the hardened SQL Explorer engine.
- [x] Authorization reuses existing per-project checks (key = its owner).
- [x] Tokens never logged, never returned after creation.
- [x] HTTPS assumed (enforce at the proxy); `WWW-Authenticate: Bearer` on 401.
- [x] **Rate limiting** per key (v1): default 120 req/min, `429` + `Retry-After`
      via a lightweight per-key sliding counter (DB or APCu).

---

## 6. Implementation outline (when approved)

1. **Migration**: `api_keys` table + indexes (`prefix`, `user_id`). (Rate-limit
   counter: APCu in-process, or a tiny `api_key_hits(api_key_id, window_started_at, count)`
   row if we want it durable/multi-worker — decide at build time.)
2. **`ApiKeyService`** (`app/Api/`): `generate(userId, name)`,
   `verify(token): ?user`, `revoke(id, userId)`, `listForUser(userId)`,
   `touchLastUsed(id)` (throttled), `rateLimit(keyId): bool`. Hashing +
   constant-time compare live here.
3. **Router**: add `'token' => true` in `applyAuth()` → `ApiKeyService::verify`,
   set Auth context to the key's user, enforce rate limit, else `401`/`429`.
4. **`Api/V1/` controllers**: `ProjectsApiController` (list), `CrawlsApiController`
   (list-by-project, metadata, schema), `QueryApiController` (paginated SQL).
   Thin — reuse `ProjectRepository`, `CrawlRepository`, `SqlExecutor`. Routes under
   `/api/v1/…` with `'token' => true`.
5. **`SqlExecutor`**: add a paginated mode (`page_size` + `offset`, optional
   `COUNT(*)` over the validated subquery) returning rows + total, keeping all
   existing validation/scoping. Used by `QueryApiController` (and reusable by the
   SQL Explorer later).
6. **Key management**: `ApiKeyController` (session-auth, admin) for `/api/keys`
   CRUD; Settings UI card (generate → show-once modal, list, revoke).
7. **Tests** (Pest): token generate→verify→revoke round-trip, constant-time
   reject of a wrong token, `verify` ignores revoked keys, rate-limit trips at the
   threshold, pagination math (total/total_pages/has_more) + `count=false`, and the
   SQL endpoint inherits the SQL Explorer security cases.
8. **Docs**: keep this file updated; generate an OpenAPI 3 spec (`openapi.yaml`)
   to feed the MCP server.

---

## 7. Decisions (agreed)

1. **Schema endpoint — IN v1.** `GET /api/v1/crawls/{id}/schema` (§3.4). Makes the
   MCP/Claude write valid SQL without guessing column names.
2. **Crawl metadata endpoint — IN v1.** `GET /api/v1/crawls/{id}` (§3.5).
3. **Rate limiting — IN v1.** Per-key, default **120 req/min**, returns `429` +
   `Retry-After`. Implementation: a lightweight per-key sliding counter (DB or
   APCu); see §6.
4. **Response envelope — `{ "data": …, "meta": … }`** for the public v1 API
   (errors keep `{ "success": false, "error": … }`). Cleaner public contract; the
   internal `/api/*` routes keep their existing shape.
5. **Key generation — admins only** in v1. Schema is per-user, so opening it to
   editors later needs no migration.
6. **Pagination on the SQL endpoint — IN v1** (§3.3): server-owned page size,
   real total via `COUNT` (skippable with `count=false`), client loops pages.
7. **Crawl launch — parked.** Future `POST /api/v1/projects/{id}/crawls`.

---

## 7b. Where the usage docs live (3 layers)

`API.md` (this file) is the **design spec** — internal, for us. The **usage**
docs (how to call the API) live in three complementary places:

1. **Human, discoverable — an "API" card in the Settings page**, right under the
   API-keys management (so you read how to use it exactly when you create a key):
   base URL, `Authorization: Bearer sctr_…` header, the 5 endpoints each with one
   `curl` example, and the pagination loop example. Short, copy-pasteable.
2. **Machine — `openapi.yaml` (OpenAPI 3)**: the single source of truth that stays
   in sync and **feeds the MCP server** (tool generation). Later we can serve a
   Swagger UI / Redoc page at `GET /api/docs` (pure render of the spec) for an
   interactive browser — no extra maintenance.
3. **Self-describing root — `GET /api/v1`**: returns a small JSON index of the
   available endpoints + version. Cheap, REST-idiomatic, handy for introspection
   and the MCP.

v1 ships layers 1–3; Swagger UI is a later "if needed".

## 8. Forward look: MCP server

The endpoints map 1:1 to MCP tools:

- `list_projects()` → `GET /projects`
- `list_crawls(project_id)` → `GET /projects/{id}/crawls`
- `get_crawl(crawl_id)` → `GET /crawls/{id}`
- `describe_crawl(crawl_id)` → `GET /crawls/{id}/schema` (column names → valid SQL)
- `query_crawl(crawl_id, sql, page?, page_size?)` → `POST /crawls/{id}/query`
  (the tool loops pages on `has_more` to assemble large extracts)

The MCP server is then a thin adapter holding one `sctr_…` token and translating
tool calls into these HTTP requests. Building the API right (clean JSON, stable
versioned routes, server-owned pagination, SQL = universal extractor) is exactly
what makes the MCP layer trivial.
