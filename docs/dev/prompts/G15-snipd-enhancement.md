---
title: "G15 — Snipd enhancement"
branch: phase-g/g15-snipd-enhancement
base: phase-g/g4-adapter-primitives
depends: [G4]
phase: G
status: ready-for-implementation
---

# G15 — Snipd enhancement

## Scope

Refactor the existing Phase F Snipd adapter to use the G4 composite primitive for Apple Podcasts cross-lookup, add OG fallback when the Snipd API fails or rate-limits, and remap snip-level deep-link content to the IndieWeb `quote` Post Kind. Defer multi-clip stitching and full structured-blocks Apple Podcasts art replacement to Phase H.

## Files to create or modify

Modify:

- `outpost/includes/adapters/class-snipd-adapter.php` — refactor to use `Composite_Inbound`, add OG fallback, remap to `quote` Post Kind
- `outpost/tests/integration/adapters/test-snipd-adapter.php` — update mocks, add new test cases
- `outpost/docs/adapters/snipd.md` — update with new behavior and deferred-features note

Create:

- `outpost/includes/adapters/sources/class-snipd-api-source.php` — wraps existing Snipd API call as a Composite source
- `outpost/includes/adapters/sources/class-itunes-podcast-lookup-source.php` — Apple Podcasts cross-lookup source (reusable; G15 is its first consumer)

## Design decisions locked

1. **Source list shape for Snipd:**
   ```php
   [
       ['id' => 'snipd_api', 'role' => 'primary', 'callback' => [Snipd_Api_Source::class, 'fetch']],
       ['id' => 'og',        'role' => 'fallback', 'callback' => [Og_Inbound::class, 'fetch']],
       ['id' => 'itunes',    'role' => 'enrich',   'callback' => [Itunes_Podcast_Lookup_Source::class, 'fetch']],
   ]
   ```
   Merge strategy: `deep_merge`. Snipd API result is the base; iTunes Lookup adds `apple_podcasts_url`, `high_res_artwork_url`, `apple_show_id`. OG fallback only runs if Snipd API returns `WP_Error`.
2. **Snip → Post Kind mapping:**
   - URL pattern `snipd.com/snip/{id}` (a single snip with timestamp + AI summary) → Post Kind **`quote`** with the snip's transcript as the quote body and the timestamped link as `cite`.
   - URL pattern `snipd.com/episode/{id}` (a podcast episode without snip) → Post Kind **`listen`** (existing F-phase behavior).
   - URL pattern `snipd.com/show/{id}` (a podcast show overview) → Post Kind **`bookmark`**.
