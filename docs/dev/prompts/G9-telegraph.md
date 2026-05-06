---
title: "G9 — Telegraph outbound"
branch: phase-g/g9-telegraph
base: main
depends: []
phase: G
status: ready-for-implementation
---

# G9 — Telegraph outbound

## Scope

Add Telegraph (Telegram's anonymous publishing platform) as a POSSE-outbound target. No OAuth; Telegraph returns an `access_token` from `createAccount` that's stored per-user-per-WP-site for editing. Pages are immutable URLs; edits replace content but not URL.

## Files to create or modify

Create:

- `outpost/includes/adapters/class-telegraph-adapter.php`
- `outpost/tests/integration/adapters/test-telegraph-adapter.php`
- `outpost/docs/adapters/telegraph.md`

## Design decisions locked

1. **No OAuth.** First use calls `POST https://api.telegra.ph/createAccount` with `short_name`, `author_name`, `author_url` from settings. Returned `access_token` stored as encrypted option.
2. **Per-user account by default.** Each WP user gets their own Telegraph account, scoped by `outpost_telegraph_access_token_user_{user_id}`. Single-author sites can opt into a shared site-wide account via setting "Use shared Telegraph account".
3. **Outbound on publish.** Calls `POST /createPage` with title, author, content (Telegraph DOM nodes converted from WP blocks).
4. **Telegraph DOM conversion:** WP blocks → Telegraph node format (a JSON structure with `tag`, `attrs`, `children`). Limited subset: `p`, `aside`, `blockquote`, `code`, `pre`, `figure`, `img`, `iframe` (only YouTube/Vimeo/Twitter), `h3`, `h4`, `ul`, `ol`, `li`, `a`, `b`, `em`, `s`, `u`, `br`, `hr`. Anything else stripped or downgraded.
5. **Updates supported.** When user updates the WP post and "Update Telegraph copy" option is on, calls `POST /editPage` with stored access_token + page path.
6. **Canonical link injected.** Telegraph supports `author_url` per page. Set per-post to the WP post URL so Telegraph readers can find the canonical version.
7. **Pseudonymous publishing.** User can override `author_name` and `author_url` per-post via custom meta box. Useful for pseudonymous or anonymous sharing.
8. **No analytics.** Telegraph offers no view stats; do not promise analytics in UI.

## Implementation outline

- Adapter extends `Posse_Adapter_Base`.
- `convert_blocks_to_telegraph_dom( array $wp_blocks ): array` is the heart of it. Test thoroughly against Telegraph's documented limited tag set.
- `outpost_telegraph_post_url` post meta stores the resulting Telegraph URL.
- `outpost_telegraph_page_path` post meta stores the path needed for `editPage` calls.

## Tests

- Account creation on first use; stored token reused on second use.
- Block conversion: heading levels collapsed to h3/h4; unsupported blocks stripped; YouTube embed iframe preserved.
- Update path: `editPage` called with correct path on second publish.
- Pseudonymous override: custom author_name and author_url respected.

### wp-env stubs

- `test_telegraph_first_publish_creates_account`
- `test_telegraph_block_to_dom_conversion`

## Acceptance criteria

- [ ] First publish creates Telegraph account, stores token, publishes page.
- [ ] Subsequent publishes reuse stored token.
- [ ] Update workflow calls editPage correctly.
- [ ] Pseudonymous override works.
- [ ] Tests pass.
- [ ] §5 audit lint passes.
- [ ] Docs page documents the immutability of URLs and limited tag set.

## PR description template

```
### Phase G — G9 — Telegraph outbound

Adds Telegram's Telegraph publishing platform as POSSE-outbound target. Anonymous-by-default publishing; per-user access tokens; pseudonymous override per post. Limited block tag set documented.

Catalog reference: §11 G9 entry, §3 PKM table. Detailed prompt: `outpost/docs/dev/prompts/G9-telegraph.md`.

### Test plan

10+ tests covering account creation, block conversion, edit path, pseudonymous override. 2 wp-env stubs.

### Merge order

Independent.
```

## Open items

None.
