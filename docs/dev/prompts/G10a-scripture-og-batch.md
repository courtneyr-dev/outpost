---
title: "G10a — Sefaria + SuttaCentral og_tags batch"
branch: phase-g/g10a-scripture-og-batch
base: main
depends: []
phase: G
status: ready-for-implementation
splits-from: G10
---

# G10a — Sefaria + SuttaCentral og_tags batch (foundation-free)

## Why this exists

The original G10 prompt covered 7 scripture sources and depended on G4 composite primitive plus settings-UI scaffolding for API-key sources. This split ships the two simplest auth-free public sources right now using the F17 og_tags pattern (the same pattern Substack and Apple Music use in PR #34). The remaining 5 (api.bible, sunnah.com, Quran.com + AlQuran.cloud fallback chain, Working Preacher RSS, Universalis RSS) become G10b after foundation work lands.

## Scope

Two new og_tags-style adapters for Sefaria and SuttaCentral. Both detect their URL patterns, fetch the canonical page, parse OG meta, and return structured content for the composer. No API keys, no OAuth, no settings UI required — pure URL-pattern detection + OG fetch.

## Files to create

Match the F17 batch pattern. Find an F17 og_tags adapter (e.g., the Substack one) via `grep -ri 'substack' includes/sources/`, copy its shape, and rename for Sefaria and SuttaCentral.

- `includes/sources/class-outpost-source-sefaria.php` (matching F-phase naming)
- `includes/sources/class-outpost-source-suttacentral.php`
- `tests/unit/SourceSefariaTest.php` or matching F-phase test layout
- `tests/unit/SourceSuttaCentralTest.php`
- `docs/adapters/sefaria.md`
- `docs/adapters/suttacentral.md`

Modify whatever class registers F17 sources with the source dispatcher; add the two new sources.

## Design decisions locked

### Sefaria

1. **URL patterns:** `sefaria.org/*`, `www.sefaria.org/*`. Adapter recognizes any path under those hosts as a candidate.
2. **No API call.** Despite Sefaria having a rich free API, this adapter is og_tags-only for now. The full API integration with citation parsing and RTL handling is G10b.
3. **OG behavior:** Sefaria's pages have clean Open Graph tags including title, description (often the verse text in English + Hebrew), and image. Use them as-is.
4. **Post Kind suggestion:** `quote`.
5. **License attribution:** Sefaria texts are CC0/CC-BY/CC-BY-SA per text. The og_tags adapter cannot reliably know which license applies to a given URL. Add a generic attribution line: "Source: Sefaria.org" with a note in the docs page that full license-aware attribution arrives with G10b.
6. **RTL handling:** OG description from Sefaria often contains both Hebrew and English. Wrap the entire description in a container with `lang="he"` only when a Unicode block scan detects more Hebrew than English; otherwise leave plain. Conservative for v1.

### SuttaCentral

7. **URL patterns:** `suttacentral.net/*`. Recognize any path.
8. **No API call.** As with Sefaria, full API integration with translator selection is G10b.
9. **OG behavior:** SuttaCentral provides OG title (sutta name) and description (first lines or translator-provided summary). Image often a sutta-collection icon.
10. **Post Kind suggestion:** `quote`.
11. **License attribution:** SuttaCentral texts are mostly CC0 (Bhikkhu Sujato translations). Add attribution: "Source: SuttaCentral.net". Note in docs that translator-aware attribution arrives with G10b.

### Shared

12. **No settings UI.** Both adapters work out of the box with no configuration.
13. **Naming conventions:** match F-phase exactly. Kebab-case files. `Outpost_Source_Sefaria` and `Outpost_Source_Suttacentral` class names. No PHP namespaces.
14. **Cache:** match whatever caching the F17 og_tags adapters use; do not invent a new cache pattern.

## Tests

For each adapter:

- URL detection: matches its own host; rejects others.
- Successful fetch: returns expected title, description, image from a real-world fixture.
- 404: returns clean error.
- OG meta missing: returns response with empty fields rather than throwing.

## Acceptance criteria

- [ ] Both adapters added; both registered with the source dispatcher.
- [ ] Both detected by URL pattern; tested.
- [ ] Both fetch and return OG metadata against fixtures.
- [ ] §5 audit lint passes.
- [ ] No forbidden words.
- [ ] Both docs pages note that full API-aware integration is G10b.
- [ ] Diff under 400 lines total.

## PR description template

```
### Phase G — G10a — Sefaria + SuttaCentral og_tags batch

Two scripture og_tags adapters following the F17 pattern. Foundation-free: no API keys, no OAuth, no settings UI.

The remaining 5 platforms in the original G10 spec (api.bible, Quran.com + fallback, sunnah.com, Working Preacher, Universalis) ship as G10b after foundation work lands (settings-UI + RSS extractor + composite primitive in production).

### Catalog reference

Phase G expansion catalog §5. Detailed prompt: `docs/dev/prompts/G10a-scripture-og-batch.md`.

### Test plan

8+ tests across both adapters.

### Merge order

Independent. Bases on main.
```

## Open items

None.
