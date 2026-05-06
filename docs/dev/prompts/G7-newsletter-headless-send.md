---
title: "G7 — Newsletter headless-send cluster"
branch: phase-g/g7-newsletter-headless-send
base: main
depends: []
phase: G
status: ready-for-implementation
---

# G7 — Newsletter headless-send cluster

## Scope

Add four newsletter platforms as headless email-sending engines for WordPress-authored content: Beehiiv, Buttondown, Kit, Mailchimp. Functionally distinct from G5 (POSSE-syndicate, where the WP post is canonical and copies fan out). In G7, the WP draft or post becomes the source for an email *send* through the user's chosen platform, with optional WP publish before, after, or never. New post-sidebar panel labeled "Send via newsletter" sits adjacent to (not inside) the POSSE syndication panel.

## Files to create or modify

Create:

- `outpost/includes/headless-send/class-headless-send-controller.php` — `Outpost\Headless_Send\Controller`
- `outpost/includes/headless-send/interface-headless-send-target.php` — `Outpost\Headless_Send\Target`
- `outpost/includes/headless-send/targets/class-beehiiv-target.php` — Beehiiv Send API integration
- `outpost/includes/headless-send/targets/class-buttondown-target.php` — Buttondown emails endpoint
- `outpost/includes/headless-send/targets/class-kit-target.php` — Kit broadcasts endpoint
- `outpost/includes/headless-send/targets/class-mailchimp-target.php` — Mailchimp campaigns endpoint
- `outpost/includes/headless-send/class-content-transformer.php` — WP blocks → markdown/HTML per target
- `outpost/includes/admin/class-headless-send-sidebar.php` — Gutenberg sidebar plugin
- `outpost/assets/js/headless-send-sidebar.js` — sidebar React component (built via existing Webpack pipeline)
- `outpost/includes/rest/class-headless-send-endpoint.php` — REST: `POST outpost/v1/g/headless-send/{platform}`
- `outpost/includes/rest/class-headless-send-status-endpoint.php` — REST: `GET outpost/v1/g/headless-send/status/{post_id}`
- `outpost/tests/integration/headless-send/test-controller.php`
- `outpost/tests/integration/headless-send/test-{platform}-target.php` (one per platform = 4 files)
- `outpost/tests/integration/headless-send/test-content-transformer.php`
- `outpost/docs/adapters/headless-send-beehiiv.md`
- `outpost/docs/adapters/headless-send-buttondown.md`
- `outpost/docs/adapters/headless-send-kit.md`
- `outpost/docs/adapters/headless-send-mailchimp.md`
- `outpost/docs/concepts/headless-send-vs-posse.md` — explainer covering the two distinct user journeys

Modify:

- `outpost/includes/admin/class-settings-page.php` — add "Newsletter headless-send" tab next to existing "POSSE syndication" tab
- `outpost/includes/class-outpost.php` — register the new controller on plugins_loaded

## Design decisions locked

### UX placement

1. **Gutenberg sidebar plugin slot.** Register a `PluginDocumentSettingPanel` with title "Send via newsletter". Sits below "POSSE syndication" panel, not inside it. Distinct visual separator. The two panels are siblings, never nested.
2. **Per-platform toggle inside the panel.** Each enabled platform shows a row with: platform name, status badge (Not sent / Sending… / Sent / Failed), default list selector (populated from API on settings save), subject-line override field (defaults to post title), and a single "Send via {Platform}" button.
3. **No auto-send on publish.** Headless send is always explicit. The user clicks the button. This is the core distinction from G5 POSSE-syndicate, which is post-publish-hook driven.
4. **Confirmation dialog before send.** Modal with: target list count (fetched live), subject line preview, "Send to {N} subscribers" confirmation. Click → fire the REST endpoint → modal updates with status.
5. **Send works on draft posts.** Headless send does not require the WP post to be published. A user can compose in WP, send to their newsletter, and never publish on WP if they choose. Settings option "Auto-publish on successful send" defaults off.
6. **Re-send protection.** If a post has been sent to a platform once successfully (`outpost_headless_send_{platform}_status` meta = `sent`), the button is disabled with tooltip "Already sent. Use the platform's UI to resend." Override via per-post meta key `outpost_headless_send_allow_resend` (no UI for this; intentional friction).

### Per-platform endpoints and auth

