# EIOU API Quick Reference

Quick lookup card for the EIOU REST API endpoints.

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

### System Endpoints

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/api/v1/system/status` | `system:read` | System health status |
| `GET` | `/api/v1/system/metrics` | `system:read` | System metrics |
| `GET` | `/api/v1/system/settings` | `system:read` | System settings |
| `PUT` | `/api/v1/system/settings` | `admin` | Update system settings |
| `POST` | `/api/v1/system/sync` | `admin` | Trigger sync operation |
| `POST` | `/api/v1/system/shutdown` | `admin` | Shutdown background processors |
| `POST` | `/api/v1/system/start` | `admin` | Start background processors |

### P2P Approval Endpoints

Active when `autoAcceptTransaction` is OFF. Fast mode shows 1 route; best-fee mode lists all routes. Tor destinations use fast mode internally.

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/api/v1/p2p` | `wallet:read` | List P2P transactions awaiting approval |
| `GET` | `/api/v1/p2p/candidates/{hash}` | `wallet:read` | Get route candidates for a transaction |
| `POST` | `/api/v1/p2p/approve` | `wallet:send` | Approve a P2P transaction |
| `POST` | `/api/v1/p2p/reject` | `wallet:send` | Reject a P2P transaction |

### Chain Drop Endpoints

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/api/v1/chaindrop` | `wallet:read` | List chain drop proposals |
| `POST` | `/api/v1/chaindrop/propose` | `wallet:send` | Propose chain drop with contact |
| `POST` | `/api/v1/chaindrop/accept` | `wallet:send` | Accept chain drop proposal |
| `POST` | `/api/v1/chaindrop/reject` | `wallet:send` | Reject chain drop proposal |

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
| `wallet:send` | Send transactions |
| `wallet:*` | All wallet permissions |
| `contacts:read` | Read contacts |
| `contacts:write` | Add, update, delete contacts |
| `contacts:*` | All contact permissions |
| `system:read` | Read system status and metrics |
| `backup:read` | Read backup status/list, verify backups |
| `backup:write` | Create, restore, delete, enable/disable backups |
| `backup:*` | All backup permissions |
| `admin` | Full administrative access |
| `all` | All permissions |

---

## Common Query Parameters

### Pagination

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `limit` | int | 50 | Number of items (max: 100) |
| `offset` | int | 0 | Skip first N items |

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
    "currency": "USD"
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
