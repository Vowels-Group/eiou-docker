# eIOU API Quick Reference

Quick lookup card for the eIOU REST API endpoints.

---

## Authentication

All requests require HMAC-SHA256 authentication:

| Header | Value |
|--------|-------|
| `X-API-Key` | Your API key ID (`eiou_...`) |
| `X-API-Timestamp` | Unix timestamp (seconds) |
| `X-API-Nonce` | Unique request ID (8-64 chars) |
| `X-API-Signature` | HMAC-SHA256 signature |

**Signature:** `HMAC-SHA256(secret, METHOD\nPATH\nTIMESTAMP\nNONCE\nBODY)`

---

## Endpoint Summary

### Wallet Endpoints

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/api/v1/wallet/balance` | `wallet:read` | Get balances by contact |
| `GET` | `/api/v1/wallet/balances` | `wallet:read` | Alias for balance |
| `GET` | `/api/v1/wallet/info` | `wallet:read` | Get wallet public key and addresses |
| `GET` | `/api/v1/wallet/overview` | `wallet:read` | Dashboard summary (balances, credit, recent tx) |
| `GET` | `/api/v1/wallet/transactions` | `wallet:read` | Paginated transaction history |
| `POST` | `/api/v1/wallet/send` | `wallet:send` | Send a transaction |

### Contact Endpoints

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/api/v1/contacts` | `contacts:read` | List all contacts |
| `POST` | `/api/v1/contacts` | `contacts:write` | Add new contact |
| `GET` | `/api/v1/contacts/pending` | `contacts:read` | Get pending requests |
| `GET` | `/api/v1/contacts/search` | `contacts:read` | Search contacts by name |
| `POST` | `/api/v1/contacts/ping/:address` | `contacts:read` | Ping contact status + exchange per-currency credit |
| `GET` | `/api/v1/contacts/:address` | `contacts:read` | Get contact details |
| `PUT` | `/api/v1/contacts/:address` | `contacts:write` | Update contact |
| `DELETE` | `/api/v1/contacts/:address` | `contacts:write` | Delete contact |
| `POST` | `/api/v1/contacts/block/:address` | `contacts:write` | Block contact |
| `POST` | `/api/v1/contacts/unblock/:address` | `contacts:write` | Unblock contact |
| `POST` | `/api/v1/contacts/:hash/decisions` | `contacts:write` | Apply batched accept/decline/defer decisions (mirrors `eiou contact apply`) |
| `POST` | `/api/v1/contacts/:hash/decline` | `contacts:write` | Decline every pending currency on a contact request |
| `GET` | `/api/v1/contacts/:hash/currencies` | `contacts:read` | List every currency configured for a contact (status + direction) |
| `POST` | `/api/v1/contacts/:hash/currencies` | `contacts:write` | Add a new currency to an already-accepted contact |
| `POST` | `/api/v1/contacts/:hash/currency-accept` | `contacts:write` | Accept a single pending currency |
| `POST` | `/api/v1/contacts/:hash/currency-decline` | `contacts:write` | Decline a single pending currency |
| `POST` | `/api/v1/contacts/:hash/currency-remove` | `contacts:write` | Locally remove a currency from a contact (no peer notification) |

### Payment Request Endpoints

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/api/v1/requests` | `wallet:read` | List all payment requests (incoming + outgoing) |
| `POST` | `/api/v1/requests` | `wallet:send` | Create and send a payment request to a contact |
| `POST` | `/api/v1/requests/approve` | `wallet:send` | Approve an incoming request — triggers `sendEiou` automatically |
| `POST` | `/api/v1/requests/decline` | `wallet:send` | Decline an incoming request |
| `DELETE` | `/api/v1/requests/{request_id}` | `wallet:send` | Cancel an outgoing request (pending only) |

### System Endpoints

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/api/v1/system/status` | `system:read` | System health status |
| `GET` | `/api/v1/system/metrics` | `system:read` | System metrics |
| `GET` | `/api/v1/system/settings` | `system:read` | System settings |
| `PUT` | `/api/v1/system/settings` | `system:write` | Update system settings |
| `POST` | `/api/v1/system/sync` | `system:write` | Trigger sync operation |
| `POST` | `/api/v1/system/shutdown` | `system:write` | Shutdown background processors |
| `POST` | `/api/v1/system/start` | `system:write` | Start background processors |
| `POST` | `/api/v1/system/restart` | `system:write` | Full in-place restart (processors + PHP-FPM workers); required after toggling plugins |
| `POST` | `/api/v1/system/update-check` | `system:read` | Force a fresh Docker Hub / GitHub release check (bypasses 24h cache) |
| `GET` | `/api/v1/system/debug-report` | `system:read` | Download debug report (JSON) |
| `POST` | `/api/v1/system/debug-report` | `system:read` | Submit debug report to support |

