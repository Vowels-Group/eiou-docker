# EIOU Docker Compose Setup

This repository provides Docker Compose configurations for running EIOU nodes in various network topologies. Each configuration includes named volumes for persistent data storage and automatic network setup.

## Key Features
- **Contact Management**: Build and manage trust networks with configurable fees and credit limits
- **P2P Transactions**: Automatic multi-hop payment routing through trust networks
- **Multi-Transport**: HTTP, HTTPS, and Tor network support
- **REST API**: Full API with HMAC-SHA256 authentication
- **Web GUI Dashboard**: Full-featured web interface for node management and monitoring
- **CLI Interface**: Complete command-line management tools
- **Encrypted Backups**: Automatic daily database backups encrypted with AES-256-GCM
- **Persistent Storage**: Named volumes for MySQL data, configuration, and backups

## Testing

The project includes comprehensive testing infrastructure:

```bash
# Run unit tests (PHPUnit)
cd files && composer test

# Run integration tests
cd tests && ./run-all-tests.sh http4
```

See [Testing Guide](docs/TESTING.md) for detailed documentation.

## Documentation

| Document | Description |
|----------|-------------|
| [Architecture](docs/ARCHITECTURE.md) | System architecture and design |
| [Docker Configuration](docs/DOCKER_CONFIGURATION.md) | Environment variables and volume mounts |
| [Upgrade Guide](docs/UPGRADE_GUIDE.md) | How to update your node while preserving data |
| [API Reference](docs/API_REFERENCE.md) | REST API documentation |
| [API Quick Reference](docs/API_QUICK_REFERENCE.md) | API endpoint summary |
| [GUI Reference](docs/GUI_REFERENCE.md) | Web interface documentation |
| [GUI Quick Reference](docs/GUI_QUICK_REFERENCE.md) | GUI feature summary |
| [CLI Reference](docs/CLI_REFERENCE.md) | Command-line interface documentation |
| [Error Codes](docs/ERROR_CODES.md) | Error codes and troubleshooting |
| [Testing Guide](docs/TESTING.md) | Unit and integration testing documentation |
| [CLI Demo Guide](docs/CLI_DEMO_GUIDE.md) | Step-by-step CLI command walkthrough |
| [Error Handling Policy](docs/ERROR_HANDLING_POLICY.md) | Error handling standards |

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

## Available Configurations (pre-made)

