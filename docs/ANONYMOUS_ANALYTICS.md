# Anonymous Analytics

eIOU includes an optional, opt-in anonymous analytics system that sends aggregate usage statistics once per week. It is **disabled by default** and requires explicit user consent before any data is sent.

## Table of Contents

1. [Privacy Guarantees](#privacy-guarantees)
2. [What Is Sent](#what-is-sent)
3. [What Is Never Sent](#what-is-never-sent)
4. [How It Works](#how-it-works)
5. [Toggling Analytics On/Off](#toggling-analytics-onoff)
6. [First-Login Consent Modal](#first-login-consent-modal)
7. [Startup Banner Notice](#startup-banner-notice)
8. [API Discoverability](#api-discoverability)
9. [Environment Variable Override](#environment-variable-override)
10. [Technical Details](#technical-details)

---

## Privacy Guarantees

| Guarantee | Detail |
|-----------|--------|
| **Tor-routed** | All submissions are sent through the local Tor SOCKS5 proxy — your IP address is never exposed to the analytics server |
| **Anonymous ID** | The node identifier is an HMAC-SHA256 hash that **cannot be reversed** to your public key, network address, or any other identity |
| **No personal data** | No individual transaction details, contacts, addresses, amounts, or counterparties are ever included |
| **Once per week** | A single heartbeat is submitted every Sunday at 3:00 AM UTC — there is no continuous tracking or real-time reporting |
| **Random jitter** | Each submission is delayed by a random 0–60 minute window to prevent timing correlation across nodes |
| **Opt-in only** | Analytics are disabled by default. No data is ever sent unless you explicitly enable it |
| **Revocable** | You can disable analytics at any time via GUI, CLI, or API. Disabling takes effect immediately |

---

## What Is Sent

There are two event types:

### `node_setup` (sent once when analytics is first enabled)

```json
{
  "event": "node_setup",
  "analytics_id": "a1b2c3d4...",
  "version": "0.1.4-alpha",
  "timestamp": "2026-04-01T12:00:00Z"
}
```

### `usage_heartbeat` (sent weekly)

```json
{
  "event": "usage_heartbeat",
  "analytics_id": "a1b2c3d4...",
  "version": "0.1.4-alpha",
  "timestamp": "2026-04-01T03:14:22Z",
  "period_days": 7,
  "metrics": {
    "tx_sent_count": 12,
    "tx_received_count": 8,
    "tx_p2p_count": 3,
    "contact_count": 5,
    "days_active": 4,
    "volume_by_currency": [
      {
        "currency": "USD",
        "sent_count": 10,
        "sent_whole": 500,
        "sent_frac": 0,
        "received_count": 6,
        "received_whole": 300,
        "received_frac": 50000000,
        "relay_count": 2,
        "relay_whole": 100,
        "relay_frac": 0
      }
    ]
  }
}
```

All counts are scoped to the 7-day period to prevent double-counting across weekly submissions.

---

## What Is Never Sent

- Individual transaction details (amounts, timestamps, parties)
- Contact names, addresses, or public keys
- Your node's IP address, hostname, or Tor hidden service address
- Your wallet seed phrase, private key, or auth code
- Message contents or delivery metadata
- Any data that could identify you or your counterparties

---

## How It Works

1. A cron job runs every Sunday at 3:00 AM UTC
2. The script checks whether analytics are enabled — if not, it exits immediately
3. A random jitter (0–60 minutes) prevents timing correlation
4. Aggregate metrics are computed from the local database for the past 7 days
5. The payload is sent via HTTPS through the Tor SOCKS5 proxy to `analytics.eiou.org`
6. The analytics server is a Cloudflare Worker + D1 database (source: `Vowels-Group/eiou-analytics`, private)

---

## Toggling Analytics On/Off

Analytics can be enabled or disabled at any time through three interfaces. Changes take effect immediately.

### GUI

Navigate to **Settings** → **Advanced Settings** → **Feature Toggles** → **Anonymous Analytics** and toggle the switch.

### CLI

```bash
# Enable
eiou changesettings analyticsEnabled true

# Disable
eiou changesettings analyticsEnabled false
```

### API

```bash
# Enable
curl -X PUT https://<node>/api/v1/system/settings \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"analytics_enabled": true}'

# Disable
curl -X PUT https://<node>/api/v1/system/settings \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"analytics_enabled": false}'
```

---

## First-Login Consent Modal

On first login after the node is set up (or after upgrading to a version that includes analytics), a one-time modal appears asking whether to enable anonymous analytics. The modal explains what is sent and how privacy is preserved.

- **Enable Analytics** — sets `analyticsEnabled` to `true` in the config
- **No Thanks** — leaves analytics disabled

Either choice sets `analyticsConsentAsked` to `true` so the modal never appears again. The preference can always be changed later through any of the methods above.

---

## Startup Banner Notice

For CLI and headless users who never interact with the GUI, a one-time notice is displayed inside the Open Alpha startup banner when the container starts. It shows the enable/disable commands for both CLI and API. The notice disappears once `analyticsConsentAsked` is set in the config (either via the GUI modal or by toggling the setting through CLI/API).

---

## API Discoverability

The `GET /api/v1/system/status` endpoint includes an `analytics` object so API consumers can programmatically detect the opt-in state:

```json
{
  "data": {
    "analytics": {
      "enabled": false,
      "consent_pending": true,
      "last_submitted": null
    }
  }
}
```

| Field | Description |
|-------|-------------|
| `enabled` | Whether analytics are currently enabled |
| `consent_pending` | `true` if the user has not yet made a choice (enable or skip) |
| `last_submitted` | ISO 8601 timestamp of the last successful submission, or `null` |

When `consent_pending` is `true`, the caller can prompt the user or enable analytics via `PUT /api/v1/system/settings`.

---

## Environment Variable Override

Set `EIOU_ANALYTICS_ENABLED` in your Docker Compose file or environment to override the config file setting at the container level:

```yaml
environment:
  EIOU_ANALYTICS_ENABLED: "true"   # or "false"
```

When set, this takes precedence over the GUI/CLI/API setting. Remove it to return control to the user-configurable setting.

Note: This environment variable also controls whether the weekly cron job is installed at container startup. If set to `false` (or unset, which defaults to `false`), the cron entry is removed entirely.

---

## Technical Details

| Component | Detail |
|-----------|--------|
| Service | `AnalyticsService` (`files/src/services/AnalyticsService.php`) |
| Cron script | `files/scripts/analytics-cron.php` |
| Cron schedule | `0 3 * * 0` (Sundays at 3:00 AM UTC) |
| Endpoint | `https://analytics.eiou.org/v1/report` |
| Proxy | `127.0.0.1:9050` (Tor SOCKS5, DNS resolved through Tor) |
| Anonymous ID | HMAC-SHA256 of public key hash, truncated to 32 hex chars |
| Config key | `analyticsEnabled` in `/etc/eiou/config/defaultconfig.json` |
| Consent flag | `analyticsConsentAsked` in `/etc/eiou/config/defaultconfig.json` |
| Cache file | `/etc/eiou/config/analytics-cache.json` (last submission timestamp) |
| Connect timeout | 30 seconds |
| Request timeout | 60 seconds |
| Submission jitter | 0–3600 seconds (random) |
