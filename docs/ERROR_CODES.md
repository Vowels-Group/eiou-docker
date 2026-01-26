# EIOU Error Codes Reference

Complete reference of all error codes used in the EIOU system, with HTTP status mappings and troubleshooting guidance.

## Table of Contents

1. [Error Response Format](#error-response-format)
2. [General Errors](#general-errors)
3. [Authentication Errors](#authentication-errors)
4. [API Key Errors](#api-key-errors)
5. [Wallet Errors](#wallet-errors)
6. [Contact Errors](#contact-errors)
7. [Transaction Errors](#transaction-errors)
8. [Transport Errors](#transport-errors)
9. [Validation Errors](#validation-errors)
10. [File Errors](#file-errors)
11. [Backup Errors](#backup-errors)
12. [Command Errors](#command-errors)
13. [Connection Errors](#connection-errors)
14. [HTTP Status Code Reference](#http-status-code-reference)

---

## Error Response Format

### REST API Response

```json
{
    "success": false,
    "error": {
        "code": "ERROR_CODE",
        "message": "Human-readable error description"
    },
    "timestamp": "2026-01-24T12:00:00Z",
    "request_id": "req_abc123",
    "status_code": 400
}
```

### CLI JSON Response

```json
{
    "success": false,
    "error": {
        "code": "ERROR_CODE",
        "message": "Human-readable error description"
    },
    "command": "send",
    "timestamp": "2026-01-24T12:00:00Z"
}
```

---

## General Errors

| Code | HTTP | Title | Description | Troubleshooting |
|------|------|-------|-------------|-----------------|
| `GENERAL_ERROR` | 500 | General Error | An unspecified error occurred | Check server logs for details |
| `VALIDATION_ERROR` | 400 | Validation Error | Input validation failed | Review the error message for specific field requirements |
| `NOT_FOUND` | 404 | Not Found | Requested resource does not exist | Verify the resource ID or path is correct |
| `INTERNAL_ERROR` | 500 | Internal Server Error | Server-side processing failure | Contact support if the issue persists |
| `TIMEOUT` | 504 | Request Timeout | Operation timed out | Retry the request; check network connectivity |
| `UNKNOWN_ERROR` | 500 | Unknown Error | Unexpected error type | Check server logs; report to support |

---

## Authentication Errors

### General Authentication

| Code | HTTP | Title | Description | Troubleshooting |
|------|------|-------|-------------|-----------------|
| `AUTHENTICATION_ERROR` | 401 | Authentication Error | General authentication failure | Verify credentials are correct |
| `AUTH_REQUIRED` | 401 | Authentication Required | Request requires authentication | Include valid authentication headers |
| `AUTH_INVALID` | 401 | Invalid Credentials | Credentials are incorrect | Re-check API key and secret |
| `AUTH_EXPIRED` | 401 | Session Expired | Authentication session has expired | Re-authenticate with fresh credentials |
| `PERMISSION_DENIED` | 403 | Permission Denied | Insufficient permissions for action | Request appropriate permissions |
| `UNAUTHORIZED` | 401 | Unauthorized | Not authorized to perform action | Verify authentication headers |

### API Authentication Headers

| Code | HTTP | Title | Description | Troubleshooting |
|------|------|-------|-------------|-----------------|
| `AUTH_MISSING_HEADER` | 401 | Missing Authorization Header | Required auth header not provided | Add the missing header to request |
| `AUTH_INVALID_FORMAT` | 401 | Invalid Authorization Format | Auth header format is incorrect | Use format: `X-API-Key`, `X-API-Timestamp`, `X-API-Signature` |
| `AUTH_MISSING_KEY` | 401 | Missing API Key | `X-API-Key` header not provided | Include your API key ID |
| `AUTH_MISSING_TIMESTAMP` | 401 | Missing Timestamp | `X-API-Timestamp` header not provided | Include current Unix timestamp |
| `AUTH_MISSING_SIGNATURE` | 401 | Missing Signature | `X-API-Signature` header not provided | Include HMAC-SHA256 signature |

### Timestamp Errors

| Code | HTTP | Title | Description | Troubleshooting |
|------|------|-------|-------------|-----------------|
| `AUTH_INVALID_TIMESTAMP` | 401 | Invalid Timestamp | Timestamp is not a valid number | Use Unix timestamp in seconds |
| `AUTH_EXPIRED_TIMESTAMP` | 401 | Expired Timestamp | Timestamp is too old (>5 minutes) | Synchronize system clock; use current time |

### Signature Errors

| Code | HTTP | Title | Description | Troubleshooting |
|------|------|-------|-------------|-----------------|
| `AUTH_INVALID_SIGNATURE` | 401 | Invalid Signature | HMAC signature verification failed | Verify signature algorithm: `HMAC-SHA256(secret, METHOD\nPATH\nTIMESTAMP\nBODY)` |
| `AUTH_INVALID_SIGNATURE_FORMAT` | 401 | Invalid Signature Format | Signature format is incorrect | Use hex-encoded HMAC-SHA256 |
| `AUTH_INVALID_CREDENTIALS` | 401 | Invalid Credentials | Key or secret is wrong | Verify API key ID and secret |

---

## API Key Errors

| Code | HTTP | Title | Description | Troubleshooting |
|------|------|-------|-------------|-----------------|
| `AUTH_INVALID_KEY` | 401 | Invalid API Key | API key does not exist | Verify the key ID is correct |
| `AUTH_KEY_EXPIRED` | 401 | API Key Expired | API key has passed expiration date | Create a new API key |
| `AUTH_KEY_DISABLED` | 403 | API Key Disabled | API key has been disabled | Enable the key or use a different one |
| `AUTH_PERMISSION_DENIED` | 403 | Permission Denied | Key lacks required permission | Add permission or use a different key |
| `API_KEY_NOT_FOUND` | 404 | API Key Not Found | Key ID does not exist | List keys with `eiou apikey list` |
| `CREATE_FAILED` | 500 | Creation Failed | Failed to create API key | Check database connectivity |
| `LIST_FAILED` | 500 | List Failed | Failed to list API keys | Check database connectivity |
| `DISABLE_FAILED` | 500 | Disable Failed | Failed to disable API key | Verify key exists and retry |
| `ENABLE_FAILED` | 500 | Enable Failed | Failed to enable API key | Verify key exists and retry |

---

## Wallet Errors

| Code | HTTP | Title | Description | Troubleshooting |
|------|------|-------|-------------|-----------------|
| `WALLET_EXISTS` | 409 | Wallet Already Exists | A wallet already exists on this node | Use existing wallet or reset node |
| `WALLET_NOT_FOUND` | 404 | Wallet Not Found | No wallet exists on this node | Generate a wallet first: `eiou generate` |
| `INVALID_HOSTNAME` | 400 | Invalid Hostname | Hostname format is invalid | Use format: `http://hostname` or `https://hostname` |
| `SEED_RESTORE_FAILED` | 500 | Seed Restore Failed | Failed to restore wallet from seed | Verify 24-word seed phrase is correct |
| `INVALID_SEED_PHRASE` | 400 | Invalid Seed Phrase | Seed phrase is not valid | Use a valid BIP39 24-word phrase |
| `INVALID_WORD_COUNT` | 400 | Invalid Word Count | Seed phrase has wrong word count | Provide exactly 24 words |
| `INVALID_CHECKSUM` | 400 | Invalid Checksum | Seed phrase checksum failed | Verify seed words are spelled correctly |

---

## Contact Errors

| Code | HTTP | Title | Description | Troubleshooting |
|------|------|-------|-------------|-----------------|
| `CONTACT_NOT_FOUND` | 404 | Contact Not Found | Contact does not exist | Verify contact name or address |
| `CONTACT_EXISTS` | 409 | Contact Already Exists | Contact already in address book | Use existing contact or different address |
| `CONTACT_BLOCKED` | 403 | Contact Blocked | Contact has been blocked | Unblock contact first: `eiou unblock` |
| `CONTACT_REJECTED` | 403 | Contact Request Rejected | Contact request was rejected | Cannot add this contact |
| `CONTACT_CREATE_FAILED` | 500 | Contact Creation Failed | Failed to create contact | Check database and network connectivity |
| `SELF_CONTACT` | 400 | Cannot Add Self as Contact | Attempted to add own address | Cannot send to yourself |
| `ACCEPT_FAILED` | 500 | Accept Failed | Failed to accept contact request | Retry; check logs for details |
| `BLOCK_FAILED` | 500 | Block Failed | Failed to block contact | Verify contact exists |
| `UNBLOCK_FAILED` | 500 | Unblock Failed | Failed to unblock contact | Verify contact is currently blocked |
| `UNBLOCK_ADD_FAILED` | 500 | Unblock and Add Failed | Failed to unblock and re-add | Retry operation |
| `DELETE_FAILED` | 500 | Delete Failed | Failed to delete contact | Check if contact has pending transactions |
| `UPDATE_FAILED` | 500 | Update Failed | Failed to update contact | Verify contact exists and values are valid |
| `ADDRESS_UPDATE_FAILED` | 500 | Address Update Failed | Failed to update contact address | Check address format |
| `NO_CONTACTS` | 400 | No Contacts Available | No contacts found | Add contacts first: `eiou add` |
| `CONTACT_UNREACHABLE` | 503 | Contact Unreachable | Contact node is not responding | Check contact is online; try again later |

---

## Transaction Errors

| Code | HTTP | Title | Description | Troubleshooting |
|------|------|-------|-------------|-----------------|
| `TRANSACTION_FAILED` | 500 | Transaction Failed | Transaction processing failed | Check contact status and retry |
| `TRANSACTION_IN_PROGRESS` | 429 | Transaction In Progress | Another transaction is already processing | Wait for current transaction to complete |
| `INSUFFICIENT_FUNDS` | 403 | Insufficient Funds | Not enough balance for transaction | Check balance with `eiou viewbalances` |
| `INVALID_AMOUNT` | 400 | Invalid Amount | Transaction amount is invalid | Use positive numeric amount |
| `INVALID_CURRENCY` | 400 | Invalid Currency | Currency code is not valid | Use valid currency code (e.g., USD, EUR) |
| `INVALID_RECIPIENT` | 400 | Invalid Recipient | Recipient address is invalid | Verify recipient exists and address is correct |
| `SELF_SEND` | 400 | Cannot Send to Yourself | Attempted self-transaction | Specify a different recipient |
| `CHAIN_INTEGRITY_FAILED` | 500 | Chain Integrity Failed | Transaction chain verification failed | Contact is corrupted; may need resync |

---

## Transport Errors

| Code | HTTP | Title | Description | Troubleshooting |
|------|------|-------|-------------|-----------------|
| `NO_VIABLE_TRANSPORT` | 503 | No Viable Transport | No working transport method found | Check HTTP/HTTPS/Tor connectivity |
| `NO_VIABLE_ROUTE` | 503 | No Viable Route | P2P routing could not find path | Increase `maxP2pLevel` or add more contacts |
| `P2P_CANCELLED` | 503 | P2P Route Cancelled | P2P transaction was cancelled | Route expired; retry the transaction |

---

## Validation Errors

| Code | HTTP | Title | Description | Troubleshooting |
|------|------|-------|-------------|-----------------|
| `INVALID_ADDRESS` | 400 | Invalid Address | Address format is invalid | Use HTTP, HTTPS, or Tor address format |
| `INVALID_NAME` | 400 | Invalid Name | Contact name is invalid | Use alphanumeric characters |
| `INVALID_FEE` | 400 | Invalid Fee | Fee value is invalid | Use positive percentage (e.g., 1.0) |
| `INVALID_CREDIT` | 400 | Invalid Credit | Credit limit is invalid | Use positive number |
| `INVALID_PARAMS` | 400 | Invalid Parameters | Request parameters are invalid | Check parameter types and values |
| `INVALID_FIELD` | 400 | Invalid Field | Field name or value is invalid | Check allowed fields |
| `INVALID_PERMISSION` | 400 | Invalid Permission | Permission string is invalid | Use valid permission format |
| `INVALID_SETTING` | 400 | Invalid Setting | Setting name is not recognized | Use `eiou changesettings` to see available settings |
| `INVALID_SYNC_TYPE` | 400 | Invalid Sync Type | Sync type is not valid | Use: `contacts`, `transactions`, or `balances` |
| `INVALID_ARGUMENT` | 400 | Invalid Argument | Command argument is invalid | Check command syntax with `eiou help <command>` |
| `MISSING_ARGUMENT` | 400 | Missing Argument | Required argument not provided | Provide all required arguments |
| `MISSING_PARAMS` | 400 | Missing Parameters | Required parameters missing | Include all required fields |
| `MISSING_IDENTIFIER` | 400 | Missing Identifier | Contact identifier not provided | Provide contact name or address |
| `MISSING_ADDRESS` | 400 | Missing Address | Address not provided | Include recipient address |
| `NO_ADDRESS` | 500 | No Address Available | Contact has no valid address | Contact record is incomplete |

---

## File Errors

| Code | HTTP | Title | Description | Troubleshooting |
|------|------|-------|-------------|-----------------|
| `FILE_NOT_FOUND` | 404 | File Not Found | Specified file does not exist | Verify file path is correct |
| `FILE_NOT_READABLE` | 403 | File Not Readable | Cannot read the file | Check file permissions |

---

## Backup Errors

| Code | HTTP | Title | Description | Troubleshooting |
|------|------|-------|-------------|-----------------|
| `BACKUP_FAILED` | 500 | Backup Failed | Database backup operation failed | Check database connectivity and disk space |
| `BACKUP_NOT_FOUND` | 404 | Backup Not Found | Specified backup file does not exist | Run `eiou backup list` to see available backups |
| `BACKUP_INVALID` | 400 | Invalid Backup | Backup file is corrupted or invalid format | Verify backup with `eiou backup verify <file>` |
| `BACKUP_DECRYPT_FAILED` | 500 | Decryption Failed | Cannot decrypt backup file | Ensure wallet is restored with correct seed phrase |
| `RESTORE_FAILED` | 500 | Restore Failed | Database restore operation failed | Check database connectivity; verify backup integrity |
| `RESTORE_CONFIRM_REQUIRED` | 400 | Confirmation Required | Restore requires --confirm flag | Add `--confirm` flag to acknowledge data overwrite |
| `DB_CONFIG_NOT_FOUND` | 500 | Database Config Not Found | Database configuration file missing | Ensure `/etc/eiou/dbconfig.json` exists |
| `MYSQLDUMP_FAILED` | 500 | MySQL Dump Failed | mysqldump command failed | Check MariaDB service is running |

---

## Command Errors

| Code | HTTP | Title | Description | Troubleshooting |
|------|------|-------|-------------|-----------------|
| `COMMAND_NOT_FOUND` | 404 | Command Not Found | CLI command does not exist | Run `eiou help` for available commands |
| `INTERACTIVE_NOT_SUPPORTED` | 400 | Interactive Mode Not Supported | Interactive mode not available with `--json` | Provide all arguments on command line |

---

## Connection Errors

| Code | HTTP | Title | Description | Troubleshooting |
|------|------|-------|-------------|-----------------|
| `CONNECTION_FAILED` | 500 | Connection Failed | Failed to connect to remote service | Check network connectivity |
| `NETWORK_ERROR` | 500 | Network Error | Network communication error | Verify network configuration |

---

## Rate Limiting

| Code | HTTP | Title | Description | Troubleshooting |
|------|------|-------|-------------|-----------------|
| `RATE_LIMIT_EXCEEDED` | 429 | Rate Limit Exceeded | Too many requests | Wait for `retry-after` time; reduce request frequency |

---

## HTTP Status Code Reference

### Success (2xx)

| Code | Meaning |
|------|---------|
| `200` | OK - Request succeeded |
| `201` | Created - Resource created successfully |

### Client Errors (4xx)

| Code | Meaning | Common Causes |
|------|---------|---------------|
| `400` | Bad Request | Validation errors, invalid input, missing parameters |
| `401` | Unauthorized | Missing or invalid authentication |
| `403` | Forbidden | Permission denied, blocked contact, insufficient funds |
| `404` | Not Found | Resource doesn't exist |
| `409` | Conflict | Resource already exists |
| `429` | Too Many Requests | Rate limit exceeded |

### Server Errors (5xx)

| Code | Meaning | Common Causes |
|------|---------|---------------|
| `500` | Internal Server Error | Server-side failures, database errors |
| `503` | Service Unavailable | Contact unreachable, no viable transport |
| `504` | Gateway Timeout | Operation timed out |

---

## See Also

- [API Reference](API_REFERENCE.md) - Complete REST API documentation
- [CLI Reference](CLI_REFERENCE.md) - Command-line interface documentation
- [API Quick Reference](API_QUICK_REFERENCE.md) - API endpoint summary
