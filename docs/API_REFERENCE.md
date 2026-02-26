# EIOU API Reference

Complete API documentation for the EIOU Docker node REST API.

## Table of Contents

1. [Authentication](#authentication)
2. [Response Format](#response-format)
3. [Error Codes](#error-codes)
4. [Wallet Endpoints](#wallet-endpoints)
5. [Contact Endpoints](#contact-endpoints)
6. [System Endpoints](#system-endpoints)
7. [Chain Drop Endpoints](#chain-drop-endpoints)
8. [Backup Endpoints](#backup-endpoints)
9. [API Key Management](#api-key-management)

---

## Authentication

The EIOU API uses HMAC-SHA256 signature-based authentication to secure all requests.

### Required Headers

| Header | Description |
|--------|-------------|
| `X-API-Key` | Your API key ID (format: `eiou_...`) |
| `X-API-Timestamp` | Unix timestamp of request (seconds since epoch) |
| `X-API-Nonce` | Unique request identifier (8-64 chars, prevents replay attacks) |
| `X-API-Signature` | HMAC-SHA256 signature of the request |

### Signature Generation

The signature is computed as:

```
signature = HMAC-SHA256(string_to_sign, api_secret)
```

Where `string_to_sign` is:

```
{METHOD}\n{PATH}\n{TIMESTAMP}\n{NONCE}\n{BODY}
```

- `METHOD`: HTTP method in uppercase (GET, POST, PUT, DELETE)
- `PATH`: Request path (e.g., `/api/v1/wallet/balance`)
- `TIMESTAMP`: Same Unix timestamp as the header
- `NONCE`: Same unique nonce as the header
- `BODY`: Request body (empty string for GET requests)

### Security Notes

- Timestamps must be within 5 minutes of server time
- Each nonce can only be used once within the timestamp window (prevents replay attacks)
- API secrets are never sent in requests - only the computed signature
- Rate limiting is enforced per API key (default: 100 requests/minute)
- Proxy headers (`X-Forwarded-For`, `CF-Connecting-IP`) are only trusted when `REMOTE_ADDR` is in the trusted proxies list. Configure via CLI: `eiou changesettings trustedProxies "10.0.0.1,172.16.0.1"` (see [CLI Reference — changesettings](CLI_REFERENCE.md#changesettings)). The `TRUSTED_PROXIES` environment variable takes precedence if set.

### Example: Bash

```bash
#!/bin/bash
API_KEY="eiou_your_key_id"
API_SECRET="your_api_secret"
METHOD="GET"
PATH="/api/v1/wallet/balance"
TIMESTAMP=$(date +%s)
NONCE=$(openssl rand -hex 16)
BODY=""

STRING_TO_SIGN="${METHOD}\n${PATH}\n${TIMESTAMP}\n${NONCE}\n${BODY}"
SIGNATURE=$(echo -en "$STRING_TO_SIGN" | openssl dgst -sha256 -hmac "$API_SECRET" | cut -d' ' -f2)

curl -X GET "http://localhost:8080${PATH}" \
  -H "X-API-Key: $API_KEY" \
  -H "X-API-Timestamp: $TIMESTAMP" \
  -H "X-API-Nonce: $NONCE" \
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
$nonce = bin2hex(random_bytes(16));
$body = '';

$stringToSign = "{$method}\n{$path}\n{$timestamp}\n{$nonce}\n{$body}";
$signature = hash_hmac('sha256', $stringToSign, $apiSecret);

$ch = curl_init("http://localhost:8080{$path}");
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "X-API-Key: {$apiKey}",
    "X-API-Timestamp: {$timestamp}",
    "X-API-Nonce: {$nonce}",
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
const nonce = crypto.randomBytes(16).toString('hex');
const body = '';

const stringToSign = `${method}\n${path}\n${timestamp}\n${nonce}\n${body}`;
const signature = crypto.createHmac('sha256', apiSecret).update(stringToSign).digest('hex');

fetch(`http://localhost:8080${path}`, {
    method: method,
    headers: {
        'X-API-Key': apiKey,
        'X-API-Timestamp': timestamp.toString(),
        'X-API-Nonce': nonce,
        'X-API-Signature': signature
    }
});
```

### Example: Python

```python
import hashlib
import hmac
import secrets
import time
import requests

api_key = 'eiou_your_key_id'
api_secret = 'your_api_secret'
method = 'GET'
path = '/api/v1/wallet/balance'
timestamp = str(int(time.time()))
nonce = secrets.token_hex(16)
body = ''

string_to_sign = f"{method}\n{path}\n{timestamp}\n{nonce}\n{body}"
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
        'X-API-Nonce': nonce,
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
| `auth_missing_nonce` | X-API-Nonce header not provided |
| `auth_invalid_nonce` | Nonce format invalid (must be 8-64 characters) |
| `auth_replay_detected` | Nonce has already been used (replay attack) |
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
| `validation_error` | One or more setting values failed validation |
| `unknown_setting` | Unrecognized setting name in update request |

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
| `chaindrop_failed` | Chain drop operation failed |
| `chaindrop_error` | Error during chain drop operation |
| `sync_error` | Sync operation failed |
| `shutdown_error` | Shutdown operation failed |
| `start_error` | Start operation failed |

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
  -H "X-API-Nonce: $NONCE" \
  -H "X-API-Signature: $SIGNATURE"
```

---

### GET /api/v1/wallet/info

Get wallet public key, addresses, fee earnings, and available credit.

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
        },
        "fee_earnings": [
            {
                "currency": "USD",
                "total_amount": 12.50
            }
        ],
        "available_credit": [
            {
                "currency": "USD",
                "total_available_credit": 250.00
            }
        ]
    }
}
```

**Fields:**
- `fee_earnings`: Total fees earned from P2P relay transactions, grouped by currency
- `available_credit`: Total available credit extended by all contacts, grouped by currency

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
        "total_available_credit": [
            {
                "currency": "USD",
                "total": "250.00"
            }
        ],
        "recent_transactions": [
            {
                "txid": "tx_abc123",
                "type": "sent",
                "tx_type": "standard",
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

**Fields:**
- `total_available_credit`: Sum of available credit across all contacts, grouped by currency. Received via ping/pong from contacts; refreshed on ~5 minute intervals.

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
| `contact` | string | null | Filter by contact name or address |

**Response:**

```json
{
    "success": true,
    "data": {
        "transactions": [
            {
                "txid": "tx_abc123",
                "type": "sent",
                "tx_type": "standard",
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
| `best_fee` | boolean | No | **[Experimental]** Use best-fee routing: collects all P2P route responses and selects the lowest accumulated fee. May be slower than default fast mode. |

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
        "type": "standard"
    }
}
```

**curl Example:**

```bash
curl -X POST "http://localhost:8080/api/v1/wallet/send" \
  -H "X-API-Key: $API_KEY" \
  -H "X-API-Timestamp: $TIMESTAMP" \
  -H "X-API-Nonce: $NONCE" \
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
                "my_available_credit": 95.00,
                "their_available_credit": 100.00,
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

**Fields:**
- `my_available_credit`: How much credit you can use through this contact (received via ping/pong, ~5 min refresh). `null` if not yet known.
- `their_available_credit`: How much credit this contact can use through you (calculated: sent - received + credit_limit). `null` if balance data unavailable.

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
                },
                "fee_percent": 1.0,
                "credit_limit": 100.00,
                "my_available_credit": 85.50,
                "their_available_credit": 114.50,
                "currency": "USD"
            }
        ],
        "count": 1
    }
}
```

**Contact fields:**
- `fee_percent`: Fee percentage for transactions through this contact
- `credit_limit`: Credit limit set for this contact
- `my_available_credit`: How much credit this contact extends to you (from pong, refreshed on ~5 min intervals). `null` if not yet received.
- `their_available_credit`: How much credit you extend to this contact (calculated from balance + credit limit). `null` if no balance data.
- `currency`: Currency code for this contact relationship

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

**Note:** Ping also exchanges available credit information with the contact in the background. The `my_available_credit` field on subsequent contact queries will reflect the latest value received from this ping.

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
            "my_available_credit": 95.00,
            "their_available_credit": 100.00,
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

**Fields:**
- `my_available_credit`: How much credit you can use through this contact (received via ping/pong, ~5 min refresh). `null` if not yet known.
- `their_available_credit`: How much credit this contact can use through you (calculated: sent - received + credit_limit). `null` if balance data unavailable.

**Error Response (404):**

```json
{
    "success": false,
    "data": null,
    "error": {
        "message": "Contact not found",
        "code": "contact_not_found"
    },
    "status_code": 404
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
    "currency": "USD"
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
            "currency": "USD"
        }
    }
}
```

**Error Response (404):**

```json
{
    "success": false,
    "data": null,
    "error": {
        "message": "Contact not found for address: http://unknown:8080",
        "code": "contact_not_found"
    },
    "status_code": 404
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

**Error Response (404):**

```json
{
    "success": false,
    "data": null,
    "error": {
        "message": "Contact not found for address: http://unknown:8080",
        "code": "contact_not_found"
    },
    "status_code": 404
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

**Error Response (404):**

```json
{
    "success": false,
    "data": null,
    "error": {
        "message": "Contact not found for address: http://unknown:8080",
        "code": "contact_not_found"
    },
    "status_code": 404
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

**Error Response (404):**

```json
{
    "success": false,
    "data": null,
    "error": {
        "message": "Contact not found for address: http://unknown:8080",
        "code": "contact_not_found"
    },
    "status_code": 404
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
        "settings": {
            "default_currency": "USD",
            "minimum_fee_amount": 0.01,
            "default_fee_percent": 1.0,
            "maximum_fee_percent": 5.0,
            "default_credit_limit": 100.00,
            "max_p2p_level": 3,
            "p2p_expiration_seconds": 3600,
            "max_output_lines": 100,
            "default_transport_mode": "http",
            "hostname": "http://alice",
            "hostname_secure": "https://alice",
            "auto_refresh_enabled": true,
            "auto_backup_enabled": true,
            "contact_status_enabled": true,
            "contact_status_sync_on_ping": true,
            "auto_chain_drop_propose": true,
            "auto_chain_drop_accept": false,
            "api_enabled": true,
            "api_cors_allowed_origins": "",
            "rate_limit_enabled": true,
            "backup_retention_count": 3,
            "backup_cron_hour": 0,
            "backup_cron_minute": 0,
            "log_level": "INFO",
            "log_max_entries": 100,
            "cleanup_delivery_retention_days": 30,
            "cleanup_dlq_retention_days": 90,
            "cleanup_held_tx_retention_days": 7,
            "cleanup_rp2p_retention_days": 30,
            "cleanup_metrics_retention_days": 90,
            "p2p_rate_limit_per_minute": 60,
            "rate_limit_max_attempts": 10,
            "rate_limit_window_seconds": 60,
            "rate_limit_block_seconds": 300,
            "http_transport_timeout_seconds": 15,
            "tor_transport_timeout_seconds": 30,
            "sync_chunk_size": 50,
            "sync_max_chunks": 100,
            "held_tx_sync_timeout_seconds": 120,
            "display_date_format": "Y-m-d H:i:s.u",
            "display_currency_decimals": 2,
            "display_recent_transactions_limit": 5
        }
    }
}
```

**Fields:**
- `default_currency`: Default currency code for transactions (e.g., "USD")
- `minimum_fee_amount`: Minimum fee amount for transactions
- `default_fee_percent`: Default fee percentage for new contacts
- `maximum_fee_percent`: Maximum allowed fee percentage
- `default_credit_limit`: Default credit limit for new contacts
- `max_p2p_level`: Maximum P2P relay depth level
- `p2p_expiration_seconds`: Time in seconds before P2P requests expire
- `max_output_lines`: Maximum output lines for CLI commands
- `default_transport_mode`: Default transport protocol ("http", "https", or "tor")
- `hostname`: HTTP hostname of the node (e.g., "http://alice")
- `hostname_secure`: HTTPS hostname of the node (e.g., "https://alice")
- `auto_refresh_enabled`: Whether auto-refresh is enabled for transaction history
- `auto_backup_enabled`: Whether daily automatic database backup is enabled
- `contact_status_enabled`: Whether contact status tracking is enabled
- `contact_status_sync_on_ping`: Whether to sync contact status during ping
- `auto_chain_drop_propose`: Whether to auto-propose chain-drop operations
- `auto_chain_drop_accept`: Whether to auto-accept chain-drop proposals
- `api_enabled`: Whether the REST API endpoint is enabled
- `api_cors_allowed_origins`: Allowed CORS origins for API (empty = none)
- `rate_limit_enabled`: Whether rate limiting is active
- `backup_retention_count`: Number of backup files to retain (min 1)
- `backup_cron_hour`: Backup schedule hour in UTC (0-23)
- `backup_cron_minute`: Backup schedule minute (0-59)
- `log_level`: Minimum log level (DEBUG, INFO, WARNING, ERROR, CRITICAL)
- `log_max_entries`: Maximum log entries to keep (min 10)
- `cleanup_delivery_retention_days`: Days to retain delivery records (min 1)
- `cleanup_dlq_retention_days`: Days to retain dead letter queue entries (min 1)
- `cleanup_held_tx_retention_days`: Days to retain held transactions (min 1)
- `cleanup_rp2p_retention_days`: Days to retain P2P routing records (min 1)
- `cleanup_metrics_retention_days`: Days to retain metrics data (min 1)
- `p2p_rate_limit_per_minute`: Maximum P2P requests per minute (min 1)
- `rate_limit_max_attempts`: Max attempts before rate limit triggers (min 1)
- `rate_limit_window_seconds`: Rate limit time window in seconds (min 1)
- `rate_limit_block_seconds`: Block duration after limit exceeded in seconds (min 1)
- `http_transport_timeout_seconds`: HTTP transport timeout (5-120 seconds)
- `tor_transport_timeout_seconds`: Tor transport timeout (10-300 seconds)
- `sync_chunk_size`: Number of transactions per sync chunk (10-500)
- `sync_max_chunks`: Maximum sync chunks per cycle (10-1000)
- `held_tx_sync_timeout_seconds`: Max seconds a held transaction sync can be in progress before considered stale (30-299, must be less than P2P expiration)
- `display_date_format`: PHP date format string for timestamps
- `display_currency_decimals`: Decimal places for currency display (0-8)
- `display_recent_transactions_limit`: Number of recent transactions on dashboard (min 1)

---

### PUT /api/v1/system/settings

Update system settings.

**Permission:** `admin`

**Request Body:**

```json
{
    "default_fee": 1.5,
    "default_credit_limit": 200.00,
    "hostname": "http://mynode"
}
```

| Field | Type | Description |
|-------|------|-------------|
| `default_fee` | number | Default fee percentage for new contacts |
| `default_credit_limit` | number | Default credit limit for new contacts |
| `default_currency` | string | Default currency code (e.g., USD) |
| `min_fee` | number | Minimum fee amount |
| `max_fee` | number | Maximum fee percentage |
| `max_p2p_level` | int | Maximum P2P relay depth |
| `p2p_expiration` | int | P2P request expiration in seconds |
| `max_output` | int | Maximum CLI output lines (0 = unlimited) |
| `default_transport_mode` | string | Default transport protocol (http, https, tor) |
| `auto_refresh_enabled` | boolean | Enable/disable auto-refresh |
| `auto_backup_enabled` | boolean | Enable/disable automatic backups |
| `hostname` | string | Node hostname (triggers SSL cert regeneration) |
| `name` | string | Node display name |
| `contact_status_enabled` | boolean | Enable/disable contact status tracking |
| `contact_status_sync_on_ping` | boolean | Sync status during ping operations |
| `auto_chain_drop_propose` | boolean | Auto-propose chain-drop operations |
| `auto_chain_drop_accept` | boolean | Auto-accept chain-drop proposals |
| `api_enabled` | boolean | Enable/disable REST API endpoint |
| `api_cors_allowed_origins` | string | Allowed CORS origins (empty = none) |
| `rate_limit_enabled` | boolean | Enable/disable rate limiting |
| `backup_retention_count` | int | Backup files to retain (min 1) |
| `backup_cron_hour` | int | Backup schedule hour UTC (0-23) |
| `backup_cron_minute` | int | Backup schedule minute (0-59) |
| `log_level` | string | Min log level: DEBUG, INFO, WARNING, ERROR, CRITICAL |
| `log_max_entries` | int | Max log entries to keep (min 10) |
| `cleanup_delivery_retention_days` | int | Delivery record retention days (min 1) |
| `cleanup_dlq_retention_days` | int | DLQ entry retention days (min 1) |
| `cleanup_held_tx_retention_days` | int | Held transaction retention days (min 1) |
| `cleanup_rp2p_retention_days` | int | P2P routing record retention days (min 1) |
| `cleanup_metrics_retention_days` | int | Metrics data retention days (min 1) |
| `p2p_rate_limit_per_minute` | int | Max P2P requests per minute (min 1) |
| `rate_limit_max_attempts` | int | Attempts before rate limit triggers (min 1) |
| `rate_limit_window_seconds` | int | Rate limit time window seconds (min 1) |
| `rate_limit_block_seconds` | int | Block duration after limit exceeded (min 1) |
| `http_transport_timeout_seconds` | int | HTTP timeout (5-120 seconds) |
| `tor_transport_timeout_seconds` | int | Tor timeout (10-300 seconds) |
| `sync_chunk_size` | int | Transactions per sync chunk (10-500) |
| `sync_max_chunks` | int | Max sync chunks per cycle (10-1000) |
| `held_tx_sync_timeout_seconds` | int | Held tx sync timeout (30-299 seconds) |
| `display_date_format` | string | PHP date format string |
| `display_currency_decimals` | int | Currency decimal places (0-8) |
| `display_recent_transactions_limit` | int | Recent transactions on dashboard (min 1) |

All fields are optional. Only provided fields will be updated. Unknown fields return warnings.

**Response:**

```json
{
    "success": true,
    "data": {
        "message": "Settings updated successfully",
        "updated": {
            "default_fee": 1.5,
            "default_credit_limit": 200.00,
            "hostname": "http://mynode",
            "hostname_secure": "https://mynode"
        }
    }
}
```

**Partial Success Response** (some fields valid, some invalid):

```json
{
    "success": true,
    "data": {
        "message": "Settings updated successfully",
        "updated": {
            "default_fee": 1.5
        },
        "warnings": [
            "Unknown setting: invalid_key"
        ]
    }
}
```

**Notes:**
- Changing `hostname` automatically derives `hostname_secure` and regenerates the SSL certificate
- Boolean fields accept: `true`/`false`, `"true"`/`"false"`, `"1"`/`"0"`, `"on"`/`"off"`, `"yes"`/`"no"`

---

### POST /api/v1/system/sync

Trigger a sync operation to synchronize data with contacts.

**Permission:** `admin`

**Request Body (optional):**

```json
{
    "type": "contacts"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `type` | string | No | Sync type: `contacts`, `transactions`, `balances`, or omit for all |

**Response:**

```json
{
    "success": true,
    "data": {
        "message": "Sync completed",
        "type": "all",
        "results": null
    }
}
```

---

### POST /api/v1/system/shutdown

Shutdown background processors. The API remains responsive; only background workers (P2P, transaction processor, etc.) are terminated.

**Permission:** `admin`

**Response:**

```json
{
    "success": true,
    "data": {
        "message": "Processors shutdown initiated",
        "processes_terminated": 3,
        "pid_files_cleaned": 3
    }
}
```

**Fields:**
- `processes_terminated`: Number of processes that received SIGTERM
- `pid_files_cleaned`: Number of PID files removed

**Notes:**
- Creates a shutdown flag at `/tmp/eiou_shutdown.flag` to prevent the watchdog from restarting processors
- The API server itself is not affected and continues to serve requests

---

### POST /api/v1/system/start

Start background processors by removing the shutdown flag. The watchdog process will detect the flag removal and restart processors automatically.

**Permission:** `admin`

**Response (processors were stopped):**

```json
{
    "success": true,
    "data": {
        "message": "Processor restart initiated",
        "shutdown_flag_removed": true,
        "action": "watchdog_will_restart"
    }
}
```

**Response (processors already running):**

```json
{
    "success": true,
    "data": {
        "message": "Processors are already running",
        "shutdown_flag_removed": false,
        "action": "none"
    }
}
```

---

## Chain Drop Endpoints

Chain drops allow resetting the transaction chain with a contact when integrity issues are detected (e.g., missing or corrupted transactions). Auto-propose is controlled by `EIOU_AUTO_CHAIN_DROP_PROPOSE` (default: `true`). Auto-accept is controlled by `EIOU_AUTO_CHAIN_DROP_ACCEPT` (default: `false`); when enabled, a balance guard blocks acceptance if missing transactions include net payments owed to us.

### GET /api/v1/chaindrop

List chain drop proposals.

**Permission:** `wallet:read`

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `contact` | string | null | Filter by contact name or address |

**Response:**

```json
{
    "success": true,
    "data": {
        "proposals": [
            {
                "proposal_id": "cd_abc123",
                "contact_pubkey_hash": "def456...",
                "status": "pending",
                "created_at": "2026-01-24T12:00:00Z"
            }
        ],
        "count": 1
    }
}
```

**Notes:**
- Without `contact` filter, returns all incoming pending proposals
- With `contact` filter, returns all proposals (any status) for that contact

---

### POST /api/v1/chaindrop/propose

Propose a chain drop with a contact. This initiates the process of resetting the transaction chain.

**Permission:** `wallet:send`

**Request Body:**

```json
{
    "contact": "Bob"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `contact` | string | Yes | Contact name or address (also accepts `address` field name) |

**Response (201 Created):**

```json
{
    "success": true,
    "data": {
        "message": "Chain drop proposed successfully",
        "proposal_id": "cd_abc123",
        "missing_txid": "tx_missing...",
        "broken_txid": "tx_broken..."
    }
}
```

**Fields:**
- `proposal_id`: Unique identifier for the proposal
- `missing_txid`: The transaction ID that triggered the chain integrity issue (if applicable)
- `broken_txid`: The transaction ID where the chain break was detected (if applicable)

---

### POST /api/v1/chaindrop/accept

Accept a pending chain drop proposal.

**Permission:** `wallet:send`

**Request Body:**

```json
{
    "proposal_id": "cd_abc123"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `proposal_id` | string | Yes | ID of the proposal to accept |

**Response:**

```json
{
    "success": true,
    "data": {
        "message": "Chain drop proposal accepted",
        "proposal_id": "cd_abc123"
    }
}
```

---

### POST /api/v1/chaindrop/reject

Reject a pending chain drop proposal.

**Permission:** `wallet:send`

**Request Body:**

```json
{
    "proposal_id": "cd_abc123"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `proposal_id` | string | Yes | ID of the proposal to reject |

**Response:**

```json
{
    "success": true,
    "data": {
        "message": "Chain drop proposal rejected",
        "proposal_id": "cd_abc123"
    }
}
```

---

## Backup Endpoints

Manage encrypted database backups.

### GET /api/v1/backup/status

Get backup system status and settings.

**Permission:** `backup:read` or `admin`

**Response:**

```json
{
    "success": true,
    "data": {
        "enabled": true,
        "backup_count": 3,
        "retention_count": 3,
        "last_backup": "2026-01-24T03:00:00+00:00",
        "last_backup_file": "backup_20260124_030000.eiou.enc",
        "backup_directory": "/var/lib/eiou/backups",
        "next_scheduled": "2026-01-25T03:00:00+00:00"
    }
}
```

**Fields:**
- `enabled`: Whether automatic daily backups are enabled
- `backup_count`: Number of existing backup files
- `retention_count`: Maximum backups to retain (default: 3)
- `next_scheduled`: Next scheduled backup time (null if disabled)

---

### GET /api/v1/backup/list

List all available backup files.

**Permission:** `backup:read` or `admin`

**Response:**

```json
{
    "success": true,
    "data": {
        "backups": [
            {
                "filename": "backup_20260124_030000.eiou.enc",
                "size": 524288,
                "size_human": "512 KB",
                "created_at": "2026-01-24T03:00:00+00:00"
            },
            {
                "filename": "backup_20260123_030000.eiou.enc",
                "size": 520192,
                "size_human": "508 KB",
                "created_at": "2026-01-23T03:00:00+00:00"
            }
        ],
        "count": 2
    }
}
```

---

### POST /api/v1/backup/create

Create a new encrypted backup.

**Permission:** `backup:write` or `admin`

**Request Body (optional):**

```json
{
    "name": "pre_upgrade_backup"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | No | Custom name for backup (alphanumeric, underscore, hyphen only) |

**Response (201 Created):**

```json
{
    "success": true,
    "data": {
        "message": "Backup created successfully",
        "filename": "pre_upgrade_backup.eiou.enc",
        "size": 524288,
        "path": "/var/lib/eiou/backups/pre_upgrade_backup.eiou.enc"
    }
}
```

---

### POST /api/v1/backup/restore

Restore database from a backup file.

**Permission:** `backup:write` or `admin`

**Request Body:**

```json
{
    "filename": "backup_20260124_030000.eiou.enc",
    "confirm": true
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `filename` | string | Yes | Name of backup file to restore |
| `confirm` | boolean | Yes | Must be `true` to proceed (safety check) |

> **Warning:** This operation will overwrite all current database data!

**Response:**

```json
{
    "success": true,
    "data": {
        "message": "Backup restored successfully",
        "filename": "backup_20260124_030000.eiou.enc",
        "restored_at": "2026-01-24T15:30:00+00:00"
    }
}
```

**Error Response (confirmation required):**

```json
{
    "success": false,
    "error": {
        "code": "confirmation_required",
        "message": "Must set confirm: true to restore backup. This will overwrite all current database data!"
    }
}
```

---

### POST /api/v1/backup/verify

Verify backup file integrity and decryption.

**Permission:** `backup:read` or `admin`

**Request Body:**

```json
{
    "filename": "backup_20260124_030000.eiou.enc"
}
```

**Response:**

```json
{
    "success": true,
    "data": {
        "filename": "backup_20260124_030000.eiou.enc",
        "valid": true,
        "version": "1.0",
        "created_at": "2026-01-24T03:00:00+00:00"
    }
}
```

**Fields:**
- `valid`: `true` if backup can be decrypted and contains valid SQL
- `version`: Backup format version
- `created_at`: Timestamp when backup was created

---

### DELETE /api/v1/backup/:filename

Delete a backup file.

**Permission:** `backup:write` or `admin`

**Response:**

```json
{
    "success": true,
    "data": {
        "message": "Backup deleted successfully",
        "filename": "backup_20260124_030000.eiou.enc"
    }
}
```

---

### POST /api/v1/backup/enable

Enable automatic daily backups.

**Permission:** `backup:write` or `admin`

**Response:**

```json
{
    "success": true,
    "data": {
        "message": "Automatic backups enabled",
        "enabled": true
    }
}
```

---

### POST /api/v1/backup/disable

Disable automatic daily backups.

**Permission:** `backup:write` or `admin`

**Response:**

```json
{
    "success": true,
    "data": {
        "message": "Automatic backups disabled",
        "enabled": false
    }
}
```

---

### POST /api/v1/backup/cleanup

Remove old backup files, keeping only the most recent (default: 3).

**Permission:** `backup:write` or `admin`

**Response:**

```json
{
    "success": true,
    "data": {
        "message": "Backup cleanup completed",
        "deleted_count": 2,
        "deleted_files": [
            "backup_20260120_030000.eiou.enc",
            "backup_20260119_030000.eiou.enc"
        ]
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
| `backup:read` | Read backup status and list, verify backups |
| `backup:write` | Create, restore, delete backups, enable/disable auto-backup |
| `backup:*` | All backup permissions |
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
        "rate_limit_per_minute": 100,
        "warning": "Save this secret now! It will not be shown again."
    }
}
```

**Fields:**
- `key_id`: Unique identifier for the API key
- `secret`: The API secret (only shown once at creation)
- `name`: Human-readable name for the key
- `permissions`: Array of granted permissions
- `rate_limit_per_minute`: Maximum API calls per minute for this key
- `warning`: Security reminder to save the secret immediately

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

### POST /api/v1/keys/enable/:key_id

Enable a disabled API key.

**Permission:** `admin`

**Response:**

```json
{
    "success": true,
    "data": {
        "message": "API key enabled successfully",
        "key_id": "eiou_xyz789"
    }
}
```

**Error Response (404):**

```json
{
    "success": false,
    "error": {
        "code": "key_not_found",
        "message": "API key not found"
    }
}
```

---

### POST /api/v1/keys/disable/:key_id

Disable an API key without deleting it. Disabled keys return `auth_key_disabled` on authentication attempts.

**Permission:** `admin`

**Response:**

```json
{
    "success": true,
    "data": {
        "message": "API key disabled successfully",
        "key_id": "eiou_xyz789"
    }
}
```

**Error Response (404):**

```json
{
    "success": false,
    "error": {
        "code": "key_not_found",
        "message": "API key not found"
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

### Contact Online Status

| Value | Description |
|-------|-------------|
| `online` | Contact responded to ping |
| `offline` | Contact did not respond to ping |
| `unknown` | Ping not performed (default or feature disabled) |

### Transaction Status

| Value | Description |
|-------|-------------|
| `pending` | Transaction has been created |
| `sending` | Transaction claimed for processing |
| `sent` | Transaction has been sent onwards |
| `accepted` | Transaction accepted by peer |
| `rejected` | Transaction rejected by peer |
| `cancelled` | Transaction not received by peer in time |
| `completed` | Transaction accepted by final recipient |
| `failed` | Transaction failed after max recovery attempts |

### Transaction Type

| Value | Description |
|-------|-------------|
| `sent` | Outgoing transaction |
| `received` | Incoming transaction |
| `relay` | Relayed through this node |

### Transaction TX Type

| Value | Description |
|-------|-------------|
| `standard` | Direct transaction to known contact |
| `p2p` | P2P transaction to unknown contact (or part of P2P chain) |
| `contact` | Contact request transaction (amount=0, establishes contact) |

---

## See Also

- [API Quick Reference](API_QUICK_REFERENCE.md) - Condensed API reference
- [Error Codes](ERROR_CODES.md) - Complete error code reference
- [CLI Reference](CLI_REFERENCE.md) - Command-line interface documentation
- [Docker Configuration](DOCKER_CONFIGURATION.md) - Container configuration
