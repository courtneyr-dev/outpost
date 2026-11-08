# POSSE outbound base class

Outpost's POSSE-outbound foundation. Every direct-API syndication destination (Beehiiv, Buttondown, Kit, and write.as, from G5) is a concrete subclass of `Outpost_POSSE_Destination_Base` registered with `Outpost_POSSE_Registry`. The dispatcher handles scheduling, retry, and post-meta accounting; destinations only make the API call.

## Components

- **`Outpost_POSSE_Destination_Base`** — abstract. Subclasses implement `id()`, `label()`, `provider_id()`, `dispatch( int $post_id ): array`. The base provides `get_credentials_for_user()` (loads from `Outpost_Credentials_Store` via the G3.5a OAuth foundation; returns null when `provider_id()` is `''`) plus `success_result()` / `failure_result()` helpers. The four shipped destinations return `''` from `provider_id()` and read their keys from the Outpost → Settings → API Keys tab through `Outpost_Settings_Handler::read_tab( 'api_keys' )` instead.
- **`Outpost_POSSE_Registry`** — static registry keyed by destination id. Concrete destinations register themselves on `init`. Filterable via `outpost_posse_destinations` for site-config overrides.
- **`Outpost_POSSE_Dispatcher`** — wp-cron-driven dispatcher. Hooks `transition_post_status` to schedule a single-event 30 seconds after publish, then handles retries with exponential backoff (30s → 5min → 30min) up to 3 attempts.
- **`Outpost_POSSE_Meta`** — post-meta accessor for the four canonical keys (`_outpost_posse_targets`, `_outpost_syndication_urls`, `_outpost_posse_failures`, `_outpost_posse_in_flight`). All four are registered via `register_post_meta` with `show_in_rest`, so REST clients can read and write them. The Gutenberg sidebar (G3.5c) doesn't read or write them yet.

## Lifecycle

