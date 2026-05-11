# Notion preview UX for disconnected and anonymous users

**Issue:** [#69](https://github.com/courtneyr-dev/outpost/issues/69)
**Decided:** 2026-05-11
**Decider:** Courtney Robertson
**Status:** Accepted

## Context

When a user shares a Notion URL to Outpost via the Web Share Target API or the
preview endpoint, the source dispatch declares `extractor => 'api_json'`. The
api_json extractor requires Notion API authentication via an OAuth token on
file for the current user.

Three user states exist:

- **Authenticated users with Notion connected:** API extraction works. Preview
  shows full page metadata, blocks, properties.
- **Authenticated users without Notion connected:** API call fails because no
  token exists. Extractor throws `Extractor_Not_Implemented_Exception`. Preview
  returns HTTP 501.
- **Anonymous users:** No session, no token. Same 501 outcome.

The 501 response is technically honest but produces a poor sharing experience.
Three integration test stubs for Notion preview behavior were deferred from
PR #68 because the underlying code path returns 501 for these states.

## Decision

For disconnected and anonymous users, Outpost falls back to **public OG tag
extraction** instead of returning 501. Specifically:

- Source dispatch detects the user's connection state for Notion before
  attempting api_json
- If the user lacks a Notion connection, dispatch switches to the `og_tags`
  extractor (the same one used for arbitrary public URLs)
- The og_tags extractor reads `og:title`, `og:description`, `og:image`, and
  related public metadata from the rendered HTML
- Preview returns 200 with whatever public metadata exists
- For private Notion pages (which serve a placeholder page to unauthenticated
  fetches), og_tags will return what Notion exposes publicly — typically just
  a generic Notion title
- For public Notion pages, og_tags returns useful metadata for sharing

## Reasoning

Three observations drove the decision.

**Who actually shares Notion URLs to Outpost.** Probably mostly users who already
use Notion, but some who don't. Building a sharing experience that only works
for connected users punishes the second group without serving the first group
any better. The IndieWeb-spirited answer is to treat the public web as the
public web — a Notion URL is just a URL, and the basic act of sharing it
shouldn't require account authorization.

**What "preview" means when the API path is blocked.** For a public Notion
page, og_tags fallback yields the title and a usable description. That's
genuinely useful for a sharing flow. For a private page, even Notion's OAuth
wouldn't help an anonymous user — they can't read someone else's private page
regardless. The fallback degrades gracefully in both cases: useful for public,
generic-but-honest for private.

**Auth-required basic sharing is the wrong default.** Outpost's job is to help
users POSSE content to their site. Requiring third-party OAuth before basic
sharing inverts the relationship — it makes Outpost dependent on Notion's
permission model for an action that doesn't fundamentally need Notion's
participation. The IndieWeb principles favor user agency over platform
dependency.

## What this changes

**Three deferred Notion stubs gain a product target.** PR #68 closed three
stubs that exercise the 501 path. With og_tags fallback as the new product
behavior, those stubs have a concrete shape to assert against. End-to-end
execution of those stubs still requires the REWRITABLE_HOSTS /
`http_request_host_is_external` test-infra fix tracked as gotcha #4 in PR #68's
close-out — this decision unblocks the *product* dimension; the test-infra
dimension remains separately tracked.

**The api_json extractor remains the preferred path** for users with Notion
connected. Better metadata, structured blocks, properties. Connection state
detection determines which path runs.

**Connection state detection becomes a shared primitive.** This pattern likely
applies to other API-required sources too (Strava, Polar, Oura, etc.). The
"check connection, fall back to og_tags if absent" logic should live alongside
the source registry as a helper any api_json-declaring source can opt into via
configuration.

## Implementation scope

This decision lives at the source registration + extractor selection layer.
Outpost has no `Source_Dispatch` class today; extractor lookup runs through
`Outpost_Source_Registry::get_extractor()` (a switch on `extractor_id` at
`includes/sources/class-source-registry.php`) with each source declaring its
preferred extractor in registration. Sketch of changes:

- `Outpost_Source_Notion` — declare an og_tags fallback alongside its current
  `extractor => 'api_json'` registration, and a connection-state check helper
  that flips the active extractor when no Notion token is on file.
- `Outpost_Source_Registry::get_extractor()` call sites — honor the per-source
  fallback when the primary extractor reports unavailable for the current user.
- `tests/integration/NotionShareTargetPreviewTest.php` — the three deferred
  test methods (`disconnected_user_falls_through_to_og_title`,
  `anonymous_request_falls_through_to_og_title`,
  `notion_transport_failure_falls_through`) become the *product target* for
  og_tags fallback assertions. End-to-end exercise of those tests also requires
  the REWRITABLE_HOSTS / `http_request_host_is_external` test-infra fix from
  gotcha #4 — that is a separate test-infra unblock, not part of this
  decision's scope.

No production code change to the og_tags extractor itself; it already exists
and works for generic URLs.

## Decision NOT made here

**Apple Music canonical-host expansion** (separate decision, separate doc).
Apple Music's blocked status is about REWRITABLE_HOSTS allowlist, not about
auth state — different problem, different solution surface.

**Other api_json sources' connection state handling.** Strava, Polar, Oura,
WHOOP all have similar API-required dispatch. They might benefit from the same
fallback pattern, but each has different public-metadata availability and each
deserves its own consideration. This decision is Notion-scoped; broader
application is future work.

**Notion API rate limit handling.** A user with Notion connected but hitting
rate limits will still see a different failure mode. That's a separate concern
about resilience, not about disconnected-user UX.

## References

- Issue: #69
- PR that surfaced the problem: #68 (closed)
- Three deferred test methods in `tests/integration/NotionShareTargetPreviewTest.php`:
  `disconnected_user_falls_through_to_og_title`,
  `anonymous_request_falls_through_to_og_title`,
  `notion_transport_failure_falls_through`
- Source registration: `includes/sources/class-outpost-source-notion.php`
- Extractor registry: `includes/sources/extractors/` (resolution lives at
  `includes/sources/class-source-registry.php`, method `get_extractor()`)
- Test-infra prereq for end-to-end: REWRITABLE_HOSTS gotcha #4 (PR #68
  close-out)
