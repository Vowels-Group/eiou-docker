# EIOU API Reference

Complete API documentation for the EIOU Docker node REST API.

## Table of Contents

1. [Authentication](#authentication)
2. [Response Format](#response-format)
3. [Error Codes](#error-codes)
4. [Wallet Endpoints](#wallet-endpoints)
5. [Contact Endpoints](#contact-endpoints)
6. [System Endpoints](#system-endpoints)
7. [API Key Management](#api-key-management)

---

## Authentication

The EIOU API uses HMAC-SHA256 signature-based authentication to secure all requests.

### Required Headers

| Header | Description |
|--------|-------------|
| `X-API-Key` | Your API key ID (format: `eiou_...`) |
| `X-API-Timestamp` | Unix timestamp of request (seconds since epoch) |
| `X-API-Signature` | HMAC-SHA256 signature of the request |

### Signature Generation

The signature is computed as:

```
signature = HMAC-SHA256(string_to_sign, api_secret)
```

Where `string_to_sign` is:

```
{METHOD}\n{PATH}\n{TIMESTAMP}\n{BODY}
```

- `METHOD`: HTTP method in uppercase (GET, POST, PUT, DELETE)
- `PATH`: Request path (e.g., `/api/v1/wallet/balance`)
- `TIMESTAMP`: Same Unix timestamp as the header
- `BODY`: Request body (empty string for GET requests)

### Security Notes

- Timestamps must be within 5 minutes of server time (prevents replay attacks)
- API secrets are never sent in requests - only the computed signature
- Rate limiting is enforced per API key (default: 100 requests/minute)

### Example: Bash

```bash
#!/bin/bash
API_KEY="eiou_your_key_id"
API_SECRET="your_api_secret"
METHOD="GET"
PATH="/api/v1/wallet/balance"
TIMESTAMP=$(date +%s)
BODY=""

STRING_TO_SIGN="${METHOD}\n${PATH}\n${TIMESTAMP}\n${BODY}"
SIGNATURE=$(echo -en "$STRING_TO_SIGN" | openssl dgst -sha256 -hmac "$API_SECRET" | cut -d' ' -f2)

curl -X GET "http://localhost:8080${PATH}" \
  -H "X-API-Key: $API_KEY" \
  -H "X-API-Timestamp: $TIMESTAMP" \
  -H "X-API-Signature: $SIGNATURE"
```

### Example: PHP

```php
<?php
$apiKey = 'eiou_your_key_id';
$apiSecret = 'your_api_secret';
$method = 'GET';
$path = '/api/v1/wallet/balance';
$timestamp = time();
$body = '';

$stringToSign = "{$method}\n{$path}\n{$timestamp}\n{$body}";
$signature = hash_hmac('sha256', $stringToSign, $apiSecret);

$ch = curl_init("http://localhost:8080{$path}");
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "X-API-Key: {$apiKey}",
    "X-API-Timestamp: {$timestamp}",
    "X-API-Signature: {$signature}"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
```

### Example: JavaScript

```javascript
const crypto = require('crypto');

const apiKey = 'eiou_your_key_id';
const apiSecret = 'your_api_secret';
const method = 'GET';
const path = '/api/v1/wallet/balance';
const timestamp = Math.floor(Date.now() / 1000);
const body = '';

const stringToSign = `${method}\n${path}\n${timestamp}\n${body}`;
const signature = crypto.createHmac('sha256', apiSecret).update(stringToSign).digest('hex');

fetch(`http://localhost:8080${path}`, {
    method: method,
    headers: {
        'X-API-Key': apiKey,
        'X-API-Timestamp': timestamp.toString(),
        'X-API-Signature': signature
    }
});
```

### Example: Python

```python
import hashlib
import hmac
import time
import requests

api_key = 'eiou_your_key_id'
api_secret = 'your_api_secret'
method = 'GET'
path = '/api/v1/wallet/balance'
timestamp = str(int(time.time()))
body = ''

string_to_sign = f"{method}\n{path}\n{timestamp}\n{body}"
signature = hmac.new(
    api_secret.encode(),
    string_to_sign.encode(),
    hashlib.sha256
).hexdigest()

response = requests.get(
    f"http://localhost:8080{path}",
    headers={
        'X-API-Key': api_key,
        'X-API-Timestamp': timestamp,
        'X-API-Signature': signature
    }
)
print(response.json())
```

---

## Response Format

### Success Response

```json
{
    "success": true,
    "data": {
        // Response data here
    },
    "request_id": "req_abc123",
    "timestamp": "2026-01-23T12:00:00Z"
}
```

### Error Response

```json
{
    "success": false,
    "error": {
        "code": "error_code",
        "message": "Human-readable error message"
    },
    "request_id": "req_abc123",
    "timestamp": "2026-01-23T12:00:00Z"
}
```

---

## Error Codes

### Authentication Errors (401)

| Code | Description |
|------|-------------|
| `auth_missing_key` | X-API-Key header not provided |
| `auth_missing_timestamp` | X-API-Timestamp header not provided |
| `auth_missing_signature` | X-API-Signature header not provided |
| `auth_invalid_key` | API key does not exist |
| `auth_invalid_signature` | HMAC signature verification failed |
| `auth_invalid_timestamp` | Timestamp is not a valid number |
| `auth_expired_timestamp` | Timestamp is too old (>5 minutes) |
| `auth_key_disabled` | API key has been disabled |
| `auth_key_expired` | API key has expired |

### Permission Errors (403)

| Code | Description |
|------|-------------|
| `permission_denied` | API key lacks required permission |

### Resource Errors (404)

| Code | Description |
|------|-------------|
| `invalid_path` | Invalid API path |
| `unknown_resource` | Resource type not found |
| `unknown_action` | Action not found for resource |
| `contact_not_found` | Contact does not exist |

### Validation Errors (400)

| Code | Description |
|------|-------------|
| `invalid_json` | Request body is not valid JSON |
| `missing_field` | Required field is missing |
| `invalid_amount` | Transaction amount is invalid |
| `no_fields` | No fields provided for update |

### Rate Limiting (429)

| Code | Description |
|------|-------------|
| `rate_limit_exceeded` | Too many requests per minute |

### Operation Errors

| Code | Description |
|------|-------------|
| `key_not_found` | API key does not exist |
| `ping_failed` | Contact ping operation failed |
| `ping_error` | Error during contact ping |
| `update_failed` | Contact update operation failed |
| `update_error` | Error during contact update |
| `delete_failed` | Contact deletion failed |
| `delete_error` | Error during contact deletion |
| `block_failed` | Contact block operation failed |
| `block_error` | Error during block operation |
| `unblock_failed` | Contact unblock operation failed |
| `unblock_error` | Error during unblock operation |
| `contact_add_failed` | Failed to add contact |

### Server Errors (500)

| Code | Description |
|------|-------------|
| `internal_error` | Unexpected server error |
| `transaction_error` | Transaction processing failed |
| `contact_error` | Contact operation failed |

---

## Wallet Endpoints

### GET /api/v1/wallet/balance

Get wallet balances grouped by contact.

**Alias:** `/api/v1/wallet/balances` (plural form also accepted)

**Permission:** `wallet:read`

**Response:**

```json
{
    "success": true,
    "data": {
        "balances": [
            {
                "contact_name": "Alice",
                "address": "http://alice.local:8080",
                "currency": "USD",
                "received": 150.00,
                "sent": 50.00,
                "net_balance": 100.00
            }
        ]
    }
}
```

**curl Example:**

```bash
curl -X GET "http://localhost:8080/api/v1/wallet/balance" \
  -H "X-API-Key: $API_KEY" \
  -H "X-API-Timestamp: $TIMESTAMP" \
  -H "X-API-Signature: $SIGNATURE"
```

---

### GET /api/v1/wallet/info

Get wallet public key and addresses.

**Permission:** `wallet:read`

**Response:**

```json
{
    "success": true,
    "data": {
        "public_key_hash": "abc123...",
        "addresses": {
            "http": "http://node.local:8080",
            "https": "https://node.local:8443",
            "tor": "abc123...onion"
        }
    }
}
```

---

### GET /api/v1/wallet/overview

Get dashboard summary with balances and recent transactions.

**Permission:** `wallet:read`

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `transaction_limit` | int | 5 | Number of recent transactions (max: 20) |

**Response:**

```json
{
    "success": true,
    "data": {
        "balances": [
            {
                "currency": "USD",
                "total_balance": 500.00
            }
        ],
        "recent_transactions": [
            {
                "txid": "tx_abc123",
                "type": "sent",
                "tx_type": "direct",
                "status": "completed",
                "amount": 25.00,
                "currency": "USD",
                "counterparty_name": "Bob",
                "description": "Payment for services",
                "timestamp": "2026-01-23T12:00:00Z"
            }
        ],
        "transaction_count": 1
    }
}
```

---

### GET /api/v1/wallet/transactions

Get paginated transaction history.

**Permission:** `wallet:read`

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `limit` | int | 50 | Number of transactions (max: 100) |
| `offset` | int | 0 | Pagination offset |
| `type` | string | null | Filter by type: `sent`, `received`, `relay` |

**Response:**

```json
{
    "success": true,
    "data": {
        "transactions": [
            {
                "txid": "tx_abc123",
                "type": "sent",
                "tx_type": "direct",
                "status": "completed",
                "amount": 25.00,
                "currency": "USD",
                "sender_address": "http://alice.local:8080",
                "receiver_address": "http://bob.local:8080",
                "description": "Invoice #123",
                "memo": "standard",
                "timestamp": "2026-01-23T12:00:00Z"
            }
        ],
        "pagination": {
            "total": 150,
            "limit": 50,
            "offset": 0
        }
    }
}
```

---

### POST /api/v1/wallet/send

Send a transaction to a contact.

**Permission:** `wallet:send`

**Request Body:**

```json
{
    "address": "http://bob.local:8080",
    "amount": 25.00,
    "currency": "USD",
    "description": "Payment for services"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `address` | string | Yes | Recipient address (HTTP, HTTPS, or Tor) |
| `amount` | number | Yes | Amount to send (must be > 0) |
| `currency` | string | Yes | Currency code (e.g., USD) |
| `description` | string | No | Optional transaction description |

**Response:**

```json
{
    "success": true,
    "data": {
        "status": "sent",
        "message": "Transaction sent successfully",
        "recipient": "Bob",
        "recipient_address": "http://bob.local:8080",
        "amount": 25.00,
        "currency": "USD",
        "txid": "tx_abc123",
        "type": "direct"
    }
}
```

**curl Example:**

```bash
curl -X POST "http://localhost:8080/api/v1/wallet/send" \
  -H "X-API-Key: $API_KEY" \
  -H "X-API-Timestamp: $TIMESTAMP" \
  -H "X-API-Signature: $SIGNATURE" \
  -H "Content-Type: application/json" \
  -d '{"address":"http://bob.local:8080","amount":25.00,"currency":"USD"}'
```

---

## Contact Endpoints

### GET /api/v1/contacts

List all contacts.

**Permission:** `contacts:read`

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `status` | string | `accepted` | Filter by status: `pending`, `accepted`, `blocked` |

**Response:**

```json
{
    "success": true,
    "data": {
        "contacts": [
            {
                "name": "Bob",
                "pubkey_hash": "abc123...",
                "status": "accepted",
                "currency": "USD",
                "fee_percent": 1.0,
                "credit_limit": 100.00,
                "addresses": {
                    "http": "http://bob.local:8080",
                    "https": null,
                    "tor": null
                },
                "created_at": "2026-01-01T00:00:00Z"
            }
        ],
        "count": 1
    }
}
```

---

### POST /api/v1/contacts

Add a new contact.

**Permission:** `contacts:write`

**Request Body:**

```json
{
    "address": "http://bob.local:8080",
    "name": "Bob",
    "fee_percent": 1.0,
    "credit_limit": 100.00,
    "currency": "USD"
}
```

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `address` | string | Yes | - | Contact's node address |
| `name` | string | Yes | - | Display name for contact |
| `fee_percent` | number | No | 1.0 | Transaction fee percentage |
| `credit_limit` | number | No | 100.0 | Credit limit for contact |
| `currency` | string | No | USD | Currency for transactions |

**Response (201 Created):**

```json
{
    "success": true,
    "data": {
        "message": "Contact request sent successfully",
        "status": "pending",
        "address": "http://bob.local:8080",
        "name": "Bob"
    }
}
```

---

### GET /api/v1/contacts/pending

Get all pending contact requests (incoming and outgoing).

**Permission:** `contacts:read`

**Response:**

```json
{
    "success": true,
    "data": {
        "pending": {
            "incoming": [
                {
                    "pubkey_hash": "abc123...",
                    "status": "pending",
                    "addresses": {
                        "http": "http://unknown.local:8080"
                    },
                    "created_at": "2026-01-23T12:00:00Z"
                }
            ],
            "outgoing": [
                {
                    "name": "Charlie",
                    "pubkey_hash": "def456...",
                    "status": "pending",
                    "currency": "USD",
                    "fee_percent": 1.0,
                    "credit_limit": 100.00,
                    "addresses": {
                        "http": "http://charlie.local:8080"
                    },
                    "created_at": "2026-01-23T12:00:00Z"
                }
            ]
        },
        "counts": {
            "incoming": 1,
            "outgoing": 1,
            "total": 2
        }
    }
}
```

---

### GET /api/v1/contacts/search

Search contacts by name.

**Permission:** `contacts:read`

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `q` or `query` | string | Yes | Search term |

**Response:**

```json
{
    "success": true,
    "data": {
        "search_term": "bob",
        "contacts": [
            {
                "name": "Bob",
                "pubkey_hash": "abc123...",
                "status": "accepted",
                "addresses": {
                    "http": "http://bob.local:8080"
                }
            }
        ],
        "count": 1
    }
}
```

---

### POST /api/v1/contacts/ping/:address

Ping a contact to check online status.

**Permission:** `contacts:read`

**URL Parameters:**

| Parameter | Description |
|-----------|-------------|
| `address` | Contact address (URL-encoded) |

**Response:**

```json
{
    "success": true,
    "data": {
        "contact_name": "Bob",
        "online_status": "online",
        "chain_valid": true,
        "message": "Ping complete"
    }
}
```

---

### GET /api/v1/contacts/:address

Get contact details by address or name.

**Permission:** `contacts:read`

**Response:**

```json
{
    "success": true,
    "data": {
        "contact": {
            "name": "Bob",
            "pubkey_hash": "abc123...",
            "status": "accepted",
            "currency": "USD",
            "fee_percent": 1.0,
            "credit_limit": 100.00,
            "addresses": {
                "http": "http://bob.local:8080",
                "https": null,
                "tor": null
            },
            "balance": {
                "received": 100.00,
                "sent": 50.00,
                "net": 50.00
            },
            "created_at": "2026-01-01T00:00:00Z"
        }
    }
}
```

---

### PUT /api/v1/contacts/:address

Update contact information.

**Permission:** `contacts:write`

**Request Body:**

```json
{
    "name": "Robert",
    "fee_percent": 1.5,
    "credit_limit": 200.00,
    "currency": "EUR"
}
```

All fields are optional. Only provided fields will be updated.

**Response:**

```json
{
    "success": true,
    "data": {
        "message": "Contact updated successfully",
        "updated": {
            "address": "http://bob.local:8080",
            "name": "Robert",
            "fee_percent": 1.5,
            "credit_limit": 200.00,
            "currency": "EUR"
        }
    }
}
```

---

### DELETE /api/v1/contacts/:address

Delete a contact.

**Permission:** `contacts:write`

**Response:**

```json
{
    "success": true,
    "data": {
        "message": "Contact deleted successfully",
        "address": "http://bob.local:8080"
    }
}
```

---

### POST /api/v1/contacts/block/:address

Block a contact.

**Permission:** `contacts:write`

**Response:**

```json
{
    "success": true,
    "data": {
        "message": "Contact blocked successfully",
        "address": "http://bob.local:8080"
    }
}
```

---

### POST /api/v1/contacts/unblock/:address

Unblock a contact.

**Permission:** `contacts:write`

**Response:**

```json
{
    "success": true,
    "data": {
        "message": "Contact unblocked successfully",
        "address": "http://bob.local:8080"
    }
}
```

---

## System Endpoints

### GET /api/v1/system/status

Get system health status.

**Permission:** `system:read`

**Response:**

```json
{
    "success": true,
    "data": {
        "status": "operational",
        "version": "1.0.0",
        "environment": "production",
        "database": "healthy",
        "processors": {
            "p2p": true,
            "transaction": true,
            "cleanup": true
        },
        "timestamp": "2026-01-24T12:00:00+00:00"
    },
    "request_id": "req_abc123"
}
```

**Fields:**
- `status`: Always `"operational"` when system is running
- `database`: `"healthy"` or `"unhealthy"` based on database connectivity
- `processors`: Boolean flags indicating if processor PID files exist

---

### GET /api/v1/system/metrics

Get system metrics.

**Permission:** `system:read`

**Response:**

```json
{
    "success": true,
    "data": {
        "transactions": {
            "total": 1500,
            "by_type": {
                "send": 750,
                "receive": 745,
                "fee": 5
            }
        },
        "contacts": {
            "total_accepted": 25
        },
        "p2p": {
            "queued": 3
        },
        "uptime": "5d 12h 30m",
        "memory_usage": 52428800,
        "timestamp": "2026-01-24T12:00:00+00:00"
    },
    "request_id": "req_abc123"
}
```

**Fields:**
- `transactions.by_type`: Count of transactions grouped by type (send, receive, fee, etc.)
- `contacts.total_accepted`: Number of mutually accepted contacts
- `p2p.queued`: Number of P2P relay messages waiting to be processed
- `uptime`: Formatted uptime string (days, hours, minutes)
- `memory_usage`: Current PHP memory usage in bytes

---

### GET /api/v1/system/settings

Get system settings.

**Permission:** `system:read`

**Response:**

```json
{
    "success": true,
    "data": {
        "node_name": "Alice's Node",
        "default_currency": "USD",
        "default_fee_percent": 1.0,
        "default_credit_limit": 100.00,
        "sync_interval_seconds": 60,
        "message_check_interval_seconds": 30
    }
}
```

---

## API Key Management

These endpoints require `admin` permission.

### GET /api/v1/keys

List all API keys.

**Permission:** `admin`

**Response:**

```json
{
    "success": true,
    "data": {
        "keys": [
            {
                "key_id": "eiou_abc123",
                "name": "Mobile App",
                "permissions": ["wallet:read", "wallet:send", "contacts:read"],
                "rate_limit_per_minute": 100,
                "enabled": true,
                "expires_at": null,
                "created_at": "2026-01-01T00:00:00Z",
                "last_used_at": "2026-01-23T12:00:00Z"
            }
        ]
    }
}
```

---

### POST /api/v1/keys

Create a new API key.

**Permission:** `admin`

**Request Body:**

```json
{
    "name": "New Integration",
    "permissions": ["wallet:read", "contacts:read"],
    "rate_limit_per_minute": 60,
    "expires_at": "2027-01-01T00:00:00Z"
}
```

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `name` | string | Yes | - | Descriptive name for the key |
| `permissions` | array | Yes | - | List of permissions |
| `rate_limit_per_minute` | int | No | 100 | Rate limit |
| `expires_at` | string | No | null | Expiration date (ISO 8601) |

**Available Permissions:**

| Permission | Description |
|------------|-------------|
| `wallet:read` | Read wallet balances and transactions |
| `wallet:send` | Send transactions |
| `wallet:*` | All wallet permissions |
| `contacts:read` | Read contacts |
| `contacts:write` | Add, update, delete contacts |
| `contacts:*` | All contact permissions |
| `system:read` | Read system status and metrics |
| `admin` | Full administrative access |
| `all` | All permissions |

**Response (201 Created):**

```json
{
    "success": true,
    "data": {
        "key_id": "eiou_xyz789",
        "secret": "sk_live_abc123...",
        "name": "New Integration",
        "permissions": ["wallet:read", "contacts:read"],
        "message": "Store the secret securely - it cannot be retrieved again"
    }
}
```

> **Important:** The `secret` is only returned once at creation time. Store it securely.

---

### DELETE /api/v1/keys/:key_id

Delete an API key.

**Permission:** `admin`

**Response:**

```json
{
    "success": true,
    "data": {
        "message": "API key deleted successfully",
        "key_id": "eiou_xyz789"
    }
}
```

---

## Status Values Reference

### Contact Status

| Value | Description |
|-------|-------------|
| `pending` | Contact request awaiting acceptance |
| `accepted` | Contact is active and can transact |
| `blocked` | Contact is blocked |
| `rejected` | Contact request was rejected |

### Transaction Status

| Value | Description |
|-------|-------------|
| `pending` | Transaction is being processed |
| `sent` | Transaction sent, awaiting confirmation |
| `completed` | Transaction completed successfully |
| `failed` | Transaction failed |
| `cancelled` | Transaction was cancelled |
| `rejected` | Transaction was rejected |

### Transaction Type

| Value | Description |
|-------|-------------|
| `sent` | Outgoing transaction |
| `received` | Incoming transaction |
| `relay` | Relayed through this node |

### Transaction TX Type

| Value | Description |
|-------|-------------|
| `direct` | Direct peer-to-peer transaction |
| `relay` | Transaction routed through intermediaries |

---

## See Also

- [API Quick Reference](API_QUICK_REFERENCE.md) - Condensed API reference
- [Error Codes](ERROR_CODES.md) - Complete error code reference
- [CLI Reference](CLI_REFERENCE.md) - Command-line interface documentation
- [Docker Configuration](DOCKER_CONFIGURATION.md) - Container configuration