1. User publishes a post that has `_outpost_posse_targets` set. No editor UI sets it yet; see [Choosing destinations](#choosing-destinations).
2. `transition_post_status` fires; dispatcher checks: new=='publish', old!=new (re-publish, not edit), the current user (when there is one) can `edit_post` this post, the post author has `publish_posts`, targets non-empty.
3. For each target not already in `_outpost_posse_in_flight`:
   - `outpost_posse_should_dispatch` filter veto check (default true; site owners can skip per-destination).
   - Add to in-flight (idempotency seal).
   - Schedule wp-cron event `outpost_posse_dispatch` at +30s with `[ post_id, destination_id, attempt = 1 ]`.
4. Cron fires → `handle_dispatch( $post_id, $destination_id, $attempt )`:
   - Resolve destination via registry; missing → record failure, exit.
   - Fire `outpost_posse_before_dispatch` action.
   - Call `$destination->dispatch( $post_id )`.
   - Fire `outpost_posse_after_dispatch` action with the result.
   - Route the result.

## Result shape

Every `dispatch()` returns:

```php
[
    'success'         => bool,
    'syndication_url' => ?string,  // Set on success; null otherwise.
    'error'           => ?string,
    'retryable'       => bool,     // True for 5xx / timeout. False for auth / 4xx.
]
```

- Success → `add_syndication_url()`, `remove_in_flight()`. Done.
- Non-retryable failure (or `attempt >= MAX_ATTEMPTS`) → `add_failure()`, `remove_in_flight()`. Done.
- Retryable + still under `MAX_ATTEMPTS` → schedule next attempt at the backoff timestamp.

## Backoff schedule

`Outpost_POSSE_Dispatcher::next_retry_timestamp( int $attempt ): int` — pure function on attempt number:

| Attempt | Delay |
|---------|-------|
| 1       | 30 seconds (initial schedule) |
| 2       | 5 minutes |
| 3       | 30 minutes |

After three attempts, the dispatcher records a permanent failure and the destination drops out of the in-flight list.

## Idempotency

- The in-flight list seals the destination at scheduling time, so re-publishing while a dispatch is mid-flight is a no-op.
- Edits to an already-published post don't re-fire (transition_post_status guard: `new === old` returns early).
- A genuine re-publish (draft → publish → draft → publish again) DOES re-fire — that's the user's intent.

## Filters and actions

- **Filter `outpost_posse_destinations`** (registry): accept `array<string,Outpost_POSSE_Destination_Base>` keyed by id; site owners can append, remove, or replace destinations.
- **Filter `outpost_posse_should_dispatch`** (dispatcher, per-destination): receives `(bool $should, int $post_id, string $destination_id)`. Returning false skips that destination for that post.
- **Action `outpost_posse_before_dispatch`** (`int $post_id, string $destination_id, int $attempt`): fires before each attempt. Useful for logging.
- **Action `outpost_posse_after_dispatch`** (`int $post_id, string $destination_id, int $attempt, array $result`): fires after each attempt regardless of outcome.

## Writing a destination

```php
final class Outpost_POSSE_Destination_Beehiiv extends Outpost_POSSE_Destination_Base {

    public function id(): string {
        return 'beehiiv';
    }

    public function label(): string {
        return __( 'Beehiiv', 'outpost-mobile-publishing' );
    }

    public function provider_id(): string {
        return 'beehiiv';
    }

    public function dispatch( int $post_id ): array {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return self::failure_result( 'Post not found.' );
        }
        $creds = $this->get_credentials_for_user( (int) $post->post_author );
        if ( null === $creds ) {
            return self::failure_result( 'No Beehiiv credentials for this user.' );
        }
        // ... POST to Beehiiv API ...
        // Return success_result( $beehiiv_post_url ) or failure_result( $msg, $retryable ).
    }
}

// Registration on init:
Outpost_POSSE_Registry::register( new Outpost_POSSE_Destination_Beehiiv() );
```

## Post-meta surface

The four canonical keys, all keyed by post id:

- **`_outpost_posse_targets`** (`string[]`) — destination ids the user selected.
- **`_outpost_syndication_urls`** (`array<{destination_id, url, posted_at}>`) — successful syndications. Renders as `u-syndication` mf2 markup downstream.
- **`_outpost_posse_failures`** (`array<{destination_id, error, attempted_at, attempt_count}>`) — permanent failures.
- **`_outpost_posse_in_flight`** (`string[]`) — currently scheduled or retrying.

All four are `register_post_meta`'d on `init` for the `post` post type with `show_in_rest`, the auth callback gating writes on `edit_post` cap.

Nothing in Outpost reads `_outpost_posse_failures` back: no admin notice, list-table column, or editor panel shows POSSE failures. Read the meta with `get_post_meta( $post_id, '_outpost_posse_failures', true )`, `wp post meta get <id> _outpost_posse_failures --format=json`, or `GET /wp-json/wp/v2/posts/<id>?context=edit`.

## Choosing destinations

The block editor has no destination selector. Set `_outpost_posse_targets` to a list of destination ids (`beehiiv`, `buttondown`, `kit`, `write-as`) before the post is published, through the core posts route. The request needs a user with `edit_post` on the post. Set `WP_USER` to your username and `WP_APP_PASSWORD` to an application password (Users → Profile → Application Passwords) first:

```bash
curl -X POST https://example.com/wp-json/wp/v2/posts/123 \
  -u "$WP_USER:$WP_APP_PASSWORD" \
  -H 'Content-Type: application/json' \
  -d '{"meta":{"_outpost_posse_targets":["beehiiv","buttondown"]}}'
```

Or with WP-CLI: `wp post meta update 123 _outpost_posse_targets '["beehiiv","buttondown"]' --format=json`. Each id passes through `sanitize_key()`. Each destination's own checkbox on the API Keys tab must also be on, or its dispatch fails with a "disabled in settings" error.