### P2P Approval Endpoints

Active when `autoAcceptTransaction` is OFF. Fast mode shows 1 route; best-fee mode lists all routes. Tor destinations use fast mode internally.

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/api/v1/p2p` | `wallet:read` | List P2P transactions awaiting approval |
| `GET` | `/api/v1/p2p/candidates/{hash}` | `wallet:read` | Get route candidates for a transaction |
| `POST` | `/api/v1/p2p/approve` | `wallet:send` | Approve a P2P transaction |
| `POST` | `/api/v1/p2p/reject` | `wallet:send` | Reject a P2P transaction |

### Tx Drop Endpoints

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/api/v1/chaindrop` | `wallet:read` | List tx drop proposals |
| `POST` | `/api/v1/chaindrop/propose` | `wallet:send` | Propose tx drop with contact |
| `POST` | `/api/v1/chaindrop/accept` | `admin` | Accept tx drop proposal — **irreversible chain rewrite**, gated stricter than propose/reject |
| `POST` | `/api/v1/chaindrop/reject` | `wallet:send` | Reject tx drop proposal |

### Backup Endpoints

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/api/v1/backup/status` | `backup:read` | Get backup status and settings |
| `GET` | `/api/v1/backup/list` | `backup:read` | List all backup files |
| `POST` | `/api/v1/backup/create` | `backup:write` | Create encrypted backup |
| `POST` | `/api/v1/backup/restore` | `backup:write` | Restore from backup (requires confirm) |
| `POST` | `/api/v1/backup/verify` | `backup:read` | Verify backup integrity |
| `DELETE` | `/api/v1/backup/:filename` | `backup:write` | Delete a backup file |
| `POST` | `/api/v1/backup/enable` | `backup:write` | Enable automatic backups |
| `POST` | `/api/v1/backup/disable` | `backup:write` | Disable automatic backups |
| `POST` | `/api/v1/backup/cleanup` | `backup:write` | Remove old backups |

### Payback Methods Endpoints

Settlement-rail metadata you offer contacts (bank wire, custom free-text, plugin-provided types). Sensitive fields are encrypted at rest per row; the `reveal` endpoint is gated as a write-class operation since it returns plaintext.

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/api/v1/payback-methods` | `payback:read` | List your enabled methods (`?currency=USD`, `?all=1`) |
| `POST` | `/api/v1/payback-methods` | `payback:write` | Create a new method |
| `GET` | `/api/v1/payback-methods/:id` | `payback:read` | Method metadata (sensitive fields redacted) |
| `GET` | `/api/v1/payback-methods/:id/reveal` | `payback:write` | Decrypt and return all fields in plaintext |
| `PUT` | `/api/v1/payback-methods/:id` | `payback:write` | Re-enter type-specific fields |
| `PUT` | `/api/v1/payback-methods/:id/share-policy` | `payback:write` | Update share policy (`auto` / `prompt` / `never`) |
| `DELETE` | `/api/v1/payback-methods/:id` | `payback:write` | Permanently delete a method |

### Plugin Endpoints (Admin)

Toggling a plugin's enabled flag does not restart the node — call `POST /api/v1/system/restart` after to apply.

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/api/v1/plugins` | `admin` | List installed plugins with full metadata |
| `POST` | `/api/v1/plugins/:name/enable` | `admin` | Persist enabled flag = true (no restart) |
| `POST` | `/api/v1/plugins/:name/disable` | `admin` | Persist enabled flag = false (no restart) |
| `DELETE` | `/api/v1/plugins/:name` | `admin` | Uninstall (must be disabled first) |
| `*` | `/api/v1/plugins/:name/:action` | per-plugin | Plugin-owned routes (gating set by the registering plugin) |

### API Key Management (Admin)

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/api/v1/keys` | `admin` | List all API keys |
| `POST` | `/api/v1/keys` | `admin` | Create new API key |
| `DELETE` | `/api/v1/keys/:key_id` | `admin` | Delete API key |
| `POST` | `/api/v1/keys/enable/:key_id` | `admin` | Enable API key |
| `POST` | `/api/v1/keys/disable/:key_id` | `admin` | Disable API key |

