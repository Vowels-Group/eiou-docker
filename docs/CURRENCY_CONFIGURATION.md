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
| `conversionFactors` | Maps currency codes to their minor-to-major unit factor | `{"USD": 100}` (100 cents per dollar) |
| `allowedCurrencies` | List of currency codes users can transact in | `USD,EUR` |

Decimal places are **automatically inferred** from the conversion factor: `decimals = log10(factor)`. For example, a factor of 100 means 2 decimal places (USD), 100000000 means 8 (BTC), and 1 means 0 (JPY). There is no separate decimals setting to configure.

The **minimum transaction amount** is also inferred: `1 / factor`. For USD (factor 100) the minimum is 0.01, for BTC (factor 100000000) it is 0.00000001. Amounts that round to zero at the currency's precision are rejected. This applies to transaction amounts and fee amounts; credit limits and minimum fees allow zero.

Both settings must be configured for a currency to work. The `allowedCurrencies` setting validates that each listed currency has a conversion factor defined.

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
4. Add the currency code to **Allowed Currencies** (one per line):
   ```
   USD
   EUR
   ```
5. Click **Save Settings**.

Set conversion factors first, then add the currency to the allowed list. The allowed currencies validator checks that a conversion factor exists.

### Via the CLI

```bash
# Step 1: Set conversion factors (JSON format)
docker exec eiou-node eiou changesettings conversionFactors '{"USD":100,"EUR":100}'

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
    "conversion_factors": "{\"USD\":100,\"EUR\":100}",
    "allowed_currencies": "USD,EUR"
  }'
```

---

## Configuration Reference

### Conversion Factors

The conversion factor is the number of minor units in one major unit. This determines how amounts are stored internally (as integers in minor units) and converted for display. Both decimal places and the minimum transaction amount are inferred from the factor: `decimals = log10(factor)`, `minimum = 1 / factor`.

| Currency | Factor | Decimals (inferred) | Minimum Amount (inferred) | Meaning |
|----------|--------|---------------------|---------------------------|---------|
| USD | 100 | 2 | 0.01 | 100 cents = 1 dollar |
| EUR | 100 | 2 | 0.01 | 100 cents = 1 euro |
| GBP | 100 | 2 | 0.01 | 100 pence = 1 pound |
| JPY | 1 | 0 | 1 | No minor unit (yen is the smallest unit) |
| BTC | 100000000 | 8 | 0.00000001 | 100,000,000 satoshis = 1 bitcoin |

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
docker exec eiou-node eiou changesettings allowedCurrencies USD,EUR
```

### Adding Bitcoin (Satoshi Precision)

```bash
docker exec eiou-node eiou changesettings conversionFactors '{"USD":100,"BTC":100000000}'
docker exec eiou-node eiou changesettings allowedCurrencies USD,BTC
```

### Adding Japanese Yen (No Decimal Places)

```bash
docker exec eiou-node eiou changesettings conversionFactors '{"USD":100,"JPY":1}'
docker exec eiou-node eiou changesettings allowedCurrencies USD,JPY
```

### Removing a Currency

Remove the currency from both settings. Existing transactions in that currency remain in the database but new transactions cannot be created.

```bash
docker exec eiou-node eiou changesettings conversionFactors '{"USD":100}'
docker exec eiou-node eiou changesettings allowedCurrencies USD
```
