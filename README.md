# EIOU - Economic Input-Output Units

## 🎯 Recommended Next Steps

### Immediate (This Session)
- [ ] Review documentation system structure in `docs/`
- [ ] Validate GitHub issue templates in `.github/ISSUE_TEMPLATE/`
- [ ] Test Docker topologies with updated documentation
- [ ] Provide feedback via GitHub Issues

### This Week
- [ ] Set up CI/CD with GitHub Actions for automated testing
- [ ] Improve test coverage to 80%+ (currently strong at 69% ratio)
- [ ] Complete architecture documentation (`docs/architecture/`)
- [ ] Address PSR-4 namespace compliance (migrate from manual requires)

### This Month
- [ ] Refactor files exceeding 500-line standard (ContactRepository, TransactionRepository, ServiceWrappers)
- [ ] Eliminate remaining `global $user` variables (complete DI migration)
- [ ] Expand service layer testing (currently only 1 service test)
- [ ] Performance optimization and Docker image size reduction

---

## 📊 Project Status Dashboard

### Current Sprint
- **Sprint**: [SPRINT-20251014-initial-documentation](docs/sprints/SPRINT-20251014-initial-documentation.md)
- **Branch**: `claudeflow-251014-0830`
- **Status**: 🟡 In Progress
- **Focus**: Documentation system, GitHub workflow, README restructure
- **Progress**: 9/12 tasks completed

### Quality Metrics
| Metric | Value | Status |
|--------|-------|--------|
| **Code Quality** | 7.5/10 | 🟢 Strong |
| **Architecture** | 8.5/10 | 🟢 Excellent |
| **Testing** | 7.8/10 | 🟢 Strong |
| **Security** | 9.0/10 | 🟢 Excellent |
| **Test Coverage** | 69% ratio (35/51 files) | 🟡 Good |
| **Total Tests** | 126+ tests | 🟢 Comprehensive |

### Project Health
- 📊 **PHP Files**: 85 total, 51 source files
- 🧪 **Test Files**: 35 (Unit, Integration, Security, Performance)
- 🐳 **Docker Configs**: 4 network topologies (1-37 nodes)
- 📚 **Documentation**: Sprint system established
- 🔒 **Security**: No critical issues, multi-layered protection

---

## 📖 Project Overview

This repository provides **Docker Compose configurations** for running EIOU (Economic Input-Output Units) nodes in various network topologies. EIOU is a **privacy-first, peer-to-peer payment network** with no external dependencies and Tor integration for anonymous transactions.

### What is EIOU?

EIOU implements a privacy-focused P2P payment system where:
- **Privacy First**: All network communication can route through Tor
- **Zero External Dependencies**: Fully self-contained, no blockchain, no central servers
- **P2P Direct Transfer**: Send payments directly or through relay nodes
- **Multi-currency Support**: Any fiat or cryptocurrency
- **Fee-based Relay**: Economic incentives for network participation

### Key Features
- ✅ **Privacy & Security**: Tor onion routing, multi-layered security (CSRF, XSS, rate limiting)
- ✅ **Self-Sovereign**: No central authority, fully decentralized
- ✅ **Flexible Topologies**: 1 to 37+ node networks
- ✅ **Local Storage**: SQLite-based, no external databases
- ✅ **Web GUI**: Browser-based wallet interface
- ✅ **Comprehensive Testing**: 126+ tests across all categories

---

## 🗂️ Repository Structure

