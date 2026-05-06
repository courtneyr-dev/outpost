---
title: "G10 — Scripture inbound cluster"
branch: phase-g/g10-scripture
base: phase-g/g4-adapter-primitives
depends: [G4]
phase: G
status: ready-for-implementation
---

# G10 — Scripture inbound cluster

## Scope

Add seven scripture and lectionary inbound sources covering Christian, Muslim, Jewish, and Buddhist canon. All are read-only: the user shares a citation or URL, the adapter expands it into structured quote content for posting. Uses the G4 composite primitive for fallback chains where two sources cover the same tradition. Per Phase G decision 1, Buddhist canon (SuttaCentral) is included.

## Files to create or modify

Create:

- `outpost/includes/adapters/scripture/class-scripture-adapter-base.php` — `Outpost\Adapters\Scripture\Scripture_Adapter_Base` (shared shape: citation parsing, RTL handling, license attribution)
- `outpost/includes/adapters/scripture/class-api-bible-adapter.php` — Christian, primary
- `outpost/includes/adapters/scripture/class-bible-gateway-fallback.php` — Christian, OG fallback for missing translations
- `outpost/includes/adapters/scripture/class-sefaria-adapter.php` — Jewish, no auth
- `outpost/includes/adapters/scripture/class-quran-com-adapter.php` — Muslim, primary
- `outpost/includes/adapters/scripture/class-alquran-cloud-fallback.php` — Muslim, fallback
- `outpost/includes/adapters/scripture/class-sunnah-com-adapter.php` — Hadith
- `outpost/includes/adapters/scripture/class-suttacentral-adapter.php` — Buddhist
- `outpost/includes/adapters/scripture/class-working-preacher-adapter.php` — Christian lectionary commentary (RSS)
- `outpost/includes/adapters/scripture/class-universalis-adapter.php` — Catholic daily readings (RSS)
- `outpost/includes/citation/class-citation-parser.php` — recognizes "Genesis 1:1", "John 3:16-17", "Quran 2:255", "MN 1", "BB 1.1", "שמות פרק ב פסוק ג"
- One test file per adapter, plus `test-citation-parser.php`
- One docs page per adapter under `outpost/docs/adapters/scripture-{name}.md`

## Design decisions locked

### Shared

1. **Read-only.** No outbound. User pastes a citation (e.g. "John 3:16 NIV", "Surah Al-Baqarah 255", "MN 1") or a canonical URL; adapter expands to structured quote content.
2. **Post Kind suggestion: `quote`.** With `cite` set to the canonical URL of the original source and the translator/edition recorded in post meta.
3. **License attribution mandatory.** Every adapter records the license string in post meta `outpost_scripture_license` and renders an attribution line at the bottom of the quote when the license requires it (CC-BY, CC-BY-SA, AGPL-text, sunnah-license-text, etc.).
4. **RTL handling.** Hebrew, Arabic, and any RTL text wrapped in `<span dir="rtl" lang="he">` or `<span dir="rtl" lang="ar">` automatically. Direction detection by Unicode block scan; not configurable per-call.
5. **Composite primitive used for two fallback chains:**
   - api.bible (primary) → Bible Gateway OG (fallback) for translations api.bible doesn't carry.
   - Quran.com (primary) → AlQuran.cloud (fallback) for outage resilience.
6. **Citation parser is shared.** Single parser handles all citation formats; per-tradition adapters only handle their own URL patterns and API formats.
7. **Caching is aggressive.** Scripture text rarely changes; cache for 30 days. Filterable via `outpost_scripture_cache_ttl`.

### Per-source

8. **api.bible** (Christian primary):
   - Auth: API key in `outpost_api_bible_key`. Free tier sufficient for personal use.
   - Endpoint pattern: `GET https://api.scripture.api.bible/v1/bibles/{bible_id}/passages/{passage_id}`
   - Default Bible ID configurable per user; fallback default `de4e12af7f28f599-02` (KJV).
   - License: per-translation; recorded from `bibles.copyright` field.
9. **Bible Gateway** (Christian fallback): OG-only via G4 `Og_Inbound`. URL pattern `biblegateway.com/passage/?search={ref}&version={code}`. License: external; do not redistribute beyond fair-use single-verse quote.
10. **Sefaria** (Jewish):
    - No auth. Public API.
    - Endpoint: `GET https://www.sefaria.org/api/v3/texts/{ref}`
    - Hebrew + English side-by-side; both stored in post meta.
    - `POST /api/find-refs` for paragraph-scan smart citation detection (composer-time enrichment).
    - License: per-text; CC0 / CC-BY / CC-BY-SA depending on translator.
11. **Quran.com** (Muslim primary):
    - No auth. Public API at `api.quran.com/api/v4`.
    - Endpoint: `GET /verses/by_key/{verse_key}?translations={ids}` (e.g. verse_key `2:255`).
    - User configures preferred translation IDs in settings.
    - License: per-translation; usually CC-BY-NC.
12. **AlQuran.cloud** (Muslim fallback):
    - No auth. No rate limit.
    - Endpoint: `GET https://api.alquran.cloud/v1/ayah/{reference}/{edition}`.
    - Used when Quran.com is rate-limited or down.
13. **Sunnah.com** (Hadith):
    - API key required. Documentation step: user opens an issue at github.com/sunnah-com/api requesting a key. Manual step documented in adapter docs page.
    - Endpoint: `GET https://api.sunnah.com/v1/collections/{name}/hadiths/{number}`
    - License: per-collection. Attribution mandatory.
