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
- How many decimal places are shown in the UI (CLI output, GUI display, API responses)
- It does **NOT** affect input validation, internal storage, or wire format

Input validation always operates at the full internal precision (8 decimal places). An amount like 128.99999999 is accepted and stored exactly, regardless of display decimals. The minimum accepted amount is 0.00000001 (1 fractional unit) for all currencies.

Both settings must be configured for a currency to work. The `allowedCurrencies` setting validates that each listed currency has display decimals defined.

### Precision Limits

| Limit | Value | Notes |
|-------|-------|-------|
| Internal precision | 8 decimals (10^8) | Fixed for all currencies |
| Max display decimals | 8 | Cannot exceed internal precision |
| Max transaction amount | ~2.3 quintillion | PHP_INT_MAX / 4, enforced at input validation |
| Max credit limit | ~9.2 quintillion | PHP_INT_MAX, stored via split BIGINT columns |
| Minimum amount | 0.00000001 | 1 fractional unit at 8-decimal precision |

Amounts are stored as two BIGINT columns (`_whole` and `_frac`) via the `SplitAmount` value object. All input validation uses bcmath string operations (`bccomp`/`bcadd`) to preserve full precision for large values. All arithmetic (fee calculations, balance updates) uses bcmath internally — no PHP integer overflow is possible regardless of amount size.

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

The display decimals setting controls how many decimal places are shown in the UI. It does **not** affect input validation — all currencies accept up to 8 decimal places regardless of display setting. Internally, all amounts are stored as two BIGINT columns (whole + fractional × 10^8).

| Currency | Display Decimals | UI Shows | Accepts Input Up To | Internal Storage |
|----------|-----------------|----------|---------------------|------------------|
| USD | 2 | $1,234.56 | 1234.56789012 (8 dp) | whole=1234, frac=56789012 |
| EUR | 2 | €1,234.56 | 1234.56789012 (8 dp) | whole=1234, frac=56789012 |
| JPY | 0 | ¥1,234 | 1234.56789012 (8 dp) | whole=1234, frac=56789012 |
| BTC | 8 | ₿0.00000001 | 0.00000001 (8 dp) | whole=0, frac=1 |

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