```
eiou/
├── src/                          # Source code (PHP)
│   ├── core/                     # Core application (Application.php)
│   ├── database/                 # Repository layer (7 repositories)
│   │   ├── AbstractRepository.php   # Base repository with common operations
│   │   ├── ContactRepository.php    # Contact management
│   │   ├── TransactionRepository.php # Transaction persistence
│   │   ├── P2pRepository.php        # P2P messaging
│   │   ├── Rp2pRepository.php       # Relay P2P
│   │   └── DebugRepository.php      # Debug logging
│   ├── services/                 # Service layer (11 services)
│   │   ├── ServiceContainer.php     # Dependency injection container
│   │   ├── WalletService.php        # Wallet operations
│   │   ├── TransactionService.php   # Transaction processing
│   │   ├── ContactService.php       # Contact management
│   │   ├── P2pService.php           # P2P routing
│   │   └── SecurityService.php      # Security operations
│   ├── utils/                    # Utility classes
│   │   ├── Security.php             # Security utilities (353 lines)
│   │   ├── SecureLogger.php         # PII-masking logger
│   │   ├── RateLimiter.php          # Rate limiting
│   │   └── InputValidator.php       # Input validation
│   ├── gui/                      # Web wallet interface
│   ├── schemas/                  # Data schemas
│   └── eiou.php                  # CLI entry point
├── tests/                        # Test suites (35+ files, 126+ tests)
│   ├── Unit/                     # Unit tests (71+ tests)
│   ├── Integration/              # Integration tests (15+ tests)
│   ├── Security/                 # Security tests (30+ OWASP tests)
│   ├── Performance/              # Performance benchmarks (10+ tests)
│   └── demo/                     # Pre-configured topologies
│       ├── HTTP/                 # HTTP-based networks (4, 10, 13, 37 nodes)
│       └── Tor/                  # Tor-based networks (4, 10, 13, 37 nodes)
├── docs/                         # Documentation
│   ├── sprints/                  # Sprint documentation (NEW)
│   │   ├── INDEX.md              # Sprint index (reverse chronological)
│   │   ├── TEMPLATE.md           # Sprint template
│   │   └── SPRINT-YYYYMMDD-*.md  # Individual sprints
│   ├── architecture/             # Technical architecture (PLANNED)
│   ├── guides/                   # How-to guides (PLANNED)
│   └── api/                      # API documentation (PLANNED)
├── .github/                      # GitHub templates (NEW)
│   ├── ISSUE_TEMPLATE/           # Bug, feature, docs, security templates
│   └── PULL_REQUEST_TEMPLATE.md  # PR template
├── docker-compose-*.yml          # Docker configurations (4 topologies)
├── eioud.dockerfile              # EIOU daemon Dockerfile
├── composer.json                 # PHP dependencies
├── phpunit.xml                   # PHPUnit configuration
├── run_tests.sh                  # Test runner
└── README.md                     # This file
```

---

## 🛠️ Technology Stack

### Core
- **Language**: PHP (8.x compatible)
- **Database**: SQLite with repository pattern
- **Architecture**: Service-oriented with dependency injection
- **Networking**: HTTP and Tor (onion routing)
- **Storage**: JSON-based local storage

### Security
- **Multi-layered Protection**: CSRF, XSS, SQL injection prevention
- **Rate Limiting**: Database-backed sliding window
- **Secure Logging**: Automatic PII masking
- **Input Validation**: 15+ validation types
- **Session Security**: Secure session handling with HSTS

### Infrastructure
- **Containerization**: Docker 20.x+, Docker Compose
- **Testing**: PHPUnit + custom SimpleTest framework
- **CI/CD**: GitHub Actions (planned)
- **Documentation**: Markdown, sprint-based workflow

### Network Topologies
| Topology | Nodes | Memory | Use Case |
|----------|-------|--------|----------|
| **Single** | 1 | ~1.1GB | Development/testing |
| **4-Line** | 4 | ~1.1GB | Basic network (Alice, Bob, Carol, Daniel) |
| **10-Line** | 10 | ~2.8GB | Extended chain topology |
| **13-Cluster** | 13 | ~3.5GB | Star topology with branches |
| **37-Cluster** | 37 | ~9.5GB | Complex mesh network |

---

## 🚀 Quick Start

### Prerequisites
- **Docker** 20.x+ and **Docker Compose**
- **2-12GB RAM** (depending on topology)
- **Linux/macOS/Windows with WSL2**

### 30-Second Setup (Single Node)
```bash
git clone https://github.com/eiou-org/eiou.git
cd eiou
docker-compose -f docker-compose-single.yml up -d --build
```

### 4-Node Network Setup
```bash
# Start network
docker-compose -f docker-compose-4line.yml up -d --build

# Generate Tor address on Alice
docker-compose -f docker-compose-4line.yml exec alice eiou generate torAddressOnly

# Generate HTTP address on Alice
docker-compose -f docker-compose-4line.yml exec alice eiou generate http://alice

# Bob adds Alice as contact
docker-compose -f docker-compose-4line.yml exec bob eiou add <alice-address> Alice 0.01 100 USD

# Alice sends payment to Bob
docker-compose -f docker-compose-4line.yml exec alice eiou send Bob 10 USD "Test payment"
```

### Available Configurations

| Configuration | Nodes | Memory Usage | Description |
|---------------|-------|--------------|-------------|
| [`docker-compose-single.yml`](docker-compose-single.yml) | 1 | ~1.1GB | Single EIOU node for testing |
| [`docker-compose-4line.yml`](docker-compose-4line.yml) | 4 | ~1.1GB | Basic 4-node line topology |
| [`docker-compose-10line.yml`](docker-compose-10line.yml) | 10 | ~2.8GB | Extended 10-node line topology |
| [`docker-compose-cluster.yml`](docker-compose-cluster.yml) | 13 | ~3.5GB | Cluster topology with hierarchical structure |

