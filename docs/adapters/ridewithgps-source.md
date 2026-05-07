# Ride With GPS — URL-paste source (G12a-source)

Consumer of the OAuth provider shipped in PR #46. When a user shares an RWG trip or route URL into the composer, this source class fetches the underlying object via authenticated API calls and produces the canonical extracted shape.

## URL forms

- Trip: `https://ridewithgps.com/trips/<id>` (with or without `www.` and optional `.json` suffix)
- Route: `https://ridewithgps.com/routes/<id>`

Anything else on the host returns `null` from `Outpost_Source_Rwg::matches_url()` — the registry falls back to `Source_Unknown` for non-trip / non-route paths under the same domain.

## Authentication

Reads from `Outpost_Credentials_Store::get('ridewithgps', $user_id)`. Sends `Authorization: Bearer <access_token>` against:

- Trip: `GET https://ridewithgps.com/api/v1/trips/<id>.json`
- Route: `GET https://ridewithgps.com/api/v1/routes/<id>.json`

When credentials are missing the source returns `extracted: false, reason: 'not_connected'`. The composer renders a "Connect Ride With GPS first" prompt.

## Privacy boundary

RWG's trip / route objects carry a `visibility` field. Public values (`everyone`, `public_search`, `public`) flow through to the canonical shape. Private values (`private`, `friends`) refuse — the source returns `extracted: false, reason: 'private'` even when the OAuth token has access.

This protects against accidental disclosure: a user can have private rides in their own RWG account that they never intended to publish to their personal blog. The source enforces "if RWG itself doesn't show this publicly, neither do we."

## Canonical post_kind

| URL family | post_kind | Reasoning |
|---|---|---|
| Trip | `workout` | An actual ridden activity. |
| Route | `note` | A planned ride — not yet completed. |

## Distance / elevation units

RWG returns metric (meters, m elevation gain). The source preserves metric as the source-of-truth and computes imperial alongside:

- `distance_meters`, `distance_km` (rounded to 1 dp), `distance_miles` (rounded to 2 dp)
- `elevation_meters`, `elevation_feet` (rounded to whole feet)

The composer's render layer chooses which to display based on the user's site preferences.

## Failure shape

```php
[ 'extracted' => false, 'reason' => 'invalid_url' ]
[ 'extracted' => false, 'reason' => 'not_connected' ]
[ 'extracted' => false, 'reason' => 'private' ]
[ 'extracted' => false, 'reason' => 'auth_failed' ]
[ 'extracted' => false, 'reason' => 'not_found' ]
[ 'extracted' => false, 'reason' => 'transport_failed' ]
[ 'extracted' => false, 'reason' => 'parse_failed' ]
```

## Caching

1-hour transient under `outpost_rwg_<kind>_<id>`. Successful fetches cache; failures don't (so a private trip becoming public, or auth being reconnected, surfaces immediately).

## What this source does NOT do

- Outbound publish (no POST to RWG to create trips on the user's behalf).
- Strava/Garmin cross-syndication (out of scope; could ship as a Companion adapter).
- GPX file parsing (would require route geometry — not surfaced via API in v1 scope).
- Pretty-printing route geometry (no map rendering inside the composer).