7. **Beehiiv:** `POST /v2/publications/{publication_id}/posts` with status `confirmed` and `email_capture_type` set per user preference. Send API requires Enterprise plan; on 402, surface admin notice "Beehiiv Send API requires Enterprise plan. Open a ticket with Beehiiv support to enable." Auth: Bearer API key from settings.
8. **Buttondown:** `POST /v1/emails` with `status: about_to_send` and full body. Works on free tier. Auth: `Authorization: Token {api_key}` (not Bearer).
9. **Kit:** `POST /v4/broadcasts` (v4 OAuth required) or `POST /v3/broadcasts` (v3 API key, deprecated path but still works). Default to v4 OAuth in new installs. Creator tier or higher required; on plan-gate failure (403 with specific message), surface admin notice with upgrade link.
10. **Mailchimp:** Two-step. `POST /3.0/campaigns` to create, then `POST /3.0/campaigns/{id}/actions/send` to send. Data-center prefix resolved via OAuth metadata endpoint and stored on settings save. Works on any paid plan; free Mailchimp accounts cannot send via API.
11. **Default list selection.** Each platform settings page has a "Default list" dropdown populated by calling the platform's lists endpoint at settings save. Cached for 1 hour. Sidebar can override per-send via the list selector.

### Content transformation

12. **Buttondown takes markdown.** Transformer: WP blocks → markdown via the existing F-phase block-to-markdown converter (if present) or a new converter built on `parsedown` or `league/commonmark` (whichever is already a composer dep; do not add new deps).
13. **Beehiiv accepts structured blocks or HTML.** Use HTML for simplicity in v1: WP `do_blocks()` → `wpautop()` → submitted as `body_content`. Defer the structured-blocks code path to Phase H.
14. **Kit takes HTML.** Same as Beehiiv: `do_blocks()` → `wpautop()`.
15. **Mailchimp takes HTML in `html_content`.** Same approach.
16. **Stripped from all transforms:** WP-specific shortcodes that reference WP filesystem paths, Gutenberg block comments (`<!-- wp:* -->`), WP admin notices accidentally captured in content. Transformer has a single `outpost_headless_send_strip_wp_artifacts` filter pass before output.
17. **Featured image becomes header image** where the platform supports it (Beehiiv `thumbnail_url`, Mailchimp template merge field). Buttondown and Kit do not have first-class header image fields; image stays inline at top of body.

### Status surface

18. **Per-post meta keys** (registered with `register_post_meta`):
    - `outpost_headless_send_beehiiv_status` (`unsent` | `sending` | `sent` | `failed`)
    - `outpost_headless_send_beehiiv_sent_at` (ISO 8601)
    - `outpost_headless_send_beehiiv_recipient_count` (int)
    - `outpost_headless_send_beehiiv_error` (string, only set when status = failed)
    - Same four for `_buttondown_`, `_kit_`, `_mailchimp_`
19. **Activity log entry** appended to `outpost_activity_log` option on every send attempt (success or failure). Same option used by F-phase POSSE syndication. Format: `{timestamp, post_id, platform, action: 'headless_send', status, error?}`.

### Failure handling

20. **No auto-retry.** Failed sends require explicit user re-click. Rationale: an email send that fails partway through is potentially partially-delivered; auto-retry could double-send.
21. **Admin notice on failure** persists until dismissed: "Headless send to {Platform} failed for post {title}. Click to view error." Click opens the post editor with the error displayed inline in the sidebar panel.
22. **Membership-gate errors** (Beehiiv 402, Kit 403 with plan-required code) get a specific dismissible notice with upgrade-link UI rather than the generic failure notice.

## Implementation outline

### Controller

- `Controller::register_targets( array $targets )` — called on `plugins_loaded`
- `Controller::send( int $post_id, string $platform, array $args = [] ): array|WP_Error` — orchestrator: validates post + platform + auth, calls target's `send()`, updates meta, logs activity
- `Controller::get_status( int $post_id, string $platform ): array` — returns the four meta values plus a human-readable summary

### Target interface

```php
interface Target {
    public function id(): string;                                  // 'beehiiv', 'buttondown', etc.
    public function display_name(): string;                        // 'Beehiiv'
    public function is_configured(): bool;                         // API key/OAuth present
    public function fetch_lists(): array|WP_Error;                 // for default-list dropdown
    public function send( int $post_id, array $args ): array|WP_Error;  // actual send
    public function transform_content( int $post_id ): string;     // calls Content_Transformer
}
```

### Sidebar plugin

