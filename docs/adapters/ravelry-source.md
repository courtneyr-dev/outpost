# Ravelry — URL-paste source (G14b-source)

Consumer of the OAuth provider shipped in PR #47. Users can paste pattern or project URLs into the composer; rich knit/crochet metadata is extracted via authenticated API calls.

## URL forms

- Pattern: `https://www.ravelry.com/patterns/library/<slug>` (apex `ravelry.com` also accepted)
- Project: `https://www.ravelry.com/projects/<username>/<slug>`

## Authentication

Reads from `Outpost_Credentials_Store::get('ravelry', $user_id)`. Sends `Authorization: Bearer <access_token>` against:

- Pattern resolution (slug → id): `GET https://api.ravelry.com/patterns/search.json?query=<slug>&page_size=1`
- Pattern fetch: `GET https://api.ravelry.com/patterns/<id>.json`
- Project fetch: `GET https://api.ravelry.com/projects/<username>/<slug>.json`

When credentials are missing the source returns `extracted: false, reason: 'not_connected'`.

## Privacy boundary

Ravelry projects can be marked `private: true` or `permission_to_view: false`. When either signal is set, the source returns `extracted: false, reason: 'private'` even when the OAuth token has access. Same protection pattern as RWG.

Patterns themselves are public on Ravelry — there's no analogous private flag. Pattern fetches don't carry a privacy gate.

## Canonical post_kind

Both patterns and projects map to `'note'`:

- Pattern → "I'm going to make this" (announcement / recommendation)
- Project → "I'm making" or "I made" (observation / record)

The composer's render layer can distinguish further via `post_meta` (`_outpost_ravelry_designer` for patterns, `_outpost_ravelry_status` for projects).

## Pattern metadata

When present, the canonical `content` includes a `<dl>` with these entries:

| Term | Source field |
|---|---|
| Gauge | `gauge × gauge_divisor`, optional `row_gauge`, optional `gauge_pattern` |
| Yardage | `yardage`–`yardage_max` (or single value when equal) |
| Needles / hooks | `pattern_needle_sizes[].name` joined with commas |
| Fiber | `packs[].yarn_name` joined with commas |

## Project metadata

| Term | Source field |
|---|---|
| Status | `status_name` (e.g., "in progress", "finished", "frogged") |
| Started | `started` |
| Completed | `completed` |

## Photo handling

The primary photo URL flows into `post_payload.featured_image_url`:

1. Photos with `marked_as_primary: true` win.
2. Otherwise, lowest `sort_order` wins.
3. URL preference: `medium2_url` (≈1000px) → `large_url` → `medium_url` → `small_url`.

When no photos exist, `post_payload.featured_image_url` is omitted (not set to null/empty).

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

1-hour transient under `outpost_ravelry_<kind>_<md5(url)>`. Successful fetches cache; failures don't.

## Q3/Q4 — scope verification still pending

Per `.overnight-questions.md`, Ravelry's developer docs at `https://www.ravelry.com/api` are login-gated. The OAuth provider's scope defaults (`offline + personal-data + library-read + projects-read + patterns-read`) shipped in G14b based on documented common scope strings.

This source class does NOT assume any data only-available behind extended scopes. It reads the standard pattern / project fields any authenticated read returns. If a future scope change breaks the response shape, the source's failure path (`auth_failed` or `parse_failed`) surfaces cleanly — no silent data gaps.

The override filter `outpost_oauth_provider_ravelry_scopes` remains the safety net per Q3.
