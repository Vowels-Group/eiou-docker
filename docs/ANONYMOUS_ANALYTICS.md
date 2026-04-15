# Anonymous Analytics

eIOU includes an optional, opt-in anonymous analytics system that sends aggregate usage statistics once per day. It is **disabled by default** and requires explicit user consent before any data is sent.

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
| **Once per day** | A single heartbeat is submitted every day at 3:00 AM UTC — there is no continuous tracking or real-time reporting |
| **Random jitter** | Each submission is delayed by a random 0–60 minute window to prevent timing correlation across nodes |
| **Opt-in only** | Analytics are disabled by default. No data is ever sent unless you explicitly enable it |
| **Consent boundary** | On opt-in, the current timestamp is recorded as `analyticsOptInAt`. No data from before that timestamp is ever included in a submission, even after an outage recovery |
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

### `usage_heartbeat` (sent daily)

```json
{
  "event": "usage_heartbeat",
  "analytics_id": "a1b2c3d4...",
  "version": "0.1.4-alpha",
  "timestamp": "2026-04-01T03:14:22Z",
  "period_days": 1,
  "metrics": {
    "tx_sent_count": 2,
    "tx_received_count": 1,
    "tx_p2p_count": 0,
    "contact_count": 5,
    "days_active": 1,
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

All counts are scoped to the `period_days` window so heartbeats never double-count across submissions. The normal daily heartbeat uses `period_days: 1`. After a multi-day submission gap (e.g. an outage, or a node that was offline), the next heartbeat widens its window to cover the whole gap since the last successful submission (or since `analyticsOptInAt`, whichever is later), so counts aren't lost. The window is clamped to a maximum of 365 days and is never allowed to reach back before the user's opt-in timestamp.

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

1. A cron job runs every day at 3:00 AM UTC
2. The script checks whether analytics are enabled — if not, it exits immediately
3. On the first run after an upgrade, if `analyticsEnabled` is true but `analyticsOptInAt` is missing (legacy opt-ins from before the consent-boundary feature existed), the current timestamp is stamped as `analyticsOptInAt` — no data is ever reported from before this stamp
4. A random jitter (0–60 minutes) prevents timing correlation
5. The heartbeat period is computed as the number of days since the later of `last_submitted` and `analyticsOptInAt`, clamped to `[1, 365]`. Normal daily cron → `period_days = 1`. After an outage or submission gap → `period_days` widens automatically to cover the gap
6. Aggregate metrics are computed from the local database for the `period_days` window
7. The payload is sent via HTTPS through the Tor SOCKS5 proxy to `analytics.eiou.org`

---

## Toggling Analytics On/Off

Analytics can be enabled or disabled at any time through three interfaces. Changes take effect immediately.

### GUI

Navigate to **Settings** → **Advanced Settings** → **Feature Toggles** → **Anonymous Analytics** and toggle the switch.

### CLI

From inside the container:

```bash
# Enable
eiou changesettings analyticsEnabled true

# Disable
eiou changesettings analyticsEnabled false
```

From the host:

```bash
# Enable
docker exec <container> eiou changesettings analyticsEnabled true

# Disable
docker exec <container> eiou changesettings analyticsEnabled false
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
      "last_submitted": null,
      "opt_in_at": null
    }
  }
}
```

| Field | Description |
|-------|-------------|
| `enabled` | Whether analytics are currently enabled |
| `consent_pending` | `true` if the user has not yet made a choice (enable or skip) |
| `last_submitted` | ISO 8601 timestamp of the last successful submission, or `null` |
| `opt_in_at` | ISO 8601 timestamp of the most recent off→on transition of `analyticsEnabled`, or `null` if analytics have never been enabled. Legacy nodes whose opt-in predates this field are backfilled on the first cron run after upgrade (stamped "now", not a fabricated past date). Bounds the heartbeat rollup window so no data from before consent is ever reported |

When `consent_pending` is `true`, the caller can prompt the user or enable analytics via `PUT /api/v1/system/settings`.

---

## Environment Variable Override

Set `EIOU_ANALYTICS_ENABLED` in your Docker Compose file or environment to override the config file setting at the container level:

```yaml
environment:
  EIOU_ANALYTICS_ENABLED: "true"   # or "false"
```

When set, this takes precedence over the GUI/CLI/API setting. Remove it to return control to the user-configurable setting.

Note: This environment variable also controls whether the daily cron job is installed at container startup. If set to `false` (or unset, which defaults to `false`), the cron entry is removed entirely.

---

## Technical Details

| Component | Detail |
|-----------|--------|
| Service | `AnalyticsService` (`files/src/services/AnalyticsService.php`) |
| Cron script | `files/scripts/analytics-cron.php` |
| Cron schedule | `0 3 * * *` (daily at 3:00 AM UTC) |
| Endpoint | `https://analytics.eiou.org/v1/report` |
| Proxy | `127.0.0.1:9050` (Tor SOCKS5, DNS resolved through Tor) |
| Anonymous ID | HMAC-SHA256 of public key hash, truncated to 32 hex chars |
| Config keys | `analyticsEnabled`, `analyticsConsentAsked`, `analyticsOptInAt` in `/etc/eiou/config/defaultconfig.json` |
| Cache file | `/etc/eiou/config/analytics-cache.json` (last submission timestamp) |
| Period computation | `clamp(days_since(max(last_submitted, analyticsOptInAt)), 1, 365)` — see `AnalyticsService::computePeriodDays()` |
| Connect timeout | 30 seconds |
| Request timeout | 60 seconds |
| Submission jitter | 0–3600 seconds (random) |
