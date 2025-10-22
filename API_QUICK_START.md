# eIOU Docker API - Quick Start Guide

## 🚀 Get Started in 5 Minutes

### Step 1: Start a Docker Node

```bash
cd /home/admin/eiou/ai-dev/github/eiou-docker
docker-compose -f docker-compose-single.yml up -d --build
```

Wait about 10 seconds for initialization.

### Step 2: Get Your Authentication Code

```bash
AUTHCODE=$(docker-compose -f docker-compose-single.yml exec eioud-single \
  cat /etc/eiou/config.php | grep 'authcode' | cut -d'"' -f2)
echo "Your authcode: $AUTHCODE"
```

### Step 3: Authenticate

```bash
# Get session token
TOKEN=$(curl -s -X POST http://localhost:8080/api/auth \
  -H "Content-Type: application/json" \
  -d "{\"authcode\": \"$AUTHCODE\"}" | jq -r '.data.token')

echo "Your token: $TOKEN"
```

### Step 4: Make API Calls

```bash
# Get wallet info
curl -s http://localhost:8080/api/wallet/info \
  -H "Authorization: Bearer $TOKEN" | jq

# Get balance
curl -s http://localhost:8080/api/wallet/balance \
  -H "Authorization: Bearer $TOKEN" | jq

# List contacts
curl -s http://localhost:8080/api/contacts \
  -H "Authorization: Bearer $TOKEN" | jq

# List transactions
curl -s http://localhost:8080/api/transactions \
  -H "Authorization: Bearer $TOKEN" | jq
```

## 🎯 Common Operations

### Add a Contact

```bash
curl -X POST http://localhost:8080/api/contacts \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "address": "http://bob/",
    "name": "Bob",
    "fee": 0.1,
    "credit": 100,
    "currency": "USD"
  }' | jq
```

### Send a Transaction

```bash
curl -X POST http://localhost:8080/api/wallet/send \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "recipient": "Bob",
    "amount": 10.50,
    "currency": "USD"
  }' | jq
```

### View Transaction History

```bash
curl -s http://localhost:8080/api/transactions/history \
  -H "Authorization: Bearer $TOKEN" | jq
```

## 📡 Available Endpoints

### No Authentication Required
- `GET /api/health` - API health check

### Authentication
- `POST /api/auth` - Get session token

### Wallet
- `GET /api/wallet/info` - Wallet information
- `GET /api/wallet/balance` - Balance and contact balances
- `POST /api/wallet/send` - Send transaction

### Contacts
- `GET /api/contacts` - List all contacts
- `POST /api/contacts` - Add contact
- `GET /api/contacts/:address` - Get specific contact
- `DELETE /api/contacts/:address` - Delete contact

### Transactions
- `GET /api/transactions` - All transactions
- `GET /api/transactions/sent` - Sent only
- `GET /api/transactions/received` - Received only
- `GET /api/transactions/history` - Statistics
- `GET /api/transactions/:txid` - Specific transaction

## 🔧 Port Mappings

| Docker Compose File | Container | Port |
|---------------------|-----------|------|
| single | eioud-single | 8080 |
| 4-line | alice | 8081 |
| 4-line | bob | 8082 |
| 4-line | carol | 8083 |
| 4-line | daniel | 8084 |
| 10-line | node-a to node-j | 8091-8100 |
| cluster | alpha to nu | 8101-8113 |

## 🐛 Troubleshooting

### Connection Refused

```bash
# Check if container is running
docker-compose -f docker-compose-single.yml ps

# View logs
docker-compose -f docker-compose-single.yml logs eioud-single
```

### Invalid Token

Tokens expire after 1 hour. Get a new one:

```bash
TOKEN=$(curl -s -X POST http://localhost:8080/api/auth \
  -H "Content-Type: application/json" \
  -d "{\"authcode\": \"$AUTHCODE\"}" | jq -r '.data.token')
```

### Check API Status

```bash
curl http://localhost:8080/api/health | jq
```

## 💡 Tips

1. **Save your token**: Store it in an environment variable for the session
2. **Use jq**: Install `jq` for pretty JSON output: `sudo apt install jq`
3. **Check logs**: Always check container logs if something doesn't work
4. **Port conflicts**: If port 8080 is in use, change it in docker-compose file
5. **CORS**: The API allows all origins by default (good for development)

## 🔒 Security Notes

- **Authcode**: Keep your authentication code secure
- **HTTPS**: Use HTTPS in production
- **CORS**: Restrict allowed origins in production
- **Tokens**: Session tokens expire after 1 hour
- **Rate Limiting**: Consider implementing for production

## 📱 Flutter Wallet Integration

1. Start Docker node with API port exposed (already configured)
2. Get authcode from container
3. Configure Flutter app with API endpoint (e.g., `http://localhost:8080/api`)
4. Authenticate to get token
5. Use token in all subsequent requests

## 🧪 Testing Multiple Nodes

```bash
# Start 4-line topology
docker-compose -f docker-compose-4line.yml up -d --build

# Get Alice's authcode
ALICE_AUTH=$(docker-compose -f docker-compose-4line.yml exec alice \
  cat /etc/eiou/config.php | grep 'authcode' | cut -d'"' -f2)

# Get Bob's authcode
BOB_AUTH=$(docker-compose -f docker-compose-4line.yml exec bob \
  cat /etc/eiou/config.php | grep 'authcode' | cut -d'"' -f2)

# Authenticate Alice (port 8081)
ALICE_TOKEN=$(curl -s -X POST http://localhost:8081/api/auth \
  -H "Content-Type: application/json" \
  -d "{\"authcode\": \"$ALICE_AUTH\"}" | jq -r '.data.token')

# Authenticate Bob (port 8082)
BOB_TOKEN=$(curl -s -X POST http://localhost:8082/api/auth \
  -H "Content-Type: application/json" \
  -d "{\"authcode\": \"$BOB_AUTH\"}" | jq -r '.data.token')

# Now you can interact with both nodes independently
curl http://localhost:8081/api/wallet/info -H "Authorization: Bearer $ALICE_TOKEN" | jq
curl http://localhost:8082/api/wallet/info -H "Authorization: Bearer $BOB_TOKEN" | jq
```

## 📚 Full Documentation

See `README.md` for complete API documentation including:
- All endpoints with examples
- Response formats
- Error handling
- CORS configuration
- Production deployment notes

## 🎉 You're Ready!

The API is now accessible and you can start building applications that interact with eIOU Docker nodes!

For issues or questions, check the main README.md or container logs.
