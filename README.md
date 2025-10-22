# EIOU Docker Compose Setup

This repository provides Docker Compose configurations for running EIOU nodes in various network topologies. Each configuration includes named volumes for persistent data storage and automatic network setup.

## Prerequisites

- Docker
- Docker Compose

## Quick Start

### Single Node Setup
```bash
# Run a single EIOU node
docker-compose -f docker-compose-single.yml up -d --build
```

### 4-Node Line Setup (Alice, Bob, Carol, Daniel)
```bash
# Run 4 nodes in a line topology
docker-compose -f docker-compose-4line.yml up -d --build
```

### 10-Node Line Setup
```bash
# Run 10 nodes in a line topology
docker-compose -f docker-compose-10line.yml up -d --build
```

### 13-Node Cluster Setup
```bash
# Run 13 nodes in a cluster topology
docker-compose -f docker-compose-cluster.yml up -d --build
```

## Available Configurations

| Configuration | Nodes | Memory Usage | Description |
|---------------|-------|--------------|-------------|
| [`docker-compose-single.yml`](https://github.com/eiou-org/eiou/blob/main/docker-compose-single.yml) | 1 | ~1.1GB | Single EIOU node for testing |
| [`docker-compose-4line.yml`](https://github.com/eiou-org/eiou/blob/main/docker-compose-4line.yml) | 4 | ~1.1GB | Basic 4-node line topology |
| [`docker-compose-10line.yml`](https://github.com/eiou-org/eiou/blob/main/docker-compose-10line.yml) | 10 | ~2.8GB | Extended 10-node line topology |
| [`docker-compose-cluster.yml`](https://github.com/eiou-org/eiou/blob/main/docker-compose-cluster.yml) | 13 | ~3.5GB | Cluster topology with hierarchical structure |

## Container Management

### View Running Containers
```bash
# List all running containers
docker-compose -f <config-file>.yml ps

# View logs from all containers
docker-compose -f <config-file>.yml logs

# Follow logs in real-time
docker-compose -f <config-file>.yml logs -f
```

### Execute Commands in Containers
```bash
# Generate Tor address for a specific node
docker-compose -f docker-compose-4line.yml exec alice eiou generate torAddressOnly

# Generate HTTP address for a specific node
docker-compose -f docker-compose-4line.yml exec alice eiou generate http://alice

# Add a contact to a node
docker-compose -f docker-compose-4line.yml exec alice eiou add <address> <name> <fee> <credit> <currency>
```

### Stop and Cleanup
```bash
# Stop all containers (preserves data)
docker-compose -f <config-file>.yml down

# Stop and remove all data volumes (WARNING: deletes all data)
docker-compose -f <config-file>.yml down -v

# Restart all containers
docker-compose -f <config-file>.yml restart

# Restart specific container
docker-compose -f docker-compose-4line.yml restart alice
```

## Network Topologies (conceptuals)

### Pre-made test topologies 
Under [test/demo](https://github.com/eiou-org/eiou/tree/main/tests/demo) are two folders containing pre-made topologies for both HTTP and TOR. These topologies come with an overview image depicting the topology and several files, either in .txt format (for easy copy-pasting) and/or .sh format for running through bash.

Below are all the .sh files listed for easy access, note the two versions of each file. The 'basic setup' and 'basic test setup', the former sets up the topology as described in the image in the folder. The later does the same as the former but also runs a few functions, like sending some transactions and checking contact information.

#### HTTP
| Configuration | Nodes | Memory Usage | Description |
|---------------|-------|--------------|-------------|
| [http4 basic setup](https://github.com/eiou-org/eiou/blob/main/tests/demo/HTTP/4%20contacts%20line%20(http4%20~1.1gb%20memory)/http4%20(basic%20setup%2C%20shell%20script).sh) | 4 | ~1.1GB | Basic 4-node line topology |
| [http4 basic test setup](https://github.com/eiou-org/eiou/blob/main/tests/demo/HTTP/4%20contacts%20line%20(http4%20~1.1gb%20memory)/http4%20(shell%20test%20script).sh) | 4 | ~1.1GB | Basic 4-node line topology |
| [demo4 basic setup](https://github.com/eiou-org/eiou/blob/main/tests/demo/HTTP/4%20contacts%2C%20Alice%20Bob%20Carol%20Daniel%20(~1.1gb%20memory)/demo%204%20(basic%20setup%2C%20shell%20script)%20copy.sh) | 4 | ~1.1GB | Basic 4-node line topology |
| [demo4 basic test setup](https://github.com/eiou-org/eiou/blob/main/tests/demo/HTTP/4%20contacts%2C%20Alice%20Bob%20Carol%20Daniel%20(~1.1gb%20memory)/demo%204%20(shell%20test%20script).sh) | 4 | ~1.1GB | Basic 4-node line topology |
| [http10 basic setup](https://github.com/eiou-org/eiou/blob/main/tests/demo/HTTP/10%20contacts%20line%20(http10%20~2.8gb%20memory)/http10%20(basic%20setup%2C%20shell%20script).sh) | 10 | ~2.8GB | Extended 10-node line topology |
| [http10 basic test setup](https://github.com/eiou-org/eiou/blob/main/tests/demo/HTTP/10%20contacts%20line%20(http10%20~2.8gb%20memory)/http10%20(shell%20test%20script).sh)| 10 | ~2.8GB | Extended 10-node line topology |
| [Small Cluster basic setup](https://github.com/eiou-org/eiou/blob/main/tests/demo/HTTP/13%20contacts%20cluster%20(http_small_cluster%20~3.5gb%20memory)/http_small_cluster%20(basic%20setup%2C%20shell%20script).sh) | 13 | ~3.5GB | 13-node cluster topology |
| [Small Cluster basic test setup](https://github.com/eiou-org/eiou/blob/main/tests/demo/HTTP/13%20contacts%20cluster%20(http_small_cluster%20~3.5gb%20memory)/http_small_cluster%20(shell%20test%20script).sh)| 13 | ~3.5GB | 13-node cluster topology |
| [HTTP Cluster basic setup](https://github.com/eiou-org/eiou/blob/main/tests/demo/HTTP/37%20contacts%20cluster%20(http_cluster%20%20~9.5gb%20memory)/http_cluster%20(basic%20setup%2C%20shell%20script).sh) | 37 | ~9.5GB | 37-node cluster topology |
| [HTTP Cluster basic test setup](https://github.com/eiou-org/eiou/blob/main/tests/demo/HTTP/37%20contacts%20cluster%20(http_cluster%20%20~9.5gb%20memory)/http_cluster%20(shell%20test%20script).sh)| 37 | ~9.5GB | 37-node cluster topology |


#### TOR
| Configuration | Nodes | Memory Usage | Description |
|---------------|-------|--------------|-------------|
| [tor4 basic setup](https://github.com/eiou-org/eiou/blob/main/tests/demo/Tor/4%20contacts%20line%20(tor4%20~1.1gb%20memory)/tor4%20(basic%20setup%2C%20shell%20script).sh) | 4 | ~1.1GB | Basic 4-node line topology |
| [tor4 basic test setup](https://github.com/eiou-org/eiou/blob/main/tests/demo/Tor/4%20contacts%20line%20(tor4%20~1.1gb%20memory)/tor4%20(shell%20test%20script).sh) | 4 | ~1.1GB | Basic 4-node line topology |
| [tor10 basic setup](https://github.com/eiou-org/eiou/blob/main/tests/demo/Tor/10%20contacts%20line%20(tor10%20~2.8gb%20memory)/tor10%20(basic%20setup%2C%20shell%20script).sh) | 10 | ~2.8GB | Extended 10-node line topology |
| [tor10 basic test setup](https://github.com/eiou-org/eiou/blob/main/tests/demo/Tor/10%20contacts%20line%20(tor10%20~2.8gb%20memory)/tor10%20(shell%20test%20script).sh)| 10 | ~2.8GB | Extended 10-node line topology |
| [Tor Small Cluster basic setup](https://github.com/eiou-org/eiou/blob/main/tests/demo/Tor/13%20contacts%20cluster%20(tor_small_cluster%20~3.5gb%20memory)/tor_small_cluster%20(basic%20setup%2C%20shell%20script).sh) | 13 | ~3.5GB | 13-node cluster topology |
| [Tor Small Cluster basic test setup](https://github.com/eiou-org/eiou/blob/main/tests/demo/Tor/13%20contacts%20cluster%20(tor_small_cluster%20~3.5gb%20memory)/tor_small_cluster%20(shell%20test%20script).sh)| 13 | ~3.5GB | 13-node cluster topology |
| [Tor Cluster basic setup](https://github.com/eiou-org/eiou/blob/main/tests/demo/Tor/37%20contacts%20cluster%20(tor_cluster%20%20~9.5gb%20memory)/tor_cluster%20(basic%20setup%2C%20shell%20script).sh) | 37 | ~9.5GB | 37-node cluster topology |
| [Tor Cluster basic test setup](https://github.com/eiou-org/eiou/blob/main/tests/demo/Tor/37%20contacts%20cluster%20(tor_cluster%20%20~9.5gb%20memory)/tor_cluster%20(shell%20test%20script).sh)| 37 | ~9.5GB | 37-node cluster topology |


### Line Topology (4 nodes)
<img width="2640" height="192" alt="topological 4 - overview (alice, bob, carol, daniel)" src="https://github.com/user-attachments/assets/a5da5519-7c22-4591-89f1-e27d699c576b" />

```bash
# alice adds bob and bob adds alice
docker-compose -f docker-compose-4line.yml exec alice eiou add <address> bob <fee> <credit> <currency>
docker-compose -f docker-compose-4line.yml exec bob eiou add <address> alice <fee> <credit> <currency>
# bob adds carol and carol adds bob
docker-compose -f docker-compose-4line.yml exec bob eiou add <address> carol <fee> <credit> <currency>
docker-compose -f docker-compose-4line.yml exec carol eiou add <address> bob <fee> <credit> <currency>
# carol adds daniel and daniel adds carol
docker-compose -f docker-compose-4line.yml exec carol eiou add <address> daniel <fee> <credit> <currency>
docker-compose -f docker-compose-4line.yml exec daniel eiou add <address> carol <fee> <credit> <currency>
```

### Line Topology (10 nodes)
<img width="2640" height="192" alt="toplogical 10" src="https://github.com/user-attachments/assets/15c36014-1e25-4a32-9bdf-b2b3f1f9948f" />

```bash
# node-a adds node-b and node-b adds node-a
docker-compose -f docker-compose-10line.yml exec node-a eiou add <address> node-b <fee> <credit> <currency>
docker-compose -f docker-compose-10line.yml exec node-b eiou add <address> node-a <fee> <credit> <currency>
# node-b adds node-c and node-c adds node-b
docker-compose -f docker-compose-10line.yml exec node-b eiou add <address> node-c <fee> <credit> <currency>
docker-compose -f docker-compose-10line.yml exec node-c eiou add <address> node-b <fee> <credit> <currency>
# node-c adds node-d and node-d adds node-c
docker-compose -f docker-compose-10line.yml exec node-c eiou add <address> node-d <fee> <credit> <currency>
docker-compose -f docker-compose-10line.yml exec node-d eiou add <address> node-c <fee> <credit> <currency>
# node-d adds node-e and node-e adds node-d
docker-compose -f docker-compose-10line.yml exec node-d eiou add <address> node-e <fee> <credit> <currency>
docker-compose -f docker-compose-10line.yml exec node-e eiou add <address> node-d <fee> <credit> <currency>
# node-e adds node-f and node-f adds node-e
docker-compose -f docker-compose-10line.yml exec node-e eiou add <address> node-f <fee> <credit> <currency>
docker-compose -f docker-compose-10line.yml exec node-f eiou add <address> node-e <fee> <credit> <currency>
# node-f adds node-g and node-g adds node-f
docker-compose -f docker-compose-10line.yml exec node-f eiou add <address> node-g <fee> <credit> <currency>
docker-compose -f docker-compose-10line.yml exec node-g eiou add <address> node-f <fee> <credit> <currency>
# node-g adds node-h and node-h adds node-g
docker-compose -f docker-compose-10line.yml exec node-g eiou add <address> node-h <fee> <credit> <currency>
docker-compose -f docker-compose-10line.yml exec node-h eiou add <address> node-g <fee> <credit> <currency>
# node-h adds node-i and node-i adds node-h
docker-compose -f docker-compose-10line.yml exec node-h eiou add <address> node-i <fee> <credit> <currency>
docker-compose -f docker-compose-10line.yml exec node-i eiou add <address> node-h <fee> <credit> <currency>
# node-i adds node-j and node-j adds node-i
docker-compose -f docker-compose-10line.yml exec node-i eiou add <address> node-j <fee> <credit> <currency>
docker-compose -f docker-compose-10line.yml exec node-j eiou add <address> node-i <fee> <credit> <currency>
```

### Line Topology (13 nodes)
<img width="2640" height="1414" alt="topological cluster 13" src="https://github.com/user-attachments/assets/187cfd3b-f16d-4aaf-88bf-e46630192ff2" />

```bash
# bottom right branch
# cluster-a adds cluster-a1 and cluster-a1 adds cluster-a0
docker-compose -f docker-compose-cluster.yml exec cluster-a0 eiou add <address> cluster-a1 <fee> <credit> <currency>
docker-compose -f docker-compose-cluster.yml exec cluster-a1 eiou add <address> cluster-a0 <fee> <credit> <currency>
# cluster-a1 adds cluster-a11 and cluster-a11 adds cluster-a1
docker-compose -f docker-compose-cluster.yml exec cluster-a1 eiou add <address> cluster-a11 <fee> <credit> <currency>
docker-compose -f docker-compose-cluster.yml exec cluster-a11 eiou add <address> cluster-a1 <fee> <credit> <currency>
# cluster-a1 adds cluster-a12 and cluster-a12 adds node-a1
docker-compose -f docker-compose-cluster.yml exec cluster-a1 eiou add <address> cluster-a12 <fee> <credit> <currency>
docker-compose -f docker-compose-cluster.yml exec cluster-a12 eiou add <address> cluster-a1 <fee> <credit> <currency>

# bottom left branch
# cluster-a adds cluster-a2 and cluster-a2 adds cluster-a0
docker-compose -f docker-compose-cluster.yml exec cluster-a0 eiou add <address> cluster-a2 <fee> <credit> <currency>
docker-compose -f docker-compose-cluster.yml exec cluster-a2 eiou add <address> cluster-a0 <fee> <credit> <currency>
# cluster-a2 adds cluster-a21 and cluster-a21 adds cluster-a2
docker-compose -f docker-compose-cluster.yml exec cluster-a2 eiou add <address> cluster-a21 <fee> <credit> <currency>
docker-compose -f docker-compose-cluster.yml exec cluster-a21 eiou add <address> cluster-a2 <fee> <credit> <currency>
# cluster-a2 adds cluster-a22 and cluster-a22 adds cluster-a1
docker-compose -f docker-compose-cluster.yml exec cluster-a2 eiou add <address> cluster-a22 <fee> <credit> <currency>
docker-compose -f docker-compose-cluster.yml exec cluster-a22 eiou add <address> cluster-a2 <fee> <credit> <currency>

# top left branch
# cluster-a adds cluster-a3 and cluster-a3 adds cluster-a0
docker-compose -f docker-compose-cluster.yml exec cluster-a0 eiou add <address> cluster-a3 <fee> <credit> <currency>
docker-compose -f docker-compose-cluster.yml exec cluster-a3 eiou add <address> cluster-a0 <fee> <credit> <currency>
# cluster-a3 adds cluster-a31 and cluster-a31 adds cluster-a3
docker-compose -f docker-compose-cluster.yml exec cluster-a3 eiou add <address> cluster-a31 <fee> <credit> <currency>
docker-compose -f docker-compose-cluster.yml exec cluster-a31 eiou add <address> cluster-a3 <fee> <credit> <currency>
# cluster-a3 adds cluster-a32 and cluster-a32 adds cluster-a3
docker-compose -f docker-compose-cluster.yml exec cluster-a3 eiou add <address> cluster-a32 <fee> <credit> <currency>
docker-compose -f docker-compose-cluster.yml exec cluster-a32 eiou add <address> cluster-a3 <fee> <credit> <currency>

# top right branch
# cluster-a adds cluster-a4 and cluster-a4 adds cluster-a0
docker-compose -f docker-compose-cluster.yml exec cluster-a0 eiou add <address> cluster-a4 <fee> <credit> <currency>
docker-compose -f docker-compose-cluster.yml exec cluster-a4 eiou add <address> cluster-a0 <fee> <credit> <currency>
# cluster-a4 adds cluster-a41 and cluster-a41 adds cluster-a4
docker-compose -f docker-compose-cluster.yml exec cluster-a4 eiou add <address> cluster-a41 <fee> <credit> <currency>
docker-compose -f docker-compose-cluster.yml exec cluster-a41 eiou add <address> cluster-a4 <fee> <credit> <currency>
# cluster-a4 adds cluster-a42 and cluster-a42 adds cluster-a4
docker-compose -f docker-compose-cluster.yml exec cluster-a4 eiou add <address> cluster-a42 <fee> <credit> <currency>
docker-compose -f docker-compose-cluster.yml exec cluster-a42 eiou add <address> cluster-a4 <fee> <credit> <currency>
```

## HTTP REST API

The eIOU Docker nodes now include a complete HTTP REST API for programmatic access. This enables the Flutter wallet and other applications to communicate with Docker nodes.

### API Port Mappings

Each Docker configuration exposes HTTP API endpoints on different ports:

| Configuration | Container | Host Port | API URL |
|---------------|-----------|-----------|---------|
| Single Node | eioud-single | 8080 | http://localhost:8080/api |
| 4-Line | alice | 8081 | http://localhost:8081/api |
| 4-Line | bob | 8082 | http://localhost:8082/api |
| 4-Line | carol | 8083 | http://localhost:8083/api |
| 4-Line | daniel | 8084 | http://localhost:8084/api |
| 10-Line | node-a to node-j | 8091-8100 | http://localhost:809X/api |
| Cluster | alpha to nu | 8101-8113 | http://localhost:810X/api |

### Authentication

All API endpoints (except `/api/health` and `/api/auth`) require authentication using a session token.

#### Step 1: Get Authentication Token

```bash
curl -X POST http://localhost:8080/api/auth \
  -H "Content-Type: application/json" \
  -d '{"authcode": "YOUR_AUTHCODE_FROM_CONFIG"}'
```

Response:
```json
{
  "status": "success",
  "message": "Authentication successful",
  "data": {
    "token": "a1b2c3d4e5f6...",
    "expires_in": 3600,
    "token_type": "Bearer"
  }
}
```

#### Step 2: Use Token in Requests

Include the token in subsequent requests using the `Authorization` header:

```bash
curl -X GET http://localhost:8080/api/wallet/info \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

### API Endpoints

#### Health Check
**GET** `/api/health` - Check API status (no auth required)

```bash
curl http://localhost:8080/api/health
```

#### Wallet Endpoints

**GET** `/api/wallet/info` - Get wallet information
```bash
curl -H "Authorization: Bearer TOKEN" http://localhost:8080/api/wallet/info
```

**GET** `/api/wallet/balance` - Get wallet balance and contact balances
```bash
curl -H "Authorization: Bearer TOKEN" http://localhost:8080/api/wallet/balance
```

**POST** `/api/wallet/send` - Send transaction
```bash
curl -X POST http://localhost:8080/api/wallet/send \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "recipient": "contact_name_or_address",
    "amount": 10.50,
    "currency": "USD"
  }'
```

#### Contact Endpoints

**GET** `/api/contacts` - List all contacts
```bash
curl -H "Authorization: Bearer TOKEN" http://localhost:8080/api/contacts
```

**POST** `/api/contacts` - Add new contact
```bash
curl -X POST http://localhost:8080/api/contacts \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "address": "http://bob/",
    "name": "Bob",
    "fee": 0.1,
    "credit": 100,
    "currency": "USD"
  }'
```

**GET** `/api/contacts/:address` - Get contact by address
```bash
curl -H "Authorization: Bearer TOKEN" \
  http://localhost:8080/api/contacts/http%3A%2F%2Fbob%2F
```

**DELETE** `/api/contacts/:address` - Delete contact
```bash
curl -X DELETE -H "Authorization: Bearer TOKEN" \
  http://localhost:8080/api/contacts/http%3A%2F%2Fbob%2F
```

#### Transaction Endpoints

**GET** `/api/transactions` - List all transactions (sent and received)
```bash
curl -H "Authorization: Bearer TOKEN" \
  "http://localhost:8080/api/transactions?limit=50"
```

**GET** `/api/transactions/sent` - List sent transactions
```bash
curl -H "Authorization: Bearer TOKEN" \
  "http://localhost:8080/api/transactions/sent?limit=50"
```

**GET** `/api/transactions/received` - List received transactions
```bash
curl -H "Authorization: Bearer TOKEN" \
  "http://localhost:8080/api/transactions/received?limit=50"
```

**GET** `/api/transactions/history` - Get transaction statistics
```bash
curl -H "Authorization: Bearer TOKEN" \
  http://localhost:8080/api/transactions/history
```

**GET** `/api/transactions/:txid` - Get transaction by ID
```bash
curl -H "Authorization: Bearer TOKEN" \
  http://localhost:8080/api/transactions/abc123def456...
```

### API Response Format

All API responses follow this standard format:

**Success Response:**
```json
{
  "status": "success",
  "message": "Operation completed successfully",
  "data": {
    // Response data here
  }
}
```

**Error Response:**
```json
{
  "status": "error",
  "message": "Error description",
  "errors": {
    // Optional additional error details
  }
}
```

### HTTP Status Codes

- `200 OK` - Request succeeded
- `201 Created` - Resource created successfully
- `204 No Content` - Request succeeded with no response body
- `400 Bad Request` - Invalid request parameters
- `401 Unauthorized` - Missing or invalid authentication token
- `403 Forbidden` - Insufficient permissions
- `404 Not Found` - Resource not found
- `500 Internal Server Error` - Server error

### CORS Support

The API includes full CORS support with the following headers:
- `Access-Control-Allow-Origin: *`
- `Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS`
- `Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With`

This allows the API to be accessed from web browsers and Flutter applications.

### Getting Your Authentication Code

The authentication code is generated when you first create a wallet. To view it:

```bash
# For single node
docker-compose -f docker-compose-single.yml exec eioud-single cat /etc/eiou/config.php | grep authcode

# For 4-line nodes
docker-compose -f docker-compose-4line.yml exec alice cat /etc/eiou/config.php | grep authcode
```

### Example: Complete API Workflow

```bash
# 1. Start a single node
docker-compose -f docker-compose-single.yml up -d --build

# 2. Wait for initialization (about 10 seconds)
sleep 10

# 3. Get the authcode
AUTHCODE=$(docker-compose -f docker-compose-single.yml exec eioud-single \
  cat /etc/eiou/config.php | grep 'authcode' | cut -d'"' -f2)

# 4. Authenticate and get token
TOKEN=$(curl -s -X POST http://localhost:8080/api/auth \
  -H "Content-Type: application/json" \
  -d "{\"authcode\": \"$AUTHCODE\"}" | jq -r '.data.token')

# 5. Get wallet info
curl -s http://localhost:8080/api/wallet/info \
  -H "Authorization: Bearer $TOKEN" | jq

# 6. Check balance
curl -s http://localhost:8080/api/wallet/balance \
  -H "Authorization: Bearer $TOKEN" | jq

# 7. List contacts
curl -s http://localhost:8080/api/contacts \
  -H "Authorization: Bearer $TOKEN" | jq

# 8. View transactions
curl -s http://localhost:8080/api/transactions \
  -H "Authorization: Bearer $TOKEN" | jq
```

### Connecting Flutter Wallet

To connect the Flutter wallet to a local Docker node:

1. Start the Docker node with API port exposed (already configured in docker-compose files)
2. Get the authentication code from the container
3. In the Flutter app, configure the API endpoint:
   - Development: `http://localhost:8080/api` (or appropriate port)
   - Production: Use the container's HTTP address
4. Use the authentication code to obtain a session token
5. The Flutter app can now make authenticated API calls

### Troubleshooting

**Connection refused:**
```bash
# Verify container is running
docker-compose -f docker-compose-single.yml ps

# Check logs for errors
docker-compose -f docker-compose-single.yml logs eioud-single
```

**Authentication errors:**
```bash
# Verify authcode is correct
docker-compose -f docker-compose-single.yml exec eioud-single \
  cat /etc/eiou/config.php | grep authcode
```

**API not responding:**
```bash
# Check if web server is running inside container
docker-compose -f docker-compose-single.yml exec eioud-single \
  curl http://localhost/api/health
```

### Security Notes

- The authentication code is sensitive - keep it secure
- Session tokens expire after 1 hour of inactivity
- In production, use HTTPS and restrict CORS origins
- Consider implementing rate limiting for production deployments
- The current implementation uses in-memory session storage - sessions are lost on container restart

