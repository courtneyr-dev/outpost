# Polar Flow — fetch-recent consumer (G11c-consumer)

Registers Polar Flow as a fetch-recent provider per PR #57's primitive. Users connected to Polar (PR #50) can pick a recent training session from the composer sidebar.

This is the most complex of the three fetch-recent consumers because of Polar AccessLink's transaction model.

## Provider config

```php
[
    'label'          => 'Polar Flow',
    'callback'       => [ Outpost_Fetch_Recent_Polar::class, 'fetch_items' ],
    'capability'     => 'publish_posts',
    'oauth_provider' => 'polar',
]
```

## The transaction model

Polar's AccessLink API doesn't have a standard "list recent exercises" endpoint. Instead it uses a transaction model:

1. **Start transaction** — `POST /v3/users/{user-id}/exercise-transactions`. Returns a `transaction-id`.
2. **List exercise URLs** — `GET /v3/users/{user-id}/exercise-transactions/{transaction-id}`. Returns `{ "exercises": [<url>, <url>, ...] }`.
3. **Fetch each exercise** — `GET <exercise_url>`. Returns the exercise's full record (sport, distance, duration, start time, etc.).
4. **DON'T commit** — normally a consumer would `PUT` the transaction to commit it, after which Polar removes those exercises from the next transaction's window. The picker explicitly does NOT commit because the picker only reads.

### Why not commit?

If we committed, the user would see an exercise in their picker once and then never again — even if they didn't pick it. That's broken UX for a "browse recent activity" surface.

By leaving transactions uncommitted, exercises stay in the picker window. Polar keeps them visible for up to 24 hours after they appear in any transaction (the AccessLink staleness ceiling). After that, exercises drop off naturally even without an explicit commit.

The user-visible consequence: an exercise the user opened the picker for yesterday and DIDN'T pick will keep showing up today. That's acceptable — "I saw this last time too" is friendlier than "this option vanished because I happened to glance at it."

## Item shape mapping

```
title:    "Training — Running, 5.2 km"        (when distance > 0)
          "Training — Strength Training, 46 min" (when only duration)
          "Training — Yoga"                   (when neither)
subtitle: "2026-05-04"
post_kind: "workout"
post_meta:
  _outpost_polar_exercise_id: "ex-1"
  _outpost_polar_sport:       "Running"
  _outpost_polar_start_time:  "2026-05-04T07:30:00.000"
  _outpost_polar_distance_m:  "5200"
  _outpost_polar_duration:    "PT32M0S"
```

The sport name is normalized: Polar returns `RUNNING` / `STRENGTH_TRAINING`; the picker displays `Running` / `Strength Training` (underscores → spaces, then word-cased).

ISO 8601 duration parsing handles `PT45M30S`-style values into total minutes for the title.

## Roundtrip count

Per modal open: 1 POST (start transaction) + 1 GET (list URLs) + N GETs (one per exercise, capped at `MAX_EXERCISES_PER_FETCH = 25`). If the caller's `count` is less than 25, the per-exercise loop short-circuits at count.

Realistic worst case: ~12 HTTP roundtrips per modal open. Acceptable for a one-time picker action.

## After-token-exchange dependency

Polar requires the user be registered with the AccessLink app before any data API call works. PR #50's `after_token_exchange()` hook handles this during the OAuth connect flow. PR #50's `verify_connection()` also auto-retries registration if it failed silently.

The fetch-recent callback assumes the user is already registered. If registration silently failed and was never auto-retried, the POST `/exercise-transactions` will return 404. The picker surfaces that as an empty list — modal renders "No recent items available." User can re-trigger registration by hitting the OAuth verify endpoint via Settings.

## No icon URL

Polar's AccessLink doesn't expose generic activity icons. Default item renderer skips the icon space when `icon_url` is null.

## Test seam

`Outpost_Fetch_Recent_Polar::set_http_resolver_for_tests( callable )` swaps `wp_remote_request` for a closure receiving `(method, url, token, ?body)` and returning the parsed response array. The test seam tracks all HTTP methods invoked during a fetch — the test suite asserts no `PUT` is issued during the picker flow (i.e., transaction never gets committed).