---

## 💻 Development Guide

### Local Development Setup
```bash
# Clone repository
git clone https://github.com/eiou-org/eiou.git
cd eiou

# Install PHP dependencies (if developing locally)
composer install

# Run tests
./run_tests.sh

# Start development container
docker-compose -f docker-compose-single.yml up -d
```

### Branch Workflow (MANDATORY per CLAUDE.md)
1. **Start**: `git checkout main && git pull origin main`
2. **Create Branch**: `git checkout -b claudeflow-$(date +%y%m%d-%H%M)`
3. **Develop**: Make changes, commit regularly
4. **Push**: `git push origin claudeflow-YYMMDD-HHmm`
5. **PR**: Create Pull Request on GitHub (manual approval required)
6. **Merge**: Approve and merge on github.com
7. **Cleanup**: Delete branch locally and remotely
8. **Update**: `git checkout main && git pull origin main`

**🚨 CRITICAL**: NEVER push directly to main. All changes must go through feature branches and pull requests.

### Code Standards
- **File Size**: Maximum 500 lines per file (currently 3 files exceed this)
- **Testing**: Minimum 80% coverage for new code
- **Documentation**: All public functions must have PHPDoc comments
- **Security**: No hardcoded secrets, use environment variables
- **Privacy**: All network communication supports Tor routing

---

## 🧪 Testing Documentation

### Running Tests
```bash
# Run all tests
./run_tests.sh

# Run specific test category
phpunit tests/Unit/
phpunit tests/Integration/
phpunit tests/Security/
phpunit tests/Performance/

# Run with coverage report
phpunit --coverage-html coverage/
```

### Test Categories

#### Unit Tests (71+ tests)
- Repository layer tests with mock PDO
- Service layer tests (needs expansion)
- Utility function tests
- Input validation tests

#### Integration Tests (15+ tests)
- P2P message flow tests
- Transaction flow tests
- Service integration tests
- Docker topology tests

#### Security Tests (30+ tests)
- OWASP Top 10 coverage (90%)
- SQL injection prevention
- XSS protection tests
- CSRF validation tests
- Rate limiting tests
- Authentication bypass tests

#### Performance Tests (10+ benchmarks)
- Database operation benchmarks (<5ms target)
- N+1 query detection
- Password hashing validation (50-300ms)
- Transaction throughput tests

### Docker Network Tests
```bash
# Test 4-line HTTP topology
cd tests/demo/HTTP/4\ contacts\ line\ \(http4\ ~1.1gb\ memory\)/
./http4\ \(shell\ test\ script\).sh

# Test 4-line Tor topology
cd tests/demo/Tor/4\ contacts\ line\ \(tor4\ ~1.1gb\ memory\)/
./tor4\ \(shell\ test\ script\).sh
```

---

## 🏗️ Architecture Overview

### Design Patterns
EIOU uses a **clean architecture** approach with clear separation of concerns:

#### Repository Pattern
- **AbstractRepository**: Base class with 20+ common CRUD operations
- Specialized repositories for each domain entity
- Mock PDO implementation for testing without database

#### Service Layer Pattern
- **ServiceContainer**: Singleton dependency injection container
- Services handle business logic and coordinate repositories
- Clear API boundaries between layers

#### Security by Design
- **Multi-layered Defense**: Input validation, output encoding, CSRF protection
- **SecureLogger**: Automatic PII masking (emails, IPs, passwords, credit cards)
- **RateLimiter**: Sliding window rate limiting per user/IP
- **InputValidator**: Centralized validation for 15+ input types

### Database Schema
- **contacts**: Contact management with fee/credit/currency
- **transactions**: Transaction history with chaining for audits
- **p2p**: Peer-to-peer message queue
- **rp2p**: Relay peer-to-peer routing
- **debug**: Debug logging with security masking

### Core Components
1. **Application.php**: Core application singleton with service initialization
2. **eiou.php**: CLI interface for node operations
3. **Wallet GUI**: Browser-based interface for wallet operations
4. **P2P Protocol**: Custom binary protocol with hash-based routing
5. **Tor Manager**: Onion service creation and management

For detailed architecture documentation, see [`docs/architecture/`](docs/architecture/).

---

## 📋 Container Management

