# WHOOP — fetch-recent consumer (G11b-consumer)

Registers WHOOP as a fetch-recent provider per PR #57's primitive. Users connected to WHOOP (PR #49) can pick a recent cycle (24-hour strain period) or recovery (morning recovery score) from the composer sidebar.

## Provider config

```php
[
    'label'          => 'WHOOP',
    'callback'       => [ Outpost_Fetch_Recent_Whoop::class, 'fetch_items' ],
    'capability'     => 'publish_posts',
    'oauth_provider' => 'whoop',
]
```

## Fetch behavior

| Aspect | Value |
|---|---|
| API base | `https://api.prod.whoop.com` |
| Cycles endpoint | `GET /developer/v2/cycle?start=<>&limit=25` |
| Recoveries endpoint | `GET /developer/v2/recovery?start=<>&limit=25` |
| Date range | last 14 days |
| Modal cap | 10 items per open (caller-supplied; clamped to [1, 50]) |
| Combined? | yes — cycles + recoveries merged, sorted descending by start time |

## Item shape mapping

### Cycles (24-hour strain period)

```
title:    "Cycle — Strain 14.7/21"   (or just "Cycle" when score absent)
subtitle: "2026-05-04"
post_kind: "note"
post_meta:
  _outpost_whoop_cycle_id: "cyc-1"
  _outpost_whoop_strain:   "14.7"
  _outpost_whoop_start_at: "2026-05-04T00:00:00+00:00"
```

### Recoveries (morning recovery score)

```
title:    "Recovery — 2026-05-04, 78%"
subtitle: "2026-05-04"
post_kind: "note"
post_meta:
  _outpost_whoop_recovery_score: "78"
  _outpost_whoop_cycle_id:       "cyc-99"
  _outpost_whoop_created_at:     "2026-05-04T07:30:00+00:00"
```

Both kinds → `note` post_kind. Cycles and recoveries are observational reflections, not active sessions.

## Score extraction

WHOOP's v2 API nests scores under a `score` object — `cycle.score.strain` and `recovery.score.recovery_score`. The mapper falls back to top-level `strain` / `recovery_score` fields for older API revisions.

## No icon URL

WHOOP's v2 API doesn't expose generic activity icons. The default item renderer (PR #57) skips the icon space when `icon_url` is null.

## No membership gate

Per PR #49's locked decisions, WHOOP doesn't gate API access on subscription tier. Failures (network, auth) surface as empty lists; the picker modal renders "No recent items available" gracefully.

## Test seam

`Outpost_Fetch_Recent_Whoop::set_http_resolver_for_tests( callable )` swaps `wp_remote_get` for a closure receiving `(url, token)` and returning the parsed response array.
