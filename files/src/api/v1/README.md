# EIOU REST API v1

RESTful API for interacting with EIOU wallet nodes programmatically.

## Features

- ✅ Wallet operations (balance, send, receive, transactions)
- ✅ Contact management (via existing AJAX API)
- ✅ API key authentication
- ✅ Rate limiting (100 req/min per key)
- ✅ Standardized JSON responses
- ✅ CORS support
- ✅ Pagination support

## Base URL

```
http://your-node-address/src/api/v1
```

## Authentication

All API requests require an API key. Include your key in the `X-API-Key` header:

```bash
curl -H "X-API-Key: your_api_key_here" \
  http://localhost/src/api/v1/wallet/balance
```

### Generating API Keys

Use the CLI tool to generate and manage API keys:

```bash
# Generate a new API key
php /etc/eiou/src/api/v1/tools/manage-keys.php generate --name="My App"

# List all API keys
php /etc/eiou/src/api/v1/tools/manage-keys.php list

# Revoke a key
php /etc/eiou/src/api/v1/tools/manage-keys.php revoke --key-hash="abc123..."
```

## API Endpoints

### Wallet Operations

#### Get Balance
```
GET /wallet/balance
```

**Response:**
```json
{
  "success": true,
  "data": {
    "balances": [
      {
        "contact_name": "Alice",
        "contact_address": "http://alice.example.com",
        "balance": 100.50,
        "currency": "USD",
        "received": 200.00,
        "sent": 99.50
      }
    ],
    "total_contacts": 1
  },
  "timestamp": "2025-11-18T12:00:00Z",
  "request_id": "req_abc123"
}
```

#### Send Transaction
```
POST /wallet/send
```

**Request Body:**
```json
{
  "recipient": "http://bob.example.com",
  "amount": 10.50,
  "currency": "USD"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "message": "Transaction initiated successfully",
    "recipient": "http://bob.example.com",
    "amount": 10.50,
    "currency": "USD"
  },
  "timestamp": "2025-11-18T12:00:00Z",
  "request_id": "req_xyz789"
}
```

#### Get Wallet Address
```
GET /wallet/address
```

**Response:**
```json
{
  "success": true,
  "data": {
    "addresses": [
      {
        "type": "http",
        "address": "http://mynode.example.com",
        "hostname": "mynode"
      }
    ]
  }
}
```

#### Get Transaction History
```
GET /wallet/transactions?limit=20&offset=0&type=sent
```

**Query Parameters:**
- `limit` (optional): Number of transactions to return (max 100, default 20)
- `offset` (optional): Number of transactions to skip (default 0)
- `type` (optional): Filter by transaction type (`sent`, `received`, `relay`)

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 123,
      "type": "sent",
      "amount": 10.50,
      "currency": "USD",
      "sender": "http://mynode.example.com",
      "receiver": "http://bob.example.com",
      "timestamp": "2025-11-18T12:00:00Z",
      "status": "completed"
    }
  ],
  "pagination": {
    "page": 1,
    "limit": 20,
    "total": 50,
    "total_pages": 3,
    "has_next": true,
    "has_prev": false
  }
}
```

### System Operations

#### Health Check
```
GET /health
```

**Response:**
```json
{
  "success": true,
  "data": {
    "status": "ok",
    "version": "1.0.0",
    "uptime": 3600
  }
}
```

#### System Status
```
GET /system/status
```

**Response:**
```json
{
  "success": true,
  "data": {
    "status": "operational",
    "version": "1.0.0",
    "uptime": 3600,
    "timestamp": "2025-11-18T12:00:00Z"
  }
}
```

#### System Metrics
```
GET /system/metrics
```

**Response:**
```json
{
  "success": true,
  "data": {
    "memory_usage": 52428800,
    "memory_peak": 62428800,
    "uptime": 3600
  }
}
```

## Rate Limiting

Default rate limits:
- **100 requests per minute** per API key
- **200 requests per minute** burst limit

Rate limit information is included in response headers:
```
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 95
X-RateLimit-Reset: 1700000000
```

When rate limit is exceeded:
```json
{
  "success": false,
  "error": "Rate limit exceeded. Try again in 30 seconds",
  "error_code": "RATE_LIMIT_EXCEEDED",
  "details": {
    "retry_after": 1700000030
  }
}
```

## Error Responses

All error responses follow this format:

```json
{
  "success": false,
  "error": "Human-readable error message",
  "error_code": "MACHINE_READABLE_CODE",
  "details": {},
  "timestamp": "2025-11-18T12:00:00Z",
  "request_id": "req_abc123"
}
```

### Common Error Codes

| Code | HTTP Status | Description |
|------|-------------|-------------|
| `UNAUTHORIZED` | 401 | Missing or invalid API key |
| `RATE_LIMIT_EXCEEDED` | 429 | Too many requests |
| `RESOURCE_NOT_FOUND` | 404 | API endpoint not found |
| `METHOD_NOT_ALLOWED` | 405 | HTTP method not supported |
| `INVALID_JSON` | 400 | Malformed JSON in request body |
| `MISSING_FIELD` | 400 | Required field missing |
| `INVALID_AMOUNT` | 400 | Amount must be positive |
| `INVALID_ADDRESS` | 400 | Invalid wallet address format |
| `TRANSACTION_FAILED` | 500 | Transaction could not be completed |
| `INTERNAL_ERROR` | 500 | Server error |

## Example Usage

### cURL Examples

```bash
# Get wallet balance
curl -H "X-API-Key: your_key_here" \
  http://localhost/src/api/v1/wallet/balance