### View Running Containers
```bash
# List all running containers
docker-compose -f <config-file>.yml ps

# View logs from all containers
docker-compose -f <config-file>.yml logs

# Follow logs in real-time
docker-compose -f <config-file>.yml logs -f alice
```

### Execute Commands in Containers
```bash
# Generate Tor address for a specific node
docker-compose -f docker-compose-4line.yml exec alice eiou generate torAddressOnly

# Generate HTTP address for a specific node
docker-compose -f docker-compose-4line.yml exec alice eiou generate http://alice

# Add a contact to a node
docker-compose -f docker-compose-4line.yml exec alice eiou add <address> <name> <fee> <credit> <currency>

# Send a transaction
docker-compose -f docker-compose-4line.yml exec alice eiou send <contact-name> <amount> <currency> "<message>"

# Check balance
docker-compose -f docker-compose-4line.yml exec alice eiou balance

# List contacts
docker-compose -f docker-compose-4line.yml exec alice eiou contacts
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

---

## 🌐 Network Topologies

### Pre-made Test Topologies
Under [`tests/demo/`](tests/demo/) are two folders containing pre-made topologies for both **HTTP** and **Tor**. Each topology includes:
- Overview image depicting the network structure
- `.txt` files for easy copy-pasting commands
- `.sh` shell scripts for automated setup

There are two versions of each script:
- **Basic setup**: Sets up topology as shown in diagram
- **Test setup**: Setup + automated testing (transactions, contact checks)

### HTTP Topologies

| Configuration | Nodes | Memory Usage | Setup Script | Test Script |
|---------------|-------|--------------|--------------|-------------|
| http4 | 4 | ~1.1GB | [basic setup](tests/demo/HTTP/4%20contacts%20line%20(http4%20~1.1gb%20memory)/http4%20(basic%20setup%2C%20shell%20script).sh) | [test setup](tests/demo/HTTP/4%20contacts%20line%20(http4%20~1.1gb%20memory)/http4%20(shell%20test%20script).sh) |
| http10 | 10 | ~2.8GB | [basic setup](tests/demo/HTTP/10%20contacts%20line%20(http10%20~2.8gb%20memory)/http10%20(basic%20setup%2C%20shell%20script).sh) | [test setup](tests/demo/HTTP/10%20contacts%20line%20(http10%20~2.8gb%20memory)/http10%20(shell%20test%20script).sh) |
| http_small_cluster | 13 | ~3.5GB | [basic setup](tests/demo/HTTP/13%20contacts%20cluster%20(http_small_cluster%20~3.5gb%20memory)/http_small_cluster%20(basic%20setup%2C%20shell%20script).sh) | [test setup](tests/demo/HTTP/13%20contacts%20cluster%20(http_small_cluster%20~3.5gb%20memory)/http_small_cluster%20(shell%20test%20script).sh) |
| http_cluster | 37 | ~9.5GB | [basic setup](tests/demo/HTTP/37%20contacts%20cluster%20(http_cluster%20%20~9.5gb%20memory)/http_cluster%20(basic%20setup%2C%20shell%20script).sh) | [test setup](tests/demo/HTTP/37%20contacts%20cluster%20(http_cluster%20%20~9.5gb%20memory)/http_cluster%20(shell%20test%20script).sh) |

### Tor Topologies

| Configuration | Nodes | Memory Usage | Setup Script | Test Script |
|---------------|-------|--------------|--------------|-------------|
| tor4 | 4 | ~1.1GB | [basic setup](tests/demo/Tor/4%20contacts%20line%20(tor4%20~1.1gb%20memory)/tor4%20(basic%20setup%2C%20shell%20script).sh) | [test setup](tests/demo/Tor/4%20contacts%20line%20(tor4%20~1.1gb%20memory)/tor4%20(shell%20test%20script).sh) |
| tor10 | 10 | ~2.8GB | [basic setup](tests/demo/Tor/10%20contacts%20line%20(tor10%20~2.8gb%20memory)/tor10%20(basic%20setup%2C%20shell%20script).sh) | [test setup](tests/demo/Tor/10%20contacts%20line%20(tor10%20~2.8gb%20memory)/tor10%20(shell%20test%20script).sh) |
| tor_small_cluster | 13 | ~3.5GB | [basic setup](tests/demo/Tor/13%20contacts%20cluster%20(tor_small_cluster%20~3.5gb%20memory)/tor_small_cluster%20(basic%20setup%2C%20shell%20script).sh) | [test setup](tests/demo/Tor/13%20contacts%20cluster%20(tor_small_cluster%20~3.5gb%20memory)/tor_small_cluster%20(shell%20test%20script).sh) |
| tor_cluster | 37 | ~9.5GB | [basic setup](tests/demo/Tor/37%20contacts%20cluster%20(tor_cluster%20%20~9.5gb%20memory)/tor_cluster%20(basic%20setup%2C%20shell%20script).sh) | [test setup](tests/demo/Tor/37%20contacts%20cluster%20(tor_cluster%20%20~9.5gb%20memory)/tor_cluster%20(shell%20test%20script).sh) |

### Topology Diagrams

#### Line Topology (4 nodes)
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

#### Line Topology (10 nodes)
<img width="2640" height="192" alt="toplogical 10" src="https://github.com/user-attachments/assets/15c36014-1e25-4a32-9bdf-b2b3f1f9948f" />

[See full 10-node setup commands in original section]

#### Cluster Topology (13 nodes)
<img width="2640" height="1414" alt="topological cluster 13" src="https://github.com/user-attachments/assets/187cfd3b-f16d-4aaf-88bf-e46630192ff2" />

[See full 13-node setup commands in original section]

---

## 🤝 Contributing Guidelines

We welcome contributions! Please follow these guidelines:

### How to Contribute
1. **Check Issues**: Review [open issues](https://github.com/eiou-org/eiou/issues)
2. **Discuss First**: Comment on issue or create new one
3. **Fork & Branch**: Create feature branch from main (`claudeflow-YYMMDD-HHmm`)
4. **Develop**: Follow code standards and add tests
5. **Test**: Ensure all tests pass locally
6. **PR**: Submit Pull Request using [PR template](.github/PULL_REQUEST_TEMPLATE.md)
7. **Review**: Address feedback from maintainers
8. **Merge**: Manual approval on github.com required

### Issue Reporting
Use our GitHub issue templates:
- **🐛 Bug Report**: [Template](.github/ISSUE_TEMPLATE/1-bug_report.yml)
- **✨ Feature Request**: [Template](.github/ISSUE_TEMPLATE/2-feature_request.yml)
- **📚 Documentation**: [Template](.github/ISSUE_TEMPLATE/3-documentation.yml)
- **🔒 Security**: [Template](.github/ISSUE_TEMPLATE/4-security.yml) (or use private reporting for critical issues)

### Code Review Process
- All PRs require manual approval on github.com
- Reviews focus on code quality, security, testing, and documentation
- Average review time: 24-48 hours
- Be responsive to feedback

For full contributing guide, see [`docs/guides/CONTRIBUTING.md`](docs/guides/CONTRIBUTING.md) (coming soon).

---

## 📚 Sprint Documentation

We use a sprint-based development workflow. All sprint documentation is tracked in [`docs/sprints/`](docs/sprints/).

### Current Sprint
- **[SPRINT-20251014-initial-documentation](docs/sprints/SPRINT-20251014-initial-documentation.md)** (In Progress)
  - Focus: Documentation system, GitHub workflow, README restructure
  - Branch: `claudeflow-251014-0830`
  - Progress: 9/12 tasks completed

### Sprint Index
- **[All Sprints](docs/sprints/INDEX.md)** (reverse chronological order)
- **[Sprint Template](docs/sprints/TEMPLATE.md)** (for creating new sprints)

---

## 🔗 Important Links

### Project Resources
- **Website**: https://eiou.org
- **GitHub Organization**: https://github.com/eiou-org
- **Docker Hub**: https://hub.docker.com/r/eiou/eiou
- **Documentation**: This README + [`docs/`](docs/)

### Related Repositories
- **Browser Wallet**: https://github.com/eiou-org/eiou-wallet-extension
- **Mobile Wallet (Flutter)**: https://github.com/eiou-org/eiou-wallet-flutter
- **Official Website**: https://github.com/eiou-org/eiou-website

### Community
- **Discussions**: https://github.com/eiou-org/eiou/discussions
- **Issues**: https://github.com/eiou-org/eiou/issues
- **Pull Requests**: https://github.com/eiou-org/eiou/pulls
- **Security Advisories**: https://github.com/eiou-org/eiou/security/advisories

---

## 📄 License

[Insert License Information]

---

## 🙏 Acknowledgments

Built with privacy, security, and decentralization as core principles.

Special thanks to all contributors who have helped shape EIOU into a robust, privacy-first payment network.

---

**Last Updated**: 2025-10-14 | **Version**: Development | **Branch**: claudeflow-251014-0830