| Configuration | Nodes | Memory Usage | HTTP Ports | HTTPS Ports | Description |
|---------------|-------|--------------|------------|-------------|-------------|
| [`docker-compose-single.yml`](https://github.com/eiou-org/eiou-docker/blob/main/docker-compose-single.yml) | 1 | ~512MB | 80 | 443 | Single EIOU node for testing |
| [`docker-compose-4line.yml`](https://github.com/eiou-org/eiou-docker/blob/main/docker-compose-4line.yml) | 4 | ~2GB | 8080-8083 | 8443-8446 | Basic 4-node line topology |
| [`docker-compose-10line.yml`](https://github.com/eiou-org/eiou-docker/blob/main/docker-compose-10line.yml) | 10 | ~5GB | 8080-8089 | 8443-8452 | Extended 10-node line topology |
| [`docker-compose-cluster.yml`](https://github.com/eiou-org/eiou-docker/blob/main/docker-compose-cluster.yml) | 13 | ~6.5GB | 8080-8092 | 8443-8455 | Cluster topology with hierarchical structure |

**Resource Limits:** All containers are configured with resource limits (1.0 CPU, 512MB memory limit, 256MB reservation).

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

# Generate HTTP/HTTPS address for a specific node (setting hostname automatically derives hostname_secure)
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
Under [tests/old/demo](https://github.com/eiou-org/eiou-docker/tree/main/tests/old/demo) are three folders containing pre-made topologies for HTTP, HTTPS, and TOR. These topologies come with an overview image depicting the topology and several files, either in .txt format (for easy copy-pasting) and/or .sh format for running through bash.

Below are all the .sh files listed for easy access, note the two versions of each file. The 'basic setup' and 'basic test setup', the former sets up the topology as described in the image in the folder. The later does the same as the former but also runs a few functions, like sending some transactions and checking contact information.

#### HTTP
| Configuration | Nodes | Memory Usage | Description |
|---------------|-------|--------------|-------------|
| [http4 basic setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/HTTP/4%20contacts%20line%20(http4%20~1.1gb%20memory)/http4%20(basic%20setup%2C%20shell%20script).sh) | 4 | ~1.1GB | Basic 4-node line topology |
| [http4 basic test setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/HTTP/4%20contacts%20line%20(http4%20~1.1gb%20memory)/http4%20(shell%20test%20script).sh) | 4 | ~1.1GB | Basic 4-node line topology |
| [demo4 basic setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/HTTP/4%20contacts%2C%20Alice%20Bob%20Carol%20Daniel%20(~1.1gb%20memory)/demo%204%20(basic%20setup%2C%20shell%20script)%20copy.sh) | 4 | ~1.1GB | Basic 4-node line topology |
| [demo4 basic test setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/HTTP/4%20contacts%2C%20Alice%20Bob%20Carol%20Daniel%20(~1.1gb%20memory)/demo%204%20(shell%20test%20script).sh) | 4 | ~1.1GB | Basic 4-node line topology |
| [http10 basic setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/HTTP/10%20contacts%20line%20(http10%20~2.8gb%20memory)/http10%20(basic%20setup%2C%20shell%20script).sh) | 10 | ~2.8GB | Extended 10-node line topology |
| [http10 basic test setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/HTTP/10%20contacts%20line%20(http10%20~2.8gb%20memory)/http10%20(shell%20test%20script).sh)| 10 | ~2.8GB | Extended 10-node line topology |
| [Small Cluster basic setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/HTTP/13%20contacts%20cluster%20(http_small_cluster%20~3.5gb%20memory)/http_small_cluster%20(basic%20setup%2C%20shell%20script).sh) | 13 | ~3.5GB | 13-node cluster topology |
| [Small Cluster basic test setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/HTTP/13%20contacts%20cluster%20(http_small_cluster%20~3.5gb%20memory)/http_small_cluster%20(shell%20test%20script).sh)| 13 | ~3.5GB | 13-node cluster topology |
| [HTTP Cluster basic setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/HTTP/37%20contacts%20cluster%20(http_cluster%20%20~9.5gb%20memory)/http_cluster%20(basic%20setup%2C%20shell%20script).sh) | 37 | ~9.5GB | 37-node cluster topology |
| [HTTP Cluster basic test setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/HTTP/37%20contacts%20cluster%20(http_cluster%20%20~9.5gb%20memory)/http_cluster%20(shell%20test%20script).sh)| 37 | ~9.5GB | 37-node cluster topology |


#### HTTPS
| Configuration | Nodes | Memory Usage | Description |
|---------------|-------|--------------|-------------|
| [https4 basic setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/HTTPS/4%20contacts%20line%20(http4%20~1.1gb%20memory)/https4%20(basic%20setup%2C%20shell%20script).sh) | 4 | ~1.1GB | Basic 4-node line topology |
| [https4 basic test setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/HTTPS/4%20contacts%20line%20(http4%20~1.1gb%20memory)/https4%20(shell%20test%20script).sh) | 4 | ~1.1GB | Basic 4-node line topology |
| [demo4 basic setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/HTTPS/4%20contacts%2C%20Alice%20Bob%20Carol%20Daniel%20(~1.1gb%20memory)/demo%204%20(basic%20setup%2C%20shell%20script).sh) | 4 | ~1.1GB | Basic 4-node line topology |
| [demo4 basic test setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/HTTPS/4%20contacts%2C%20Alice%20Bob%20Carol%20Daniel%20(~1.1gb%20memory)/demo%204%20(shell%20test%20script).sh) | 4 | ~1.1GB | Basic 4-node line topology |
| [https10 basic setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/HTTPS/10%20contacts%20line%20(http10%20~2.8gb%20memory)/https10%20(basic%20setup%2C%20shell%20script).sh) | 10 | ~2.8GB | Extended 10-node line topology |
| [https10 basic test setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/HTTPS/10%20contacts%20line%20(http10%20~2.8gb%20memory)/https10%20(shell%20test%20script).sh) | 10 | ~2.8GB | Extended 10-node line topology |
| [HTTPS Small Cluster basic setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/HTTPS/13%20contacts%20cluster%20(http_small_cluster%20~3.5gb%20memory)/https_small_cluster%20(basic%20setup%2C%20shell%20script).sh) | 13 | ~3.5GB | 13-node cluster topology |
| [HTTPS Small Cluster basic test setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/HTTPS/13%20contacts%20cluster%20(http_small_cluster%20~3.5gb%20memory)/https_small_cluster%20(shell%20test%20script).sh) | 13 | ~3.5GB | 13-node cluster topology |
| [HTTPS Cluster basic setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/HTTPS/37%20contacts%20cluster%20(http_cluster%20%20~9.5gb%20memory)/https_cluster%20(basic%20setup%2C%20shell%20script).sh) | 37 | ~9.5GB | 37-node cluster topology |
| [HTTPS Cluster basic test setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/HTTPS/37%20contacts%20cluster%20(http_cluster%20%20~9.5gb%20memory)/https_cluster%20(shell%20test%20script).sh) | 37 | ~9.5GB | 37-node cluster topology |


#### TOR
| Configuration | Nodes | Memory Usage | Description |
|---------------|-------|--------------|-------------|
| [tor4 basic setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/Tor/4%20contacts%20line%20(tor4%20~1.1gb%20memory)/tor4%20(basic%20setup%2C%20shell%20script).sh) | 4 | ~1.1GB | Basic 4-node line topology |
| [tor4 basic test setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/Tor/4%20contacts%20line%20(tor4%20~1.1gb%20memory)/tor4%20(shell%20test%20script).sh) | 4 | ~1.1GB | Basic 4-node line topology |
| [tor10 basic setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/Tor/10%20contacts%20line%20(tor10%20~2.8gb%20memory)/tor10%20(basic%20setup%2C%20shell%20script).sh) | 10 | ~2.8GB | Extended 10-node line topology |
| [tor10 basic test setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/Tor/10%20contacts%20line%20(tor10%20~2.8gb%20memory)/tor10%20(shell%20test%20script).sh)| 10 | ~2.8GB | Extended 10-node line topology |
| [Tor Small Cluster basic setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/Tor/13%20contacts%20cluster%20(tor_small_cluster%20~3.5gb%20memory)/tor_small_cluster%20(basic%20setup%2C%20shell%20script).sh) | 13 | ~3.5GB | 13-node cluster topology |
| [Tor Small Cluster basic test setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/Tor/13%20contacts%20cluster%20(tor_small_cluster%20~3.5gb%20memory)/tor_small_cluster%20(shell%20test%20script).sh)| 13 | ~3.5GB | 13-node cluster topology |
| [Tor Cluster basic setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/Tor/37%20contacts%20cluster%20(tor_cluster%20%20~9.5gb%20memory)/tor_cluster%20(basic%20setup%2C%20shell%20script).sh) | 37 | ~9.5GB | 37-node cluster topology |
| [Tor Cluster basic test setup](https://github.com/eiou-org/eiou-docker/blob/main/tests/old/demo/Tor/37%20contacts%20cluster%20(tor_cluster%20%20~9.5gb%20memory)/tor_cluster%20(shell%20test%20script).sh)| 37 | ~9.5GB | 37-node cluster topology |


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

### Cluster Topology (13 nodes)
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
