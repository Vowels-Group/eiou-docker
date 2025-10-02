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

## Network Topologies (conceptuals)

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
