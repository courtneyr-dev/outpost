---
title: "G15a — Snipd deep-link + OG fallback (foundation-free split)"
branch: phase-g/g15a-snipd-quick
base: main
depends: []
phase: G
status: ready-for-implementation
supersedes: null
splits-from: G15
---

# G15a — Snipd deep-link + OG fallback (foundation-free)

## Why this exists

The original G15 prompt assumed the G4 composite primitive would be merged before G15 ran. That merge is pending Courtney's review of PR #35. This split lets §8 items 1 and 2 from the catalog ship now, without depending on Composite_Inbound landing first. §8 items 3-6 (composite enrichment, Apple Podcasts cross-lookup, multi-clip stitching) become G15b later.

## Scope

Two changes to the existing F-phase Snipd source/adapter:

1. **URL pattern detection.** Recognize `snipd.com/snip/{id}`, `snipd.com/episode/{id}`, `snipd.com/show/{id}` and map each to an appropriate Post Kind suggestion.
2. **OG fallback when Snipd API fails.** Add a sequential try-Snipd-then-OG fallback chain implemented inline (no Composite_Inbound dependency).

Defer to G15b: composite-driven Apple Podcasts cross-lookup, multi-clip stitching, structured-blocks artwork replacement.

## Files to modify

- The existing F-phase Snipd source class — find it via `grep -ri 'snipd' includes/sources/ includes/adapters/`. Match the existing class name pattern (likely `Outpost_Source_Snipd` or similar).
- The matching test file under `tests/`.
- The Snipd docs page under `docs/adapters/` if it exists; create if not.

## Design decisions locked

1. **URL kind detection method:**
   - `snipd.com/snip/{id}` → Post Kind `quote`. The snip's transcript text becomes the quote body; the timestamped link is the cite.
   - `snipd.com/episode/{id}` → Post Kind `listen` (existing F-phase behavior; do not change).
   - `snipd.com/show/{id}` → Post Kind `bookmark`.
   - Unknown patterns under `snipd.com/*` → Post Kind `bookmark` (safe default, do not throw).
2. **OG fallback trigger conditions:**
   - Snipd API returns HTTP 401, 403, 429, 5xx, or times out → fallback runs.
   - Snipd API returns HTTP 404 → do NOT fall back (the snip genuinely doesn't exist; surface the error).
   - Snipd API returns success but with empty payload → do NOT fall back (don't second-guess the API).
3. **OG fallback implementation:** inline use of the F-phase OG-tag fetcher (the same one used by the F17 og_tags batch adapters). Do not import Composite_Inbound from G4. This prompt is foundation-free by design.
4. **Backward compatibility:** all existing F-phase Snipd tests pass after this change. Public method signatures preserved.
5. **Naming conventions:** match F-phase exactly. Kebab-case file names (`class-outpost-source-snipd.php`). `Outpost_` global class prefix. No PHP namespaces. No `Outpost\Adapters\Foo` namespacing.

## Tests

- URL kind detection: snip → `quote`, episode → `listen`, show → `bookmark`, unknown subpath → `bookmark`.
- Snipd API success: returns API result, no fallback fires.
- Snipd API 429: fallback fires, returns OG-derived metadata.
- Snipd API 404: no fallback, error surfaced.
- Snipd API empty payload: no fallback, original empty result returned.
- All existing F-phase Snipd tests still pass.

## Acceptance criteria

- [ ] URL kind detection method added; documented in PHPDoc.
- [ ] OG fallback added with documented trigger conditions.
- [ ] All listed tests added and passing.
- [ ] Existing F-phase Snipd tests pass (regression check).
- [ ] §5 audit lint passes.
- [ ] No forbidden words.
- [ ] Diff under 400 lines.

## PR description template

```
### Phase G — G15a — Snipd deep-link + OG fallback (foundation-free split)

Implements posse-expansion-may-2026.md §8 items 1 and 2: snip-level deep-link Post Kind mapping and OG fallback on Snipd API failure.

This is the foundation-free portion of G15. §8 items 3-6 (composite enrichment, Apple Podcasts cross-lookup, multi-clip stitching) are G15b, blocked on G4 merge (PR #35) plus Apple Music iTunes Lookup enrichment landing.

### Catalog reference

Phase G expansion catalog §8 items 1-2. Detailed prompt: `docs/dev/prompts/G15a-snipd-quick.md`.

### Test plan

6 new tests; all existing F-phase Snipd tests still pass.

### Merge order

Independent of all other Phase G PRs. Bases on main.
```

## Open items

None. All decisions locked above.