# Send transaction
curl -X POST \
  -H "X-API-Key: your_key_here" \
  -H "Content-Type: application/json" \
  -d '{"recipient":"http://bob.example.com","amount":10.50,"currency":"USD"}' \
  http://localhost/src/api/v1/wallet/send

# Get transaction history
curl -H "X-API-Key: your_key_here" \
  "http://localhost/src/api/v1/wallet/transactions?limit=10&type=sent"
```

### JavaScript/Node.js Example

```javascript
const API_KEY = 'your_api_key_here';
const BASE_URL = 'http://localhost/src/api/v1';

async function getBalance() {
  const response = await fetch(`${BASE_URL}/wallet/balance`, {
    headers: {
      'X-API-Key': API_KEY
    }
  });

  const data = await response.json();
  console.log(data);
}

async function sendTransaction(recipient, amount) {
  const response = await fetch(`${BASE_URL}/wallet/send`, {
    method: 'POST',
    headers: {
      'X-API-Key': API_KEY,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      recipient,
      amount,
      currency: 'USD'
    })
  });

  const data = await response.json();
  console.log(data);
}
```

### Python Example

```python
import requests

API_KEY = 'your_api_key_here'
BASE_URL = 'http://localhost/src/api/v1'

headers = {
    'X-API-Key': API_KEY
}

# Get balance
response = requests.get(f'{BASE_URL}/wallet/balance', headers=headers)
print(response.json())

# Send transaction
payload = {
    'recipient': 'http://bob.example.com',
    'amount': 10.50,
    'currency': 'USD'
}
response = requests.post(f'{BASE_URL}/wallet/send', json=payload, headers=headers)
print(response.json())
```

## Security Best Practices

1. **Use HTTPS in production** - Set `https_only: true` in config
2. **Keep API keys secret** - Never commit keys to version control
3. **Rotate keys regularly** - Generate new keys periodically
4. **Revoke unused keys** - Remove keys that are no longer needed
5. **Monitor API usage** - Check logs for suspicious activity
6. **Implement IP whitelisting** - Restrict API access by IP if needed

## Configuration

Edit `/etc/eiou/src/api/v1/config.php` to customize:
- Rate limiting settings
- CORS origins
- Authentication requirements
- Logging configuration
- Response format

## Support

For issues or questions:
- Repository: https://github.com/eiou-org/eiou-docker
- Documentation: [API Reference](https://docs.eiou.org/api)

---

*EIOU REST API v1.0.0 - Copyright 2025*
