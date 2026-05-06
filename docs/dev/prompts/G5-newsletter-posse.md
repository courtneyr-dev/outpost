---
title: "G5 — Newsletter POSSE-outbound cluster"
branch: phase-g/g5-newsletter-posse
base: main
depends: []
phase: G
status: ready-for-implementation
---

# G5 — Newsletter POSSE-outbound cluster

## Scope

Add four newsletter platforms as POSSE syndication targets (publish on WP first, then fan out copies): Beehiiv, Buttondown, Kit, write.as. Triggered by post-publish hook (existing F-phase pattern). Each platform has its own settings panel under the existing POSSE syndication tab. WP post becomes canonical; newsletter copies link back via `canonical_url`.

## Files to create or modify

Create:

- `outpost/includes/adapters/class-beehiiv-posse-adapter.php` — `Outpost\Adapters\Beehiiv_Posse_Adapter`
- `outpost/includes/adapters/class-buttondown-posse-adapter.php`
- `outpost/includes/adapters/class-kit-posse-adapter.php`
- `outpost/includes/adapters/class-write-as-posse-adapter.php`
- `outpost/tests/integration/adapters/test-beehiiv-posse-adapter.php`
- `outpost/tests/integration/adapters/test-buttondown-posse-adapter.php`
- `outpost/tests/integration/adapters/test-kit-posse-adapter.php`
- `outpost/tests/integration/adapters/test-write-as-posse-adapter.php`
- `outpost/docs/adapters/beehiiv.md`
- `outpost/docs/adapters/buttondown.md`
- `outpost/docs/adapters/kit.md`
- `outpost/docs/adapters/write-as.md`

Modify:

- `outpost/includes/admin/class-settings-page.php` — add per-platform sub-pages under existing POSSE tab
- `outpost/includes/class-outpost.php` — register the four adapters on plugins_loaded

## Design decisions locked

### Trigger

1. **Post-publish hook.** Identical pattern to existing F-phase POSSE adapters. Hook into `transition_post_status` from `*` to `publish`. Skip if post type not `post` (configurable via filter `outpost_posse_post_types`).
2. **Per-platform enabled flag.** Each adapter has an `outpost_{platform}_posse_enabled` option, default false. User opts in per platform.
3. **Per-post override.** Sidebar checkbox "Don't syndicate to {Platform} for this post" lives in the existing F-phase POSSE sidebar panel. This panel already exists; add a row per new platform. No new panel.

### Auth per platform

4. **Beehiiv:** Bearer API key stored in `outpost_beehiiv_api_key` (encrypted via the F-phase encrypted-options helper if it exists; otherwise plain `get_option` with a TODO to encrypt in Phase H).
5. **Buttondown:** `Authorization: Token {api_key}` (note: Token, not Bearer). Stored in `outpost_buttondown_api_key`.
6. **Kit:** OAuth 2.0 v4 (preferred for new installs) or v3 API key (legacy). Settings panel shows OAuth "Connect" button + fallback "Or paste v3 API key" expandable section.
7. **write.as:** API token authentication. Token obtained by user from write.as settings; stored in `outpost_write_as_api_token`.

### Content transformation

8. **Beehiiv:** `do_blocks()` → `wpautop()` → submit as `body_content` field on `POST /v2/publications/{id}/posts`. Status `confirmed` (deprecation notice acknowledged: pass explicit `confirmed` to preserve auto-publish). Subject = post title.
9. **Buttondown:** Block content → markdown via existing transformer (or new converter; see G7 for converter notes — share if possible). Submit as `body` field on `POST /v1/emails`. Status `about_to_send` for immediate send; `draft` if user has set "syndicate as draft" option (default off).
10. **Kit:** `do_blocks()` → `wpautop()` → HTML to broadcast endpoint. Subject = post title.
11. **write.as:** Block content → markdown (same converter as Buttondown). Submit to `POST /api/posts`. write.as natively understands markdown.

### Canonical link

12. **Every POSSE copy includes the WP post URL as canonical link.**
    - Beehiiv: append a `<p><small>This post originally appeared on <a href="{wp_url}">{wp_url}</a>.</small></p>` to body. Beehiiv has no `canonical_url` field.
    - Buttondown: `canonical_url` field is supported on `POST /v1/emails`.
    - Kit: append the same paragraph as Beehiiv. Kit broadcasts have no canonical_url field.
    - write.as: append paragraph; write.as supports custom slugs but no canonical_url field in API.

