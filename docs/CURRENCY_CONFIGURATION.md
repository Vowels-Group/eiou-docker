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

Currency support requires three settings that work together:

| Setting | Purpose | Example |
|---------|---------|---------|
| `conversionFactors` | Maps currency codes to their minor-to-major unit factor | `{"USD": 100}` (100 cents per dollar) |
| `currencyDecimals` | Maps currency codes to their display decimal places | `{"USD": 2}` (show 2 decimal places) |
| `allowedCurrencies` | List of currency codes users can transact in | `USD,EUR` |

All three must be configured for a currency to work. The `allowedCurrencies` setting validates that each listed currency has a conversion factor defined.

---

## Adding a New Currency

### Via the Web GUI

1. Open Settings and expand **Advanced Settings**.
2. Select the **Currency** category from the dropdown.
3. Add the currency to **Conversion Factors** (one per line, `CODE:FACTOR` format):
   ```
   USD:100
   EUR:100
   ```
4. Add the currency to **Currency Decimal Places** (one per line, `CODE:DECIMALS` format):
   ```
   USD:2
   EUR:2
   ```
5. Add the currency code to **Allowed Currencies** (one per line):
   ```
   USD
   EUR
   ```
6. Click **Save Settings**.

Set conversion factors and decimals first, then add the currency to the allowed list. The allowed currencies validator checks that a conversion factor exists.

### Via the CLI

```bash
# Step 1: Set conversion factors (JSON format)
docker exec eiou-node eiou changesettings conversionFactors '{"USD":100,"EUR":100}'

# Step 2: Set currency decimals (JSON format)
docker exec eiou-node eiou changesettings currencyDecimals '{"USD":2,"EUR":2}'

# Step 3: Add to allowed currencies (comma-separated)
docker exec eiou-node eiou changesettings allowedCurrencies USD,EUR

# Verify
docker exec eiou-node eiou viewsettings
```

### Via the API

```bash
# Update settings via the API
curl -X POST https://localhost/api/settings \
  -H "Authorization: Bearer <api-key>" \
  -H "Content-Type: application/json" \
  -d '{
    "conversionFactors": "{\"USD\":100,\"EUR\":100}",
    "currencyDecimals": "{\"USD\":2,\"EUR\":2}",
    "allowedCurrencies": "USD,EUR"
  }'
```

---

## Configuration Reference

### Conversion Factors

The conversion factor is the number of minor units in one major unit. This determines how amounts are stored internally (as integers in minor units) and converted for display.

| Currency | Factor | Meaning |
|----------|--------|---------|
| USD | 100 | 100 cents = 1 dollar |
| EUR | 100 | 100 cents = 1 euro |
| GBP | 100 | 100 pence = 1 pound |
| JPY | 1 | No minor unit (yen is the smallest unit) |
| BTC | 100000000 | 100,000,000 satoshis = 1 bitcoin |

### Currency Decimals

The number of decimal places used for input rounding and display formatting. The `round()` and `number_format()` functions both accept 0 as a valid value. Display examples below use PHP's default `number_format()` output, which uses `,` as the thousands separator and `.` as the decimal separator. Locale-aware formatting is not currently implemented.

| Currency | Decimals | Display Example |
|----------|----------|-----------------|
| USD | 2 | 10.50 |
| EUR | 2 | 10.50 |
| JPY | 0 | 1,500 |
| BTC | 8 | 0.00100000 |

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
docker exec eiou-node eiou changesettings conversionFactors '{"USD":100,"EUR":100}'
docker exec eiou-node eiou changesettings currencyDecimals '{"USD":2,"EUR":2}'
docker exec eiou-node eiou changesettings allowedCurrencies USD,EUR
```

### Adding Bitcoin (Satoshi Precision)

```bash
docker exec eiou-node eiou changesettings conversionFactors '{"USD":100,"BTC":100000000}'
docker exec eiou-node eiou changesettings currencyDecimals '{"USD":2,"BTC":8}'
docker exec eiou-node eiou changesettings allowedCurrencies USD,BTC
```

### Adding Japanese Yen (No Decimal Places)

```bash
docker exec eiou-node eiou changesettings conversionFactors '{"USD":100,"JPY":1}'
docker exec eiou-node eiou changesettings currencyDecimals '{"USD":2,"JPY":0}'
docker exec eiou-node eiou changesettings allowedCurrencies USD,JPY
```

### Removing a Currency

Remove the currency from all three settings. Existing transactions in that currency remain in the database but new transactions cannot be created.

```bash
docker exec eiou-node eiou changesettings conversionFactors '{"USD":100}'
docker exec eiou-node eiou changesettings currencyDecimals '{"USD":2}'
docker exec eiou-node eiou changesettings allowedCurrencies USD
```
