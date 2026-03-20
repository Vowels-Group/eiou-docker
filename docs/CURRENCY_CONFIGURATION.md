# Currency Configuration

eIOU supports multiple currencies. By default, only USD is configured. This guide explains how to add new currencies and how the configuration persists across container rebuilds.

## Table of Contents

1. [Overview](#overview)
2. [Adding a New Currency](#adding-a-new-currency)
3. [Configuration Reference](#configuration-reference)
4. [Persistence](#persistence)
5. [Examples](#examples)

---

## Overview

Currency support requires two settings that work together:

| Setting | Purpose | Example |
|---------|---------|---------|
| `displayDecimals` | Maps currency codes to their display decimal places | `{"USD": 2}` (show 2 decimals for dollars) |
| `allowedCurrencies` | List of currency codes users can transact in | `USD,EUR` |

All currencies are stored internally at **8-decimal precision** (10^8 minor units per major unit), regardless of display settings. This eliminates conversion factor mismatches between nodes — every node stores the same integers for the same amounts.

The `displayDecimals` setting controls:
- How many decimal places are shown in the UI
- Input validation (minimum amount, rounding)
- It does **NOT** affect internal storage or wire format

**Minimum amount** is inferred from display decimals: for USD (2 decimals) the minimum is 0.01, for BTC (8 decimals) it is 0.00000001. Amounts that round to zero at the display precision are rejected. This applies to transaction amounts and fee amounts; credit limits and minimum fees allow zero.

Both settings must be configured for a currency to work. The `allowedCurrencies` setting validates that each listed currency has display decimals defined.

### Precision Limits

| Limit | Value | Notes |
|-------|-------|-------|
| Internal precision | 8 decimals (10^8) | Fixed for all currencies |
| Max display decimals | 8 | Cannot exceed internal precision |
| Max amount | ~92 billion | PHP_INT_MAX / 10^8 |

All major-to-minor unit conversions use `bcmul()` (arbitrary-precision math) — amounts are exact to all 8 decimals at any size up to the 92 billion maximum.

---

## Adding a New Currency

### Via the Web GUI

1. Open Settings and expand **Advanced Settings**.
2. Select the **Currency** category from the dropdown.
3. Add the currency to **Display Decimals** (one per line, `CODE:DECIMALS` format):
   ```
   USD:2
   EUR:2
   ```
4. Add the currency code to **Allowed Currencies** (one per line):
   ```
   USD
   EUR
   ```
5. Click **Save Settings**.

Set display decimals first, then add the currency to the allowed list. The allowed currencies validator checks that display decimals are defined.

### Via the CLI

```bash
# Step 1: Set display decimals (JSON format)
docker exec eiou-node eiou changesettings displayDecimals '{"USD":2,"EUR":2}'

# Step 2: Add to allowed currencies (comma-separated)
docker exec eiou-node eiou changesettings allowedCurrencies USD,EUR

# Verify
docker exec eiou-node eiou viewsettings
```

### Via the API

```bash
# Update settings via the API
curl -X PUT https://localhost/api/v1/system/settings \
  -H "Authorization: Bearer <api-key>" \
  -H "Content-Type: application/json" \
  -d '{
    "display_decimals": "{\"USD\":2,\"EUR\":2}",
    "allowed_currencies": "USD,EUR"
  }'
```

---

## Configuration Reference

### Display Decimals

The display decimals setting controls how many decimal places are shown in the UI and accepted as input. Internally, all amounts are stored at 8-decimal precision (10^8 minor units per major unit).

| Currency | Display Decimals | Minimum Amount (inferred) | Internal Storage | Meaning |
|----------|-----------------|---------------------------|------------------|---------|
| USD | 2 | 0.01 | 10^8 per dollar | Show cents |
| EUR | 2 | 0.01 | 10^8 per euro | Show cents |
| GBP | 2 | 0.01 | 10^8 per pound | Show pence |
| JPY | 0 | 1 | 10^8 per yen | No sub-yen display |
| BTC | 8 | 0.00000001 | 10^8 per bitcoin | Show satoshis |

If display decimals are not defined for a currency, the default is 8 (full internal precision).

### Currency Code Format

Currency codes must be 3-9 uppercase alphanumeric characters (`A-Z`, `0-9`). Standard ISO 4217 codes (USD, EUR, GBP) are recommended for fiat currencies, but any valid code can be used for custom units of account.

---

## Persistence

Currency settings are stored in the node's configuration file (`/etc/eiou/config/defaultconfig.json`), which lives on a Docker volume. This means:

- Settings **persist across container restarts** (`docker compose restart`)
- Settings **persist across image rebuilds** (`docker compose up -d --build`)
- Settings are **lost only if volumes are deleted** (`docker compose down -v`)

The default values in the source code (`Constants.php`) are used only when no configuration has been saved. Once you configure currencies through the GUI, CLI, or API, the saved configuration takes precedence.

---

## Examples

### Adding Euro Support

```bash
docker exec eiou-node eiou changesettings displayDecimals '{"USD":2,"EUR":2}'
docker exec eiou-node eiou changesettings allowedCurrencies USD,EUR
```

### Adding Bitcoin (Satoshi Precision)

```bash
docker exec eiou-node eiou changesettings displayDecimals '{"USD":2,"BTC":8}'
docker exec eiou-node eiou changesettings allowedCurrencies USD,BTC
```

### Adding Japanese Yen (No Decimal Places)

```bash
docker exec eiou-node eiou changesettings displayDecimals '{"USD":2,"JPY":0}'
docker exec eiou-node eiou changesettings allowedCurrencies USD,JPY
```

### Removing a Currency

Remove the currency from both settings. Existing transactions in that currency remain in the database but new transactions cannot be created.

```bash
docker exec eiou-node eiou changesettings displayDecimals '{"USD":2}'
docker exec eiou-node eiou changesettings allowedCurrencies USD
```
