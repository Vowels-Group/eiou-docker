# EIOU API Quick Reference

Quick lookup card for the EIOU REST API endpoints.

---

## Authentication

All requests require HMAC-SHA256 authentication:

| Header | Value |
|--------|-------|
| `X-API-Key` | Your API key ID (`eiou_...`) |
| `X-API-Timestamp` | Unix timestamp (seconds) |
| `X-API-Signature` | HMAC-SHA256 signature |

**Signature:** `HMAC-SHA256(secret, METHOD\nPATH\nTIMESTAMP\nBODY)`

---

## Endpoint Summary

### Wallet Endpoints

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/api/v1/wallet/balance` | `wallet:read` | Get balances by contact |
| `GET` | `/api/v1/wallet/balances` | `wallet:read` | Alias for balance |
| `GET` | `/api/v1/wallet/info` | `wallet:read` | Get wallet public key and addresses |
| `GET` | `/api/v1/wallet/overview` | `wallet:read` | Dashboard summary (balances + recent tx) |
| `GET` | `/api/v1/wallet/transactions` | `wallet:read` | Paginated transaction history |
| `POST` | `/api/v1/wallet/send` | `wallet:send` | Send a transaction |

### Contact Endpoints

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/api/v1/contacts` | `contacts:read` | List all contacts |
| `POST` | `/api/v1/contacts` | `contacts:write` | Add new contact |
| `GET` | `/api/v1/contacts/pending` | `contacts:read` | Get pending requests |
| `GET` | `/api/v1/contacts/search` | `contacts:read` | Search contacts by name |
| `POST` | `/api/v1/contacts/ping/:address` | `contacts:read` | Ping contact status |
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

### API Key Management (Admin)

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `GET` | `/api/v1/keys` | `admin` | List all API keys |
| `POST` | `/api/v1/keys` | `admin` | Create new API key |
| `DELETE` | `/api/v1/keys/:key_id` | `admin` | Delete API key |

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
| `/contacts/search` | `q` or `query` | Search term |

---

## Request Bodies

### POST /api/v1/wallet/send

```json
{
    "address": "http://bob.local:8080",
    "amount": 25.00,
    "currency": "USD",
    "description": "Optional description"
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

```json
{
    "name": "New Name",
    "fee_percent": 1.5,
    "credit_limit": 200.00,
    "currency": "EUR"
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
PATH="/api/v1/wallet/balance"
SIGNATURE=$(echo -en "GET\n$PATH\n$TIMESTAMP\n" | openssl dgst -sha256 -hmac "$API_SECRET" | cut -d' ' -f2)

curl "http://localhost:8080$PATH" \
  -H "X-API-Key: $API_KEY" \
  -H "X-API-Timestamp: $TIMESTAMP" \
  -H "X-API-Signature: $SIGNATURE"
```

### Bash - Send Transaction

```bash
BODY='{"address":"http://bob:8080","amount":25,"currency":"USD"}'
PATH="/api/v1/wallet/send"
SIGNATURE=$(echo -en "POST\n$PATH\n$TIMESTAMP\n$BODY" | openssl dgst -sha256 -hmac "$API_SECRET" | cut -d' ' -f2)

curl -X POST "http://localhost:8080$PATH" \
  -H "X-API-Key: $API_KEY" \
  -H "X-API-Timestamp: $TIMESTAMP" \
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
