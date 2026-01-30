# Changelog

All notable changes to the EIOU Docker project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Comprehensive codebase audit report (`docs/CODEBASE_AUDIT_REPORT.md`)
- CHANGELOG.md for tracking version history
- CONTRIBUTING.md with contribution guidelines
- SECURITY.md with security policy and vulnerability reporting

### Fixed
- Section 6 negative financial test updated for async transaction validation flow
- Section 7 ContactService exception test using correct ServiceContainer getter methods
- Section 8 BackupService exception test using correct ServiceContainer getter methods

## [1.0.0] - 2025-XX-XX

### Added
- Initial Docker container for EIOU wallet node
- CLI interface for wallet management
- Web GUI for wallet operations
- P2P transaction routing
- Contact management system
- Transaction history and balance tracking
- Backup and restore functionality
- API key management for external integrations
- Multi-transport support (HTTP, HTTPS, Tor)
- Secure seedphrase display (TTY/temp file)
- SSL certificate auto-generation
- Auto-backup scheduling
- Auto-refresh for pending transactions

### Security
- CSRF protection on all forms
- Session management with secure cookies
- Input validation on all user inputs
- Shell command escaping
- Constant-time comparison for sensitive data
- Secure temp file handling in /dev/shm

---

## Version History Format

Each version entry should include:

### Added
New features and functionality

### Changed
Changes to existing functionality

### Deprecated
Features that will be removed in future versions

### Removed
Features that have been removed

### Fixed
Bug fixes

### Security
Security-related changes and fixes