- React component using `@wordpress/plugins` and `@wordpress/edit-post`.
- Reads platform configuration from `wp_localize_script` global.
- Each platform row is a sub-component with its own state (status, list selection, subject override).
- Send button click: nonce check → fetch confirmation data → modal → POST to REST endpoint → poll status endpoint until terminal state.

## Tests

### Unit / integration coverage required

- **Per target (4 platforms):**
  - `is_configured()` returns true when API credentials present, false when missing.
  - `fetch_lists()` returns array on success, `WP_Error` on auth failure.
  - `send()` happy path with mocked HTTP: returns success array, updates meta correctly.
  - `send()` with 402/403 plan-gate error: returns specific `WP_Error` code (`outpost_headless_send_plan_gated`).
  - `send()` with 401 auth error: returns specific `WP_Error` code (`outpost_headless_send_auth_failed`).
  - `transform_content()` strips WP-specific artifacts.
- **Controller:**
  - Validates platform is registered before calling.
  - Re-send protection blocks second send when status is `sent` and no override meta.
  - Override meta allows resend.
  - Activity log entry written on every attempt.
- **Content transformer:**
  - Block content → markdown roundtrip preserves headings, links, images, lists, blockquotes.
  - Featured image promoted to header where supported.
  - Shortcodes stripped per filter.
- **REST endpoints:**
  - Send endpoint requires `edit_post` capability + nonce.
  - Status endpoint returns expected JSON shape.
  - Both endpoints return appropriate HTTP codes on failure.

### wp-env stub pickup

Pick up the following stubs from the 80-skipped backlog:

- `test_headless_send_beehiiv_happy_path`
- `test_headless_send_buttondown_drafts_workflow`
- `test_headless_send_resend_protection`

## Acceptance criteria

- [ ] All four platforms send successfully against mocked API responses.
- [ ] Sidebar plugin renders, fetches lists, allows subject override, fires confirmation modal, calls REST endpoint, polls status.
- [ ] Settings tab "Newsletter headless-send" registers and renders per-platform sub-pages.
- [ ] Per-post meta registered correctly; visible in REST API for the post; respects `auth_callback` for capability.
- [ ] Activity log entries written on every attempt.
- [ ] Membership-gate errors surface a distinct dismissible notice with upgrade-link UI.
- [ ] Send works on draft posts (does not require post to be published).
- [ ] Re-send protection works; override meta allows resend.
- [ ] Full test suite passes locally.
- [ ] §5 audit lint passes.
- [ ] Per-platform docs pages written; `headless-send-vs-posse.md` explainer page written.
- [ ] No forbidden words anywhere.

## PR description template

```
### Phase G — G7 — Newsletter headless-send cluster

Adds four newsletter platforms as headless email-sending engines: Beehiiv, Buttondown, Kit, Mailchimp.

Distinct from G5 (POSSE-syndicate). G5 fans out copies of a published WP post; G7 turns a WP draft into an email send via an external platform, with optional WP publish before, after, or never. See `docs/concepts/headless-send-vs-posse.md` for the full distinction.

### Catalog reference

Phase G catalog §11, G7 entry. Detailed prompt: `outpost/docs/dev/prompts/G7-newsletter-headless-send.md`.

### Locked design decisions

- Sidebar plugin panel separate from POSSE panel (sibling, never nested).
- No auto-send on publish; always explicit user click + confirmation modal.
- Works on draft posts.
- Re-send protection blocks accidental double-send; override available via post meta.
- Beehiiv 402 and Kit 403 plan-gate errors get distinct dismissible notices with upgrade UI.

### Test plan

- 24+ tests across 4 targets, controller, transformer, REST endpoints.
- 3 wp-env stubs picked up.
- §5 audit lint clean.

### Merge order

Independent of G4. Shares some scaffolding with G5 (newsletter POSSE-outbound); if G5 merges first, expect minor merge conflict in the platform credential storage layer. Resolution: keep both scaffoldings; they're functionally distinct and the duplication is intentional for now (refactor candidate for Phase H).
```

## Open items

None for the locked decisions above. The following are deliberately deferred:

- **Beehiiv structured-blocks send path** (use HTML in v1; structured blocks Phase H)
- **Kit v3 deprecation timeline** (v4 OAuth is default for new installs; existing v3 users keep working)
- **Mailchimp template selection UI** (v1 sends as plain campaign; template selection Phase H)

If during implementation Claude Code finds the existing F-phase POSSE syndication code already abstracts platform credential storage in a reusable way, use that abstraction. Document the reuse in PR description under "Reuses F-phase abstractions".