---

## Permissions

| Permission | Scope |
|------------|-------|
| `wallet:read` | Read wallet balances and transactions |
| `wallet:send` | Send transactions, propose/accept/reject chain drops, approve/reject P2P |
| `wallet:*` | Both `wallet:read` and `wallet:send` |
| `contacts:read` | List, view, search, and ping contacts |
| `contacts:write` | Add, update, delete, block/unblock contacts; per-currency operations |
| `contacts:*` | Both `contacts:read` and `contacts:write` |
| `system:read` | Read system status, metrics, and settings; download debug reports; trigger update-check |
| `system:write` | Trigger sync, shutdown/start/restart, change settings (operational control of this node) |
| `system:*` | Both `system:read` and `system:write` |
| `backup:read` | Read backup status/list, verify backups |
| `backup:write` | Create, restore, delete, enable/disable backups, cleanup |
| `backup:*` | Both `backup:read` and `backup:write` |
| `payback:read` | List/read your own payback methods (sensitive fields redacted) |
| `payback:write` | Create/edit/delete methods, **and reveal plaintext via `GET …/:id/reveal`** (write-class because it returns secrets) |
| `payback:*` | Both `payback:read` and `payback:write` |
| `admin` | Full administrative access (settings, sync, shutdown/start/restart, keys, plugins). Implies every other scope. |

> **Wildcard semantics:** at lookup time, `<category>:*` grants any `<category>:<verb>` request — so a key with `wallet:*` covers a route that requires `wallet:read` or `wallet:send`. `admin` is the only god-scope and grants everything unconditionally. There is currently no `plugin:*` scope; key management and plugin management gate on `admin`. Operational control (sync, shutdown/start/restart, settings PUT) was carved out from `admin` into `system:write`. (The legacy `'all'` synonym was removed in favour of `admin`.)

---

## Common Query Parameters

### Pagination

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `limit` | int | 50 | Number of items (per-endpoint cap — see below) |
| `offset` | int | 0 | Skip first N items |

**Per-endpoint `limit` caps:**

| Endpoint | Cap |
|----------|-----|
| `/api/v1/wallet/transactions` | 100 |
| `/api/v1/contacts/search` | 100 |
| `/api/v1/requests` | 200 |
| `/api/v1/wallet/overview` (`transaction_limit`) | 20 |
| All others (default) | 100 |

### Filtering

| Endpoint | Parameter | Values |
|----------|-----------|--------|
| `/contacts` | `status` | `pending`, `accepted`, `blocked` |
| `/transactions` | `type` | `sent`, `received`, `relay` |
| `/transactions` | `contact` | Contact name or address |
| `/chaindrop` | `contact` | Contact name or address |
| `/contacts/search` | `q` or `query` | Search term |

---

## Request Bodies

### POST /api/v1/wallet/send

```json
{
    "address": "http://bob.local:8080",
    "amount": 25.00,
    "currency": "USD",
    "description": "Optional description",
    "best_fee": false
}
```

### POST /api/v1/contacts

```json
{
    "address": "http://bob.local:8080",
    "name": "Bob",
    "fee_percent": 1.0,
    "credit_limit": 100.00,
    "currency": "USD",
    "requested_credit_limit": 500.00
}
```

### PUT /api/v1/contacts/:address

All fields optional. `currency` required when updating `fee_percent` or `credit_limit`.

```json
{
    "name": "New Name",
    "fee_percent": 1.5,
    "credit_limit": 200.00,
    "currency": "USD"
}
```

### POST /api/v1/keys

```json
{
    "name": "My Application",
    "permissions": ["wallet:read", "contacts:read"],
    "rate_limit_per_minute": 100,
    "expires_at": "2027-01-01T00:00:00Z"
}
```

