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
| `docker-compose-single.yml` | 1 | ~1.1GB | Single EIOU node for testing |
| `docker-compose-4line.yml` | 4 | ~1.1GB | Basic 4-node line topology |
| `docker-compose-10line.yml` | 10 | ~2.8GB | Extended 10-node line topology |
| `docker-compose-cluster.yml` | 13 | ~3.5GB | Cluster topology with hierarchical structure |

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

## Network Topologies

### Line Topology (4 nodes)