14. **SuttaCentral** (Buddhist):
    - No auth. Public API.
    - Endpoint: `GET https://suttacentral.net/api/suttas/{uid}/{author}?lang={code}`
    - PTS reference format: `mn1`, `sn56.11`, `dn22`. Citation parser recognizes these.
    - **Translator selection mandatory.** Default translator: Bhikkhu Sujato (CC0). User can override per-call.
    - License attribution non-optional in citation.
15. **Working Preacher** (Christian lectionary):
    - RSS feed: `https://www.workingpreacher.org/feed/podcast` or per-section RSS.
    - Use existing F-phase RSS adapter base class (or create one in G6).
    - Post Kind: `bookmark` (this is a sermon-prep aid, not a quote).
16. **Universalis** (Catholic daily readings):
    - RSS feed at `https://universalis.com/feed.xml`.
    - Post Kind: `bookmark`.

## Implementation outline

### Citation parser

- Public method: `Citation_Parser::parse( string $input ): array|null`
- Returns `['tradition' => 'christian', 'book' => 'John', 'chapter' => 3, 'verse_start' => 16, 'verse_end' => 17, 'translation' => 'NIV', 'raw' => 'John 3:16-17 NIV']` or null if unrecognized.
- Recognizes all major citation formats per tradition. Internal table-driven dispatch.

### Per-adapter shape

- Each adapter extends `Scripture_Adapter_Base`.
- Each implements: `id()`, `tradition()`, `parse_citation_or_url( $input )`, `fetch_passage( $citation_array )`, `format_quote_html( $passage_data )`.
- `Scripture_Adapter_Base` provides: license attribution rendering, RTL wrapping, post meta storage, cache integration.

### REST endpoint

- `POST outpost/v1/g/scripture/expand` with `{ source: 'sefaria', citation: 'Genesis 1:1' }` returns expanded passage. Used by composer-time UI for paragraph-scan smart detection.

## Tests

### Per-adapter coverage

- Happy path: known reference returns expected text + license + canonical URL.
- Unknown reference: returns `WP_Error('outpost_scripture_not_found')`.
- API outage: composite fallback runs (where applicable).
- License attribution rendered in formatted HTML.
- RTL wrapping applied to Hebrew/Arabic content.

### Citation parser

- Each citation format has a passing test:
  - `"John 3:16 NIV"` → christian
  - `"Genesis 1:1"` → christian, no translation
  - `"שמות ב:ג"` → jewish
  - `"Quran 2:255"` → muslim
  - `"MN 1"` → buddhist
  - `"Bukhari 1:1"` → hadith
- Garbage input returns null without throwing.

### wp-env stub pickup

- `test_api_bible_falls_back_to_bible_gateway_og`
- `test_quran_com_falls_back_to_alquran_cloud`
- `test_sefaria_rtl_wrapping`
- `test_suttacentral_translator_attribution`

## Acceptance criteria

- [ ] All seven adapters implemented.
- [ ] Citation parser handles documented formats.
- [ ] Composite fallback chains tested with mocked outages.
- [ ] License attribution rendered for every license type.
- [ ] RTL wrapping applies automatically.
- [ ] Sunnah.com docs page documents the manual key-request step.
- [ ] SuttaCentral docs page documents translator selection (default Bhikkhu Sujato CC0).
- [ ] Full test suite passes.
- [ ] §5 audit lint passes.
- [ ] Per-tradition docs pages written.
- [ ] No forbidden words.

## PR description template

```
### Phase G — G10 — Scripture inbound cluster

Adds seven scripture inbound adapters covering Christian (api.bible + Bible Gateway fallback), Jewish (Sefaria), Muslim (Quran.com + AlQuran.cloud fallback), Hadith (sunnah.com), Buddhist (SuttaCentral), and Christian lectionary (Working Preacher + Universalis).

Uses G4 composite primitive for two fallback chains. RTL handling automatic. License attribution mandatory.

### Stacked PR

This PR is stacked on PR #X (G4 — Adapter primitives v1). **Merge G4 first.** After G4 merges, retarget this PR to `main` per FY decision #29 BEFORE deleting the G4 branch.

### Catalog reference

Phase G catalog §5 (full per-source table) and §11, G10 entry. Detailed prompt: `outpost/docs/dev/prompts/G10-scripture.md`.

### Phase G decision 1

Buddhist canon (SuttaCentral) is included in default scripture cluster. Bahá'í Reference Library and LDS Standard Works deferred to Phase H or beyond.

### Test plan

- 35+ tests across 7 adapters + citation parser + composite fallback chains.
- 4 wp-env stubs picked up.

### Merge order

After G4 merges. Independent of all other Phase G PRs.
```

## Open items

- Bible Gateway scraping is fair-use for single-verse quotes. If during implementation Claude Code discovers Bible Gateway has cloudflare bot detection that blocks our User-Agent, log to `.overnight-questions.md` with subject "Bible Gateway fallback may need stricter rate limit or alternative" and proceed with the implementation; the fallback will simply fail silently in those cases and api.bible primary will surface the original error.
- If Sunnah.com API key request takes more than 7 days during testing, the integration test for sunnah.com uses a recorded fixture rather than a live key. Document this in the test file.
