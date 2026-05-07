# Oura — fetch-recent consumer (G11a-consumer)

Registers Oura as a fetch-recent provider per PR #57's primitive. Users connected to Oura (PR #45) can pick a recent workout or sleep session from the composer sidebar.

## Provider config

Filter: `outpost_fetch_recent_providers`

```php
[
    'label'          => 'Oura',
    'callback'       => [ Outpost_Fetch_Recent_Oura::class, 'fetch_items' ],
    'capability'     => 'publish_posts',
    'oauth_provider' => 'oura',
]
```

When the user isn't connected to Oura the picker hides the "Add from Oura" button automatically — the picker primitive checks `oauth_provider` against `Outpost_Credentials_Store`.

## Fetch behavior

| Aspect | Value |
|---|---|
| API base | `https://api.ouraring.com/v2` |
| Workouts endpoint | `GET /usercollection/workout?start_date=<>&end_date=<>` |
| Sleep endpoint | `GET /usercollection/sleep?start_date=<>&end_date=<>` |
| Date range | last 14 days from request time |
| Modal cap | 10 items per open (caller-supplied; clamped to [1, 50]) |
| Combined? | yes — workouts + sleep merged into one list, sorted descending by start time |

## Item shape mapping

### Workouts

```
title:    "Workout — Running, 5.2 km"   (when distance > 0)
          "Workout — Strength training" (when distance is 0)
subtitle: "2026-05-04, 32 min, 478 kcal"
post_kind: "workout"
post_meta:
  _outpost_oura_activity:   "running"
  _outpost_oura_start_at:   "2026-05-04T07:30:00+00:00"
  _outpost_oura_duration_s: "1920"
  _outpost_oura_distance_m: "5200"
  _outpost_oura_calories:   "478"
```

### Sleep

```
title:    "Sleep — 2026-05-04"
subtitle: "7.2 hours, score: 82"
post_kind: "note"
post_meta:
  _outpost_oura_sleep_day:     "2026-05-04"
  _outpost_oura_sleep_seconds: "26040"
  _outpost_oura_sleep_score:   "82"
```

Sleep is `note` rather than `workout` because sleep is observational, not an active session.

## No icon URL

Oura's v2 API doesn't expose generic activity icons. The default item renderer (PR #57) skips the icon space when `icon_url` is null.

## Test seam

`Outpost_Fetch_Recent_Oura::set_http_resolver_for_tests( callable )` swaps out `wp_remote_get` for a closure that receives `(url, token)` and returns the parsed response array. Production calls `wp_remote_get` and parses status/body normally.

## Failure handling

When the Oura API returns any non-2xx status (or `wp_remote_get` returns a `WP_Error`), `api_get()` returns null. Both `fetch_workouts` and `fetch_sleep` then return empty arrays, and the picker modal renders "No recent items available."

This includes the membership-lapsed case: Oura returns 403 when a previously-connected account no longer has the active subscription required for v2 API access. The graceful empty-list outcome means the user sees a clean "no items" rather than an error toast.

## What this consumer does NOT do

- Outbound publish (no POST to Oura — read-only).
- Workout creation on Oura's behalf.
- Daily activity / readiness / SpO2 streams (out of v1 scope; could ship as additional fetch-recent kinds).
- Real-time webhook integration (Oura supports webhooks; not used here).
