# POSSE outbound base class

Outpost's POSSE-outbound foundation. Every direct-API syndication destination — Beehiiv, Buttondown, Kit, write.as in G5; Notion outbound, RWG, WHOOP outbound, and others in later phases — is a concrete subclass of `Outpost_POSSE_Destination_Base` registered with `Outpost_POSSE_Registry`. The dispatcher handles scheduling, retry, and post-meta accounting; destinations only make the API call.

## Components

- **`Outpost_POSSE_Destination_Base`** — abstract. Subclasses implement `id()`, `label()`, `provider_id()`, `dispatch( int $post_id ): array`. The base provides `get_credentials_for_user()` (loads from `Outpost_Credentials_Store` via the G3.5a OAuth foundation) plus `success_result()` / `failure_result()` helpers.
- **`Outpost_POSSE_Registry`** — static registry keyed by destination id. Concrete destinations register themselves on `init`. Filterable via `outpost_posse_destinations` for site-config overrides.
- **`Outpost_POSSE_Dispatcher`** — wp-cron-driven dispatcher. Hooks `transition_post_status` to schedule a single-event 30 seconds after publish, then handles retries with exponential backoff (30s → 5min → 30min) up to 3 attempts.
- **`Outpost_POSSE_Meta`** — post-meta accessor for the four canonical keys (`_outpost_posse_targets`, `_outpost_syndication_urls`, `_outpost_posse_failures`, `_outpost_posse_in_flight`). All four are registered via `register_post_meta` so the Gutenberg sidebar (G3.5c) reads/writes them via REST.

## Lifecycle

1. User publishes a post that has `_outpost_posse_targets` set (selected via G3.5c sidebar).
2. `transition_post_status` fires; dispatcher checks: new=='publish', old!=new (re-publish, not edit), publishing user has `publish_posts`, targets non-empty.
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
        return __( 'Beehiiv', 'outpost' );
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

All four are `register_post_meta`'d on `init` with `show_in_rest: true`, the auth callback gating writes on `edit_post` cap.