### Status surface

13. **Per-post meta** registered with `register_post_meta`:
    - `outpost_beehiiv_posse_status` (`pending` | `syndicated` | `failed` | `skipped`)
    - `outpost_beehiiv_posse_external_url` (the URL of the post on Beehiiv after success)
    - `outpost_beehiiv_posse_synced_at` (ISO 8601)
    - `outpost_beehiiv_posse_error` (string when failed)
    - Same four per platform.
14. **Activity log** entry on every attempt, identical pattern to existing F-phase POSSE adapters.

### Failure handling

15. **One auto-retry on transient failures** (HTTP 429, 502, 503, 504, network timeout). Wait 60 seconds via WP-Cron. After the retry, mark `failed` and surface admin notice.
16. **No retry on auth failures** (401, 403). Mark `failed` immediately, surface admin notice with "Reconnect {Platform}" button.
17. **Beehiiv 402 (Send API gating) does not apply here.** POSSE-outbound uses `POST /v2/publications/{id}/posts` which is on standard plans, not the Send API. Document this distinction in the Beehiiv docs page.

## Implementation outline

- Each adapter extends a shared abstract base class `Outpost\Adapters\Posse_Adapter_Base` (already exists from F-phase per the F4-F16 patterns; if not, create it as part of this PR).
- Each adapter implements: `id()`, `display_name()`, `is_configured()`, `syndicate( int $post_id ): array|WP_Error`.
- Shared `Posse_Adapter_Base` handles: post-publish hook registration, per-post override check, status meta updates, activity log writes, retry scheduling.
- Per-platform classes only implement the actual API call + content transformation specific to that platform.

## Tests

### Per-platform coverage

- Happy path: post publish triggers adapter, adapter calls API, success path updates meta.
- Auth failure: 401 returns specific error code; meta marked failed; no retry scheduled.
- Transient failure: 503 schedules WP-Cron retry; second attempt success marks `syndicated`.
- Per-post skip override: meta `_outpost_skip_{platform}_posse` = `true` causes adapter to skip with status `skipped`.
- Disabled platform: setting off → adapter does not run.

### Shared coverage

- Activity log entries written per attempt.
- Canonical link appended correctly for the three platforms without native `canonical_url` field.
- Buttondown canonical_url field used correctly.

### wp-env stub pickup

- `test_beehiiv_posse_publishes_on_wp_publish`
- `test_buttondown_posse_canonical_url_set`
- `test_kit_posse_oauth_v4_flow`
- `test_write_as_posse_markdown_conversion`

## Acceptance criteria

- [ ] All four adapters implemented; all four publish successfully against mocked API responses.
- [ ] Settings sub-pages registered for each platform under existing POSSE tab.
- [ ] Per-post sidebar overrides work for each platform.
- [ ] Activity log + status meta correctly written for every attempt.
- [ ] Auto-retry on transient failures works; no retry on auth failures.
- [ ] Canonical link strategy implemented per platform.
- [ ] Full test suite passes.
- [ ] §5 audit lint passes.
- [ ] Per-platform docs pages written.
- [ ] No forbidden words.

## PR description template

```
### Phase G — G5 — Newsletter POSSE-outbound cluster

Adds Beehiiv, Buttondown, Kit, and write.as as POSSE syndication targets. WP post is canonical; copies fan out to enabled platforms on publish. Distinct from G7 (headless-send), which inverts the canonical relationship.

### Catalog reference

Phase G catalog §11, G5 entry. Per-platform details in catalog §2. Detailed prompt: `outpost/docs/dev/prompts/G5-newsletter-posse.md`.

### Locked design decisions

- Post-publish hook trigger; per-platform enable flag; per-post sidebar override.
- One retry on transient failures via WP-Cron; no retry on auth failures.
- Canonical-link paragraph appended to body where API has no `canonical_url` field.

### Test plan

- 28+ tests across 4 adapters + shared base class behavior.
- 4 wp-env stubs picked up.

### Merge order

Independent. Can merge in any order relative to other Phase G PRs. May share scaffolding with G7 (headless-send); if both PRs touch the same credential storage layer, last-merged needs minor conflict resolution.
```

## Open items

None. All decisions locked above.

If during implementation Claude Code finds the existing F-phase POSSE adapters use a different abstract base class shape than the one outlined above, match the existing shape rather than introducing a new one.
