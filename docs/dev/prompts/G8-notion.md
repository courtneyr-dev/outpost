---
title: "G8 — Notion outbound + inbound"
branch: phase-g/g8-notion
base: main
depends: []
phase: G
status: ready-for-implementation
---

# G8 — Notion outbound + inbound

## Scope

Add Notion as both a POSSE-outbound target (publish a WP post → create a page in user's Notion workspace) and an inbound capture source (capture a Notion page URL → fetch structured content for posting). Uses Notion's REST API with `Notion-Version: 2025-09-03` header.

## Files to create or modify

Create:

- `outpost/includes/adapters/class-notion-adapter.php` — `Outpost\Adapters\Notion_Adapter`
- `outpost/includes/notion/class-notion-oauth.php` — OAuth 2.0 flow handler
- `outpost/includes/notion/class-notion-blocks-converter.php` — WP blocks ↔ Notion blocks
- `outpost/tests/integration/adapters/test-notion-adapter.php`
- `outpost/tests/integration/notion/test-blocks-converter.php`
- `outpost/docs/adapters/notion.md`

## Design decisions locked

1. **Auth: OAuth 2.0 public integration.** Settings page has a "Connect Notion" button that initiates standard OAuth flow. Redirect URI is `outpost-notion-oauth-callback` REST endpoint.
2. **API version header:** `Notion-Version: 2025-09-03` on every request. Constant in plugin; updated only when Notion releases a new stable version.
3. **Outbound target selection:** User picks a target Notion database in settings ("Where should new pages be created?"). Database picker fetches via `POST /v1/search?filter[value]=database`. User must have shared the database with the integration (Notion's permission boundary).
4. **Outbound content shape:** WP post → Notion page with title from post title, body as Notion blocks (h1/h2/p/quote/code/ul/ol/img mapped from corresponding Gutenberg blocks). Featured image becomes page cover.
5. **Inbound trigger:** User pastes a Notion page URL into the composer. Adapter calls `GET /v1/pages/{id}` then `GET /v1/blocks/{id}/children` recursively. Blocks converted back to WP block format via the same converter (reverse direction).
6. **Permission boundary:** Notion integrations only see pages explicitly shared with them. Adapter does NOT attempt to access pages the user hasn't shared. Surface clear error when 404 returned: "This Notion page hasn't been shared with Outpost. In Notion, click ••• on the page → Add connections → Outpost."
7. **Webhooks:** Subscribe to page change events for shared pages. Webhook handler at `POST outpost/v1/g/notion/webhook`. Verify HMAC SHA-256 per Notion docs. Deferred enhancement: trigger inbound re-capture on page edit; v1 just logs the event.
8. **Post Kind suggestion for inbound:** `bookmark` by default; composer UI offers "use as quote" alternative.
9. **Cache:** 1-hour cache for fetched Notion page content keyed by page ID.

## Implementation outline

- Adapter implements both `Posse_Adapter_Base` (outbound) and `Inbound_Adapter` (inbound) interfaces.
- `Notion_Blocks_Converter::wp_to_notion( array $wp_blocks ): array` and `notion_to_wp( array $notion_blocks ): array` are mirror methods. Shared block-type mapping table.
- OAuth state parameter validated per OAuth 2.0 spec; never accept callbacks without matching state.

## Tests

- OAuth flow: state validation, code exchange, token storage.
- Outbound: WP post → Notion page; verify page created with correct title, body blocks, cover.
- Inbound: Notion URL → WP block content; verify blocks correctly reversed.
- Block converter round-trip: WP → Notion → WP preserves heading levels, links, images, lists.
- 404 on unshared page: returns specific error code.
- Notion API version header present on every outbound request.

### wp-env stubs

- `test_notion_outbound_creates_page`
- `test_notion_inbound_unshared_page_error`
- `test_notion_blocks_round_trip`

## Acceptance criteria

- [ ] OAuth flow complete end-to-end with mocked Notion server.
- [ ] Both directions of block conversion implemented and round-trip-tested.
- [ ] Permission-boundary error surfaces user-friendly message.
- [ ] Tests pass.
- [ ] §5 audit lint passes.
- [ ] Docs page written, including the "share page with integration" UX step.

## PR description template

```
### Phase G — G8 — Notion outbound + inbound

Adds Notion as bidirectional adapter. OAuth 2.0 public integration. WP blocks ↔ Notion blocks via reusable converter. Webhook subscription for shared-page change events (logged in v1; re-capture deferred).

Catalog reference: §11 G8 entry, §3 PKM table. Detailed prompt: `outpost/docs/dev/prompts/G8-notion.md`.

### Test plan

15+ tests including OAuth flow, both directions of block converter, permission boundary errors. 3 wp-env stubs picked up.

### Merge order

Independent.
```

## Open items

None.
