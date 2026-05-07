---
title: "G-oauth-extenders-batch — Oura + RWG + Ravelry OAuth providers + G8b Notion share-target wire-up"
branch: per-prompt (4 separate PRs)
base: main
depends: [G3.5a]
phase: G
status: ready-for-implementation
unblocks: [G11 sources, G12 sources, G14 sources, real Notion shares]
---

# G-oauth-extenders-batch

## Why this exists

G3.5a (PR #43) shipped OAuth 2.0 foundation + encrypted credential storage + Notion as proof-of-concept. The pattern is now production-proven. This batch extends the pattern to three more OAuth providers (Oura, Ride With GPS, Ravelry) and wires the Notion adapter into the share-target preview path so it actually fetches authenticated metadata instead of falling back to og:title.

Four PRs, all foundation-ready, all using G3.5a Notion as the structural template.

## What's IN this batch

Four small, independent PRs — each ~400-600 lines:

1. **G11a — Oura OAuth provider** (highest-priority wellness platform; membership-gate handling required)
2. **G12a — Ride With GPS OAuth provider** (cycling activity capture)
3. **G14b — Ravelry OAuth provider** (knit/crochet patterns + projects, Courtney's audience)
4. **G8b — Notion API wired into share-target preview** (consumes G3.5a's Notion adapter)

## What's NOT in this batch

- **WHOOP and Polar Flow OAuth providers.** Defer to a follow-up batch once Oura proves the wellness-platform pattern (especially membership-gate handling).
- **Source classes for the new providers.** This batch ships OAuth + Connect/Disconnect UI + a verify-connection endpoint per platform. URL-pattern detection, content fetching, and composer integration come in follow-up PRs (G11a-source, G12a-source, G14b-source) once the connection path is proven.
- **Disconnect button click target fix.** Tiny ~20-line UI fix; Claude Code already noted it; ship as a separate trivial PR if there's time after the main four.
- **wp-env Docker mock-server wiring.** Deserves its own design conversation; not included.
- **G7 newsletter headless-send.** Still needs G3.5c Gutenberg sidebar plugin scaffolding (separate foundation gap).

## Reference: G3.5a Notion is the template

Every OAuth provider in this batch follows the same structure as the Notion provider shipped in PR #43. Read these files first to understand the pattern:

- `includes/oauth/providers/class-outpost-oauth-provider-notion.php` — provider subclass
- `includes/oauth/class-outpost-oauth-provider-base.php` — base class
- `includes/oauth/class-outpost-oauth-controller.php` — flow orchestration
- `includes/oauth/class-outpost-oauth-rest-endpoints.php` — REST endpoint pattern
- `includes/credentials/class-outpost-credentials-store.php` — credential storage
- The settings-page modification that added the "Connect Notion" button

Each new provider replicates the Notion shape. Per-provider specifics are tabled below.

## G11a — Oura OAuth provider

### Files to create

- `includes/oauth/providers/class-outpost-oauth-provider-oura.php`
- `tests/unit/OAuthProviderOuraTest.php`
- `docs/adapters/oura.md`

### Files to modify

- The existing settings page class (the same one G3.5a modified for Notion) — add "Connect Oura" button block adjacent to the Notion one. No redesign.
- `includes/oauth/class-outpost-oauth-controller.php` — register the Oura provider.

### Design decisions locked

1. **Authorize URL:** `https://cloud.ouraring.com/oauth/authorize`
2. **Token URL:** `https://api.ouraring.com/oauth/token`
3. **Scopes:** `email personal daily heartrate workout tag session ring_configuration` (space-separated; this is the full read-only set)
4. **API base for verification call:** `https://api.ouraring.com/v2/usercollection/personal_info`
5. **Token shape:** standard OAuth 2.0 (`access_token`, `refresh_token`, `token_type`, `expires_in`). Add `obtained_at` for our own expiry math.
6. **Revocation endpoint:** `https://api.ouraring.com/oauth/revoke`. Implement RFC 7009 revocation per the G3.5a pattern.
7. **Membership gate handling:** Oura returns HTTP 401 with response body containing `"detail": "expired_oura_membership"` (or similar membership-related string) when the user's Membership has lapsed for Gen3 + Ring 4 hardware. Detect this specific error and surface a dismissible admin notice: "Oura Ring data requires an active Oura Membership. Manage at ouraring.com/account." Do NOT clear stored credentials on membership-gate errors (the OAuth token is still valid; only the data access is gated). Distinct from token-expired errors.
8. **Verify-connection endpoint:** `GET outpost/v1/oauth/oura/verify` calls `personal_info` endpoint and returns `{ ok: true, email: "..." }` on success. On membership-gate error, returns `{ ok: false, reason: "membership_required" }`. On other auth errors, returns `{ ok: false, reason: "auth_failed" }`.

### Tests

- OAuth flow happy path (mocked token exchange).
- Token refresh on `is_expired()`.
- Revocation called on disconnect.
- Membership-gate detection: `expired_oura_membership` 401 surfaces specific notice.
- Verify endpoint returns expected shape on success and on each error class.

## G12a — Ride With GPS OAuth provider

### Files to create

- `includes/oauth/providers/class-outpost-oauth-provider-ridewithgps.php`
- `tests/unit/OAuthProviderRidewithgpsTest.php`
- `docs/adapters/ridewithgps.md`

### Files to modify

- Settings page — add "Connect Ride With GPS" button.
- OAuth controller — register the provider.

### Design decisions locked

1. **Authorize URL:** `https://ridewithgps.com/oauth/authorize`
2. **Token URL:** `https://ridewithgps.com/oauth/token.json` (note `.json` suffix; RWG-specific)
3. **Scopes:** `read` (default for v1; `write` deferred until outbound trip-sync is in scope, which is a future PR)
4. **API base for verification call:** `https://ridewithgps.com/api/v1/users/current.json`
5. **Token shape:** standard OAuth 2.0; RWG returns `access_token`, `token_type`, possibly `refresh_token` depending on app config. Handle missing refresh_token gracefully (some RWG OAuth apps issue long-lived tokens without refresh).
6. **Revocation endpoint:** RWG does not currently expose RFC 7009 revocation. `revocation_endpoint()` returns null. Document this in the docs page.
7. **Privacy boundary note in docs:** RWG routes have a `privacy_code` field. Future source classes must respect this — do NOT expose private routes. Document this in the docs page even though source classes are out of this PR's scope.
8. **Verify-connection endpoint:** `GET outpost/v1/oauth/ridewithgps/verify` calls `users/current.json` and returns `{ ok: true, name: "...", id: ... }`.

### Tests

- OAuth flow happy path.
- Missing refresh_token handled (no crash, no false expiry).
- Verify endpoint returns expected shape.

## G14b — Ravelry OAuth provider

### Files to create

- `includes/oauth/providers/class-outpost-oauth-provider-ravelry.php`
- `tests/unit/OAuthProviderRavelryTest.php`
- `docs/adapters/ravelry.md`

### Files to modify

- Settings page — add "Connect Ravelry" button.
- OAuth controller — register the provider.

### Design decisions locked

1. **Authorize URL:** `https://www.ravelry.com/oauth2/auth`
2. **Token URL:** `https://www.ravelry.com/oauth2/token`
3. **Scopes:** `offline` and platform-specific scopes per Ravelry docs. Default to a read-only set covering patterns + projects + library. Ravelry's scope names are non-obvious; check the current Ravelry API docs at `https://www.ravelry.com/api` for the exact scope strings before implementing.
4. **API base for verification call:** `https://api.ravelry.com/current_user.json`
5. **Token shape:** standard OAuth 2.0 with refresh_token.
6. **Revocation endpoint:** check Ravelry API docs for current revocation support. If supported, implement; if not, return null and document.
7. **Note in docs page:** OAuth 1.0a is dropped per design call. Users who registered 1.0a apps must register a new OAuth 2.0 app at `https://www.ravelry.com/pro/developer`. Document the steps.
8. **Verify-connection endpoint:** `GET outpost/v1/oauth/ravelry/verify` calls `current_user.json` and returns `{ ok: true, username: "...", display_name: "..." }`.

### Tests

- OAuth flow happy path.
- Token refresh.
- Verify endpoint shape.

## G8b — Notion API wired into share-target preview

### Why

G3.5a shipped the Notion OAuth provider + adapter, but the share-target preview path still uses og:title fallback when a Notion URL is shared. This PR routes Notion URLs through the authenticated Notion adapter so users see real page metadata (title, icon, structured blocks) instead of generic OG tags.

### Scope

Modify the share-target preview path to detect Notion URLs and route them through the authenticated Notion adapter when the current user has connected Notion. Fall back to og:title behavior only if (a) no Notion connection, or (b) the page is not shared with the user's integration.

### Files to modify

- The share-target preview path class (find via `grep -ri 'share.target.preview' includes/` or look at how og:title fallback is currently dispatched). Hook into the dispatch to give Notion adapter priority for Notion URLs.

### Files to create

- `tests/integration/NotionShareTargetPreviewTest.php`
- Update `docs/adapters/notion.md` with the share-target behavior.

### Design decisions locked

1. **Detection:** if URL matches a Notion URL pattern (`notion.so/*`, `*.notion.site/*`, `notion.com/*`), check whether current user has a Notion connection via `Outpost_Credentials_Store::is_configured('notion')`.
2. **Connected path:** call the Notion adapter; on success, return its structured metadata (title, icon, first-level blocks summary). On 404 (page not shared with integration), surface a user-friendly notice in the preview: "This Notion page hasn't been shared with Outpost. In Notion, click ••• → Add connections → Outpost." then fall through to og:title.
3. **Disconnected path:** fall through to existing og:title behavior. Optionally: surface a small "Connect Notion for richer previews" hint in the preview UI. Hint is dismissible per-user.
4. **No source-class changes.** This PR only changes the share-target preview routing. The Notion source class shipped in G3.5a remains unchanged.

### Tests

- Connected user shares Notion URL: preview shows page title + icon + first-level blocks.
- Connected user shares unshared Notion page: preview shows "not shared" notice, falls through.
- Disconnected user shares Notion URL: preview falls through to og:title (existing behavior).
- Non-Notion URL: routes unchanged.

## Shared design decisions across all four PRs

1. **F-phase native conventions exactly.** Kebab-case files, `Outpost_` prefix, no PHP namespaces in our code. League OAuth2 lib's namespaces used through fully-qualified names.
2. **Per-user credential storage by default** (G3.5a default). No site-wide opt-in for these platforms (Oura, RWG, Ravelry are personal accounts).
3. **Connect button capability gate:** matches G3.5a (`manage_options` + logged in user). Same precedent.
4. **Storage scope filter from G3.5a is reused.** Filter `outpost_credentials_storage_scope_{platform}` works automatically for the new providers; document in each provider's docs page that the filter exists.
5. **Verify endpoints capability gate:** `manage_options` + logged in (matches Connect endpoint pattern).
6. **Each PR's diff cap: 600 lines.** Hard ceiling. If a provider's tests + docs push over, split tests into integration-test follow-up.
7. **No source classes in this batch.** Source classes (URL detection + content fetch + composer integration) ship as follow-up PRs once the OAuth path is proven for each platform.

## Acceptance criteria (per PR)

- [ ] OAuth provider class implemented per the locked specifics.
- [ ] Connect button on settings page renders and initiates real OAuth flow.
- [ ] Verify endpoint returns expected shape against mocked API.
- [ ] Token refresh works lazily on `is_expired()`.
- [ ] Revocation called on disconnect where supported; fail-soft on revoke errors.
- [ ] Tests passing locally.
- [ ] §5 audit lint passes.
- [ ] Existing F-phase + G3.5a + G4a + G4b tests still pass (regression).
- [ ] Docs page written.
- [ ] No forbidden words.
- [ ] Diff under 600 lines.

## PR description template (per PR)

```
### Phase G — {prompt-id} — {provider} OAuth provider

Extends the G3.5a OAuth foundation to {Provider}. Ships OAuth provider class + Connect/Disconnect button on settings + verify-connection REST endpoint.

Source class (URL detection + content fetch + composer integration) is a follow-up PR; this PR is foundation-only for {Provider}.

### Catalog reference

Phase G expansion catalog §{section}. Detailed prompt: `docs/dev/prompts/G-oauth-extenders-batch.md` (G{prompt-id} section).

### G3.5a is the template

This PR copies the structure of `class-outpost-oauth-provider-notion.php` and adapts to {Provider}'s OAuth specifics. See prompt for the locked auth URL, token URL, scopes, and revocation behavior.

### Test plan

{N} new tests covering OAuth flow, token refresh, revocation, verify endpoint shape, {provider-specific concerns like membership-gate}.

### Merge order

Independent. Bases on main. Other PRs in the same batch (Oura, RWG, Ravelry, G8b) are also independent of each other.
```

## Open items

- **WHOOP and Polar Flow.** Deferred to a follow-up batch. Same pattern; just not in this run.
- **Source classes for Oura/RWG/Ravelry/(WHOOP/Polar Flow when they ship).** Each platform's source class needs a UX design call: URL-paste pattern (RWG, Ravelry have shareable URLs) vs "fetch recent" picker (Oura, WHOOP, Polar Flow don't have shareable activity URLs). Different UX shapes; deserves a sketch conversation before drafting.
- **Disconnect button click target fix.** Trivial ~20-line UI fix Claude Code noted. Ship as a separate small PR (G99-disconnect-fix) any time.
- **wp-env Docker mock-server wiring.** Unskips ~14 integration test stubs across G3.5a + G4b. Deserves its own prompt body with care; not in this batch.
