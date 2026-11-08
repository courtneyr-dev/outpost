# Fetch-recent picker (G-fetch-recent-picker)

Composer primitive for "no shareable URL" platforms. Wellness providers (Oura, WHOOP, Polar Flow) don't expose URLs for individual workouts / sleep sessions, so users can't paste a URL the way they can for Notion or Ravelry. The picker fetches a list of recent items from the connected platform and lets the user pick one to insert into the post.

The primitive is the REST endpoint, provider registry, sidebar panel, modal, and a demo "test" provider. Three real providers register against it: Oura, WHOOP, and Polar Flow (`includes/sources/class-outpost-fetch-recent-{oura,whoop,polar}.php`; see [Oura fetch-recent](../adapters/oura-fetch-recent.md), [WHOOP fetch-recent](../adapters/whoop-fetch-recent.md), [Polar fetch-recent](../adapters/polar-fetch-recent.md)).

## Components

### PHP side

- **`Outpost_Fetch_Recent_REST`** — REST endpoint at `/outpost/v1/fetch-recent/<provider_id>?count=10` returning items as JSON. Plus a sibling list endpoint at `/outpost/v1/fetch-recent-providers` returning provider summaries (id + label + oauth_provider) for the sidebar panel to render buttons.
- **`Outpost_Fetch_Recent_Test_Provider`** — demo provider returning three hardcoded sample items. `outpost.php` registers it on every site, alongside the real providers, so every user with `edit_posts` sees a **Test (sample data)** button in the sidebar. Remove it with the `outpost_fetch_recent_providers` filter: `unset( $providers['test'] )`.

### JS side (Gutenberg sidebar)

- **`FetchRecentPanel`** — renders one button per registered provider inside the existing `OutpostSidebar` PluginSidebar (G3.5c). Fetches the provider list once on mount. The list endpoint filters providers only by each provider's `capability`, not by connection state, so a provider's button shows even when the user hasn't connected it.
- **`FetchRecentPickerModal`** — opens when a button is clicked; fetches provider items via REST; renders states (loading / error / not-connected / items / empty); inserts the chosen item's payload into the editor as a paragraph block on selection.
- **`DefaultItemRenderer`** — provider-agnostic Card-style item renderer with optional icon, title, subtitle, and relative-time footer. Custom renderers per provider override via the `outpost.fetchRecent.itemRenderer.<provider_id>` `wp.hooks` filter.

## Canonical fetch-recent item shape

Every provider's callback returns items in this shape:

```json
{
    "id": "string-unique-per-item",
    "title": "Workout - Running 5.2 miles",
    "subtitle": "2026-05-04, 32 minutes, 478 kcal",
    "icon_url": "optional-thumbnail-url-or-null",
    "fetched_at": "ISO 8601 timestamp",
    "post_kind": "workout",
    "post_payload": {
        "title": "post title to insert",
        "content": "post body content (HTML or block markup)",
        "post_meta": { "_outpost_workout_distance": "5.2 miles" },
        "syndication_source_url": "or null when the provider has no URL"
    }
}
```

`Outpost_Fetch_Recent_REST::normalize_item()` coerces partial entries into the canonical shape and drops malformed ones (missing `id` or `title`). The REST endpoint then caps to the requested `count` (1–50, default 10).

## Provider registration

```php
add_filter( 'outpost_fetch_recent_providers', function( $providers ) {
    $providers['oura'] = [
        'label'          => __( 'Oura', 'outpost-mobile-publishing' ),
        'callback'       => function( $count = 10 ) {
            // Returns array of canonical items.
        },
        'capability'     => 'edit_posts',
        'oauth_provider' => 'oura', // null when no auth required
    ];
    return $providers;
} );
```

When `oauth_provider` is set and the current user doesn't have a connection in `Outpost_Credentials_Store` (G3.5a), the REST endpoint returns a 200 with `reason: 'not_connected'`. The modal handles that gracefully — renders a "Connect first" notice rather than an error.

## REST endpoint shapes

### Success

```json
{
    "provider_id": "test",
    "items": [ /* canonical items */ ],
    "fetched_at": "2026-05-06T00:00:00+00:00"
}
```

### Not connected (200 + reason)

```json
{
    "provider_id": "oura",
    "items": [],
    "reason": "not_connected",
    "message": "Connect Oura in OAuth settings before using this picker."
}
```

### Auth failed (not returned)

`Outpost_Fetch_Recent_REST::REASON_AUTH_FAILED` (`'auth_failed'`) is defined, and the modal has a branch that renders it, but no code path returns it. When a provider's token is rejected, the Oura, WHOOP, and Polar callbacks return an empty list, and the modal shows "No recent items available."

### Transport failure (503)

```json
{
    "code": "transport_failed",
    "message": "Couldn't reach Oura right now: <error>"
}
```

The 200-with-reason pattern for a missing connection is intentional: it's a non-error UX state the modal handles gracefully (renders a Connect prompt instead of a list). Only true server / network errors return non-200.

## Insert flow

When the user picks an item, `FetchRecentPickerModal::handleSelect`:

1. Builds a `core/paragraph` block with `attributes.content = item.post_payload.content`.
2. Dispatches `core/block-editor`'s `insertBlocks` action.
3. If `post_payload.post_meta` is non-empty, dispatches `core/editor`'s `editPost` with the meta map.
4. Closes the modal.

The picker inserts a paragraph block, not a custom Outpost block, so `post_kind` and post meta don't render visually inside the editor.

## Custom item renderers

Each provider can supply its own React renderer via the `outpost.fetchRecent.itemRenderer.<provider_id>` filter:

```js
import { addFilter } from '@wordpress/hooks';

addFilter(
    'outpost.fetchRecent.itemRenderer.oura',
    'outpost/oura-renderer',
    () => OuraItemRenderer
);
```

If no filter is registered for a provider, `DefaultItemRenderer` runs.

## Stacking

This branch (`phase-g/fetch-recent-picker`) bases on `phase-g/g3.5c-gutenberg-sidebar`, NOT `main`. Per the FY-Theming locked decision #29 (stacked-PR merge sequencing), retarget this PR's base to `main` BEFORE the G3.5c branch is deleted after G3.5c merges. The cascade-prevention ritual is documented in CLAUDE.md.

## Shipped providers

The three providers this section once listed as follow-ups have shipped: Oura (workouts and sleep), WHOOP (cycles and recovery), and Polar Flow (training sessions). The picker primitive didn't change to support them; each is a provider config plus a callback that calls the upstream API and projects canonical items.
