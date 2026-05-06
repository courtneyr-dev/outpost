---
title: "G14a — iFixit auth-free read"
branch: phase-g/g14a-ifixit
base: main
depends: []
phase: G
status: ready-for-implementation
splits-from: G14
---

# G14a — iFixit auth-free read (foundation-free)

## Why this exists

The original G14 maker cluster covered three platforms: Ravelry (OAuth, blocked on G3.5 OAuth foundation), iFixit (auth-free read), Adafruit Learning (RSS, blocked on F5 #6 RSS extractor). iFixit ships in isolation now; the other two wait for foundations.

## Scope

A new source adapter for iFixit guides. Read-only. No auth required. URL pattern detection plus a thin REST call to `ifixit.com/api/2.0/guides/{guideid}`. License attribution rendering for CC-BY-NC-SA.

## Files to create

- `includes/sources/class-outpost-source-ifixit.php` (matching F-phase naming)
- `tests/unit/SourceIfixitTest.php` or matching F-phase test layout
- `docs/adapters/ifixit.md`

Modify the source dispatcher to register the new source.

## Design decisions locked

1. **URL patterns:** `ifixit.com/Guide/*`, `www.ifixit.com/Guide/*`. Extract `{guideid}` from the URL path. Optional secondary pattern `ifixit.com/Wiki/*` falls through to OG-only handling (wikis have a different API endpoint; v1 doesn't tackle wikis).
2. **API endpoint:** `GET https://www.ifixit.com/api/2.0/guides/{guideid}`. No auth header. JSON response.
3. **Fields used from response:** `title`, `subject`, `summary`, `image.medium` URL, `url` (canonical), `category`, `time_required`, `difficulty`, `documents` (skip), `tools`, `parts`. Tools and parts go into post meta as `outpost_ifixit_tools` (array) and `outpost_ifixit_parts` (array) for later display.
4. **Post Kind suggestion:** `bookmark`.
5. **License attribution mandatory.** iFixit guides are CC-BY-NC-SA by default. Render a footer line: "Source: iFixit (CC BY-NC-SA)". Filterable via `outpost_ifixit_attribution_html`.
6. **License override warning:** if a user (via filter) returns empty attribution, log an admin notice: "iFixit content is CC BY-NC-SA. Removing attribution may violate the license." Do not block the override; log only.
7. **Cache:** 1-hour transient on the API response keyed by guideid. Match F-phase cache helper if one exists; otherwise use `set_transient` directly.
8. **Failure modes:** network timeout → fall back to OG-tag fetch (use the F17 OG fetcher inline; do not import G4 Composite_Inbound). 404 → clean error, no fallback.
9. **Naming conventions:** F-phase kebab-case + `Outpost_` prefix. No PHP namespaces.

## Tests

- URL detection: `ifixit.com/Guide/foo/12345` matches with guideid 12345.
- API success: returns title, summary, image, tools array, parts array.
- 404: returns clean error.
- Network timeout: falls back to OG; returns OG-derived metadata.
- License override filter empty: admin notice logged.

## Acceptance criteria

- [ ] Source adapter created and registered with the dispatcher.
- [ ] URL detection tested.
- [ ] API integration tested with mocked response.
- [ ] OG fallback on timeout tested.
- [ ] License attribution renders by default.
- [ ] §5 audit lint passes.
- [ ] No forbidden words.
- [ ] Diff under 400 lines.

## PR description template

```
### Phase G — G14a — iFixit auth-free read

iFixit guide capture using their public API (no auth required). CC-BY-NC-SA attribution rendered automatically.

Foundation-free split from the original G14 maker cluster. Ravelry (OAuth) and Adafruit Learning (RSS) wait on foundation work.

### Catalog reference

Phase G expansion catalog §4. Detailed prompt: `docs/dev/prompts/G14a-ifixit.md`.

### Test plan

8 new tests covering URL detection, API success, 404, OG fallback, attribution.

### Merge order

Independent. Bases on main.
```

## Open items

None.
