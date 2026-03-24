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

Currency support requires two settings:

| Setting | Purpose | Example |
|---------|---------|---------|
| `allowedCurrencies` | List of currency codes users can transact in | `USD,EUR` |
| `displayDecimals` | Number of decimal places shown in the UI (0-8) | `4` (default) |

All currencies are stored internally at **8-decimal precision** (10^8 minor units per major unit), regardless of display settings. This eliminates conversion factor mismatches between nodes — every node stores the same integers for the same amounts.

The `displayDecimals` setting is a **global** value (0-8) that applies to all currencies equally. It controls:
- How many decimal places are shown in the UI (GUI display)
- Values are **truncated (floored)**, not rounded — displayed amounts never exceed the actual value

It does **NOT** affect:
- Input validation (always accepts up to 8 decimal places)
- Internal storage or wire format
- CLI and API output (always uses full 8-decimal precision)

Input validation always operates at the full internal precision (8 decimal places). An amount like 128.99999999 is accepted and stored exactly, regardless of display decimals. The minimum accepted amount is 0.00000001 (1 fractional unit) for all currencies.

### Precision Limits

| Limit | Value | Notes |
|-------|-------|-------|
| Internal precision | 8 decimals (10^8) | Fixed for all currencies |
| Display decimals | 0-8 (default 4) | Global setting, truncates (floors) |
| Max transaction amount | ~2.3 quintillion | PHP_INT_MAX / 4, enforced at input validation |
| Max credit limit | ~9.2 quintillion | PHP_INT_MAX, stored via split BIGINT columns |
| Minimum amount | 0.00000001 | 1 fractional unit at 8-decimal precision |

Amounts are stored as two BIGINT columns (`_whole` and `_frac`) via the `SplitAmount` value object. All input validation uses bcmath string operations (`bccomp`/`bcadd`) to preserve full precision for large values. All arithmetic (fee calculations, balance updates) uses bcmath internally — no PHP integer overflow is possible regardless of amount size.

---

## Adding a New Currency

### Via the Web GUI

1. Open Settings and expand **Advanced Settings**.
2. Select the **Currency** category from the dropdown.
3. Add the currency code to **Allowed Currencies** (one per line):
   ```
   USD
   EUR
   ```
4. Click **Save Settings**.

To change the display decimal places:
1. Select the **Display** category from the dropdown.
2. Choose the desired value (0-8) from the **Display Decimal Places** dropdown.
3. Click **Save Settings**.

### Via the CLI

```bash
# Add to allowed currencies (comma-separated)
docker exec eiou-node eiou changesettings allowedCurrencies USD,EUR

# Optionally change display decimal places (0-8, default 4)
docker exec eiou-node eiou changesettings displayDecimals 4

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
    "allowed_currencies": "USD,EUR",
    "display_decimals": 4
  }'
```

---

## Configuration Reference

### Display Decimals

The display decimals setting controls how many decimal places are shown in the UI for all currencies. It is a single global value (0-8, default 4).

Values are **truncated (floored)**, not rounded. This ensures displayed amounts never exceed the actual stored value. For example, with display decimals set to 2, an amount of 1.999 displays as 1.99, not 2.00.

| Display Decimals | Amount Stored | UI Shows | Internal Storage |
|-----------------|---------------|----------|------------------|
| 0 | 1234.56789012 | 1,234 | whole=1234, frac=56789012 |
| 2 | 1234.56789012 | 1,234.56 | whole=1234, frac=56789012 |
| 4 (default) | 1234.56789012 | 1,234.5678 | whole=1234, frac=56789012 |
| 8 | 1234.56789012 | 1,234.56789012 | whole=1234, frac=56789012 |

Input validation always accepts up to 8 decimal places regardless of the display setting.

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
docker exec eiou-node eiou changesettings allowedCurrencies USD,EUR
```

### Adding Bitcoin

```bash
docker exec eiou-node eiou changesettings allowedCurrencies USD,BTC
```

### Showing Full Precision (8 Decimal Places)

```bash
docker exec eiou-node eiou changesettings displayDecimals 8
```

### Showing Whole Numbers Only (0 Decimal Places)

```bash
docker exec eiou-node eiou changesettings displayDecimals 0
```

### Removing a Currency

Remove the currency from the allowed list. Existing transactions in that currency remain in the database but new transactions cannot be created.

```bash
docker exec eiou-node eiou changesettings allowedCurrencies USD
```