3. **OG fallback trigger conditions:**
   - Snipd API returns 401, 403, 429, 5xx, or times out: fallback runs.
   - Snipd API returns 404: do NOT run fallback (the snip genuinely doesn't exist; surface the error).
   - Snipd API returns success but with empty/sparse data: do NOT run fallback (don't second-guess the API).
4. **iTunes Lookup is enrich-only.** Its failure never blocks the response. Its success adds high-resolution artwork and the canonical Apple Podcasts URL.
5. **iTunes Lookup query strategy:**
   - First: search by show name + first episode title (Snipd API gives both).
   - If no exact match: search by show name only and pick the result with the closest follower-count signal (iTunes returns this).
   - If still no match: skip enrichment silently.
   - Cache iTunes show ID → artwork URL mapping for 30 days (artwork rarely changes).
6. **Apple Podcasts URL resolution:**
   - If iTunes Lookup returns a `collectionViewUrl`, use it.
   - Otherwise construct `https://podcasts.apple.com/podcast/id{collectionId}` from the numeric ID.
7. **Multi-clip stitching deferred.** A user with three snips from one episode currently posts three separate quotes. Phase H will offer "compose all snips from this episode into one post" UX. Add a TODO comment in the adapter referencing Phase H ticket (placeholder issue number; create an actual issue if Claude Code has gh CLI access and can open one).
8. **Backward compatibility:** existing Snipd adapter public method signatures preserved. Existing F-phase tests continue to pass after refactor.

## Implementation outline

- Refactor `Snipd_Adapter::fetch_metadata()` from direct API call to `Composite_Inbound::fetch( $url, $sources )`.
- Add URL pattern detection method `Snipd_Adapter::detect_url_kind( $url ): string` returning `'snip' | 'episode' | 'show'`.
- Add Post Kind suggestion method `Snipd_Adapter::suggest_post_kind( $url, $metadata ): string` returning the kind slug.
- Implement `Snipd_Api_Source` and `Itunes_Podcast_Lookup_Source` as standalone source callbacks (not adapters themselves; they're plumbing).
- Update Post Format suggestion logic to favor `quote` format when Post Kind is `quote`.

## Tests

### Unit / integration coverage required

- **Composite primitive use:**
  - Snipd API success → composite returns merged result with iTunes enrichment.
  - Snipd API 429 → OG fallback runs and returns OG-derived metadata.
  - Snipd API 404 → no fallback; `WP_Error` surfaced.
  - iTunes Lookup fails → primary result returned without enrichment; debug log present.
- **URL kind detection:**
  - `snipd.com/snip/abc123` → `'snip'` → Post Kind `quote`.
  - `snipd.com/episode/xyz` → `'episode'` → Post Kind `listen`.
  - `snipd.com/show/qwe` → `'show'` → Post Kind `bookmark`.
- **iTunes Lookup query strategy:**
  - Exact show + episode match: returns expected result.
  - Show-only match with multiple results: closest follower-count match selected.
  - No match: silent skip; metadata returned without enrichment fields.
  - Cached lookup: second identical query does not fire HTTP.
- **Backward compatibility:**
  - All existing F-phase Snipd tests pass.

### wp-env stub pickup

- `test_snipd_falls_back_to_og_on_rate_limit`
- `test_snipd_quote_post_kind_for_snip_urls`

## Acceptance criteria

- [ ] Snipd adapter refactored to use Composite_Inbound primitive from G4.
- [ ] OG fallback triggers on documented failure conditions only.
- [ ] iTunes Lookup enrichment merges artwork and Apple Podcasts URL.
- [ ] Snip URLs map to `quote` Post Kind; episode and show URLs unchanged.
- [ ] All existing F-phase Snipd tests pass (regression check).
- [ ] New tests cover the four composite scenarios + URL kind detection + iTunes strategy.
- [ ] §5 audit lint passes.
- [ ] Docs page updated with new behavior, fallback conditions, deferred-features note.
- [ ] No forbidden words.

## PR description template

```
### Phase G — G15 — Snipd enhancement

Refactors the existing Phase F Snipd adapter to use the G4 composite primitive. Adds OG fallback on Snipd API failure and Apple Podcasts cross-lookup for richer artwork. Remaps single-snip URLs to the IndieWeb `quote` Post Kind.

### Stacked PR

This PR is stacked on PR #X (G4 — Adapter primitives v1). **Merge G4 first.** After G4 merges, retarget this PR to `main` per FY decision #29 BEFORE deleting the G4 branch. Do not delete G4's branch until this PR is retargeted.

### Catalog reference

Phase G catalog §8 (Snipd enhancement notes) and §11, G15 entry. Detailed prompt: `outpost/docs/dev/prompts/G15-snipd-enhancement.md`.

### Deferred to Phase H

- Multi-clip stitching (compose multiple snips from one episode into one post).
- Full structured-blocks Apple Podcasts art replacement.

### Test plan

- 12+ new tests; full F-phase Snipd test suite still passes.
- 2 wp-env stubs picked up.

### Merge order

After G4 merges. Independent of all other Phase G PRs.
```

## Open items

None. All decisions locked.

If during implementation Claude Code discovers Snipd's URL patterns differ from the three documented above (e.g., a fourth pattern like `snipd.com/playlist/{id}`), it should:

1. Note the discovery in `.overnight-questions.md`.
2. Default behavior: treat unknown patterns as `bookmark` Post Kind.
3. Continue implementation with the three documented patterns plus the bookmark default.