### PUT /api/v1/system/settings

```json
{
    "default_fee": 1.5,
    "default_credit_limit": 200.00,
    "hostname": "http://mynode"
}
```

### POST /api/v1/system/sync

```json
{
    "type": "contacts"
}
```

### GET /api/v1/system/debug-report

Query params: `?full=1&description=login%20crash`

### POST /api/v1/system/debug-report

```json
{
    "description": "login page crash",
    "full": false
}
```

> Scrubs sensitive data and submits via Tor. Rate-limited to 3/day. Returns a reference `key` on success.

### POST /api/v1/p2p/approve

```json
{
    "hash": "abc123def456",
    "candidate_id": 5
}
```

> `candidate_id` is optional. Omit for fast mode (single route). Required when multiple candidates exist.

### POST /api/v1/p2p/reject

```json
{
    "hash": "abc123def456"
}
```

### POST /api/v1/chaindrop/propose

```json
{
    "contact": "Bob"
}
```

### POST /api/v1/chaindrop/accept | reject

```json
{
    "proposal_id": "cd_abc123"
}
```

### POST /api/v1/backup/create

```json
{
    "name": "pre_upgrade_backup"
}
```

### POST /api/v1/backup/restore

```json
{
    "filename": "backup_20260124_030000.eiou.enc",
    "confirm": true
}
```

### POST /api/v1/backup/verify

```json
{
    "filename": "backup_20260124_030000.eiou.enc"
}
```

---

## Response Format

### Success

```json
{
    "success": true,
    "data": { ... },
    "timestamp": "2026-01-24T12:00:00Z",
    "request_id": "req_abc123"
}
```

### Error

```json
{
    "success": false,
    "error": {
        "code": "error_code",
        "message": "Human-readable message"
    },
    "timestamp": "2026-01-24T12:00:00Z",
    "request_id": "req_abc123"
}
```

---

## HTTP Status Codes

| Code | Meaning |
|------|---------|
| `200` | Success |
| `201` | Created (POST success) |
| `400` | Bad Request (validation error) |
| `401` | Unauthorized (auth failed) |
| `403` | Forbidden (permission denied) |
| `404` | Not Found |
| `429` | Too Many Requests (rate limited) |
| `500` | Internal Server Error |

---

## Quick Examples

### Bash - Get Balance

```bash
API_KEY="eiou_xxx"
API_SECRET="your_secret"
TIMESTAMP=$(date +%s)
NONCE=$(openssl rand -hex 16)
PATH="/api/v1/wallet/balance"
SIGNATURE=$(echo -en "GET\n$PATH\n$TIMESTAMP\n$NONCE\n" | openssl dgst -sha256 -hmac "$API_SECRET" | cut -d' ' -f2)

curl "http://localhost:8080$PATH" \
  -H "X-API-Key: $API_KEY" \
  -H "X-API-Timestamp: $TIMESTAMP" \
  -H "X-API-Nonce: $NONCE" \
  -H "X-API-Signature: $SIGNATURE"
```

### Bash - Send Transaction

```bash
BODY='{"address":"http://bob:8080","amount":25,"currency":"USD"}'
PATH="/api/v1/wallet/send"
NONCE=$(openssl rand -hex 16)
SIGNATURE=$(echo -en "POST\n$PATH\n$TIMESTAMP\n$NONCE\n$BODY" | openssl dgst -sha256 -hmac "$API_SECRET" | cut -d' ' -f2)

curl -X POST "http://localhost:8080$PATH" \
  -H "X-API-Key: $API_KEY" \
  -H "X-API-Timestamp: $TIMESTAMP" \
  -H "X-API-Nonce: $NONCE" \
  -H "X-API-Signature: $SIGNATURE" \
  -H "Content-Type: application/json" \
  -d "$BODY"
```

---

## Rate Limits

- Default: 100 requests per minute per API key
- Configurable per key via `rate_limit_per_minute`
- Returns `429 Too Many Requests` when exceeded

---

## See Also

- [Full API Reference](API_REFERENCE.md) - Complete documentation
- [CLI Reference](CLI_REFERENCE.md) - Command-line interface
- [Error Codes](ERROR_CODES.md) - All error codes
