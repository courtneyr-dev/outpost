# Polar Flow adapter

G11c ships the Polar Flow OAuth provider via Polar AccessLink v3. Reads from AccessLink for the connected user's training, sleep, and recovery data. Future PRs add the source class.

Completes the wellness OAuth cluster (G11a Oura + G11b WHOOP + G11c Polar).

## Setup

1. Register an OAuth application at [https://admin.polaraccesslink.com](https://admin.polaraccesslink.com) with redirect URI `https://your-site.example/wp-json/outpost/v1/oauth/polar/callback`.
2. Add credentials to `wp-config.php`:

   ```php
   define( 'OUTPOST_POLAR_CLIENT_ID', 'xxx' );
   define( 'OUTPOST_POLAR_CLIENT_SECRET', 'yyy' );
   ```

3. Visit Settings → OAuth Connections in wp-admin and click **Connect Polar Flow**.

## OAuth quirks Outpost handles

- **Different host for authorize vs token.** Authorize at `flow.polar.com`; token at `polarremote.com`. Polar-specific cross-origin shape — Outpost's provider hardcodes both URLs correctly.
- **Single scope: `accesslink.read_all`.** AccessLink doesn't subdivide scopes by data domain; one scope grants all read access.
- **RFC 7009 revocation.** `https://polarremote.com/v2/oauth2/revoke` — inherited base `disconnect()` handles this with the standard token + client_id + client_secret POST.
- **Long-lived tokens with refresh support.** Inherited `is_expired()` + `refresh_access_token()` handle the dance lazily.

## The AccessLink "register user with app" quirk

After OAuth code exchange completes, AccessLink requires an extra step before any data API call works: `POST /v3/users` with the access token to register the user with the application. Without it, every data endpoint returns 404 "user not registered with app".

Outpost handles this via the new `after_token_exchange()` hook on `Outpost_OAuth_Provider_Base` (added by this PR — Polar is its first consumer). The hook fires after standard OAuth completes AND credentials persist:

- `200` / `201` — registered, success.
- `409` — already registered (idempotent retry treated as success).
- `4xx` other than `409` — log debug warning; leave creds in place. The user can retry registration later.
- Network failure — log debug warning; leave creds in place.

**Failures inside `after_token_exchange()` MUST NOT abort the connect flow** — credentials are already stored. Per the base class contract.

### Auto-recovery via verify

If the registration step failed silently (e.g., transient network blip), the `verify_connection()` endpoint detects the resulting 404 from `GET /v3/users/{member-id}` and **automatically retries the registration once** before reporting failure. If the retry succeeds, verify returns `ok`. If the retry fails too, verify returns `user_not_registered_with_app` and the user can reconnect from scratch.

## Verify-connection endpoint

`GET /wp-json/outpost/v1/oauth/polar/verify` returns one of:

- `{ ok: true, first_name: "…", member_id: "…" }` — token works, user registered, profile fetched
- `{ ok: false, reason: "no_credentials" }` — no creds stored
- `{ ok: false, reason: "auth_failed", status: 401 }` — token rejected
- `{ ok: false, reason: "user_not_registered_with_app" }` — registration step failed AND auto-retry also failed
- `{ ok: false, reason: "transport_failed" }` — network error

Capability gate: `manage_options` + logged-in user (matches Connect/Disconnect).

## Member-id mapping

AccessLink's `member-id` field is "any string the app chooses." Outpost uses the WP `user_id` directly so the AccessLink registration is keyed to the same user the credentials are stored under. Future Polar source-class work that calls `/v3/users/{member-id}/exercises` etc. uses the same ID.

## Storage scope filter

Per-user storage by default. Site-wide via `add_filter('outpost_credentials_storage_scope_polar', fn() => 'site')` — same precedent as G3.5a Notion.

## What's NOT in G11c

- **Source class** for Polar Flow. Different UX shape than URL-paste platforms — Polar doesn't have shareable activity URLs. The right share path is a "fetch recent training/sleep" picker. UX call deserves its own sketch conversation; deferred.
- **Outbound** (Outpost → Polar) — out of scope; AccessLink is read-only for the consumer-facing endpoints.
- **Webhooks** — Polar can webhook on new exercises; future PR if needed.
- **Push notifications.** AccessLink supports webhooks for new data; not in this batch.

## Base class extension shipped here

This PR adds `after_token_exchange( int $user_id, array $creds ): void` to `Outpost_OAuth_Provider_Base` (default no-op). It's the second base-class extension after the wellness-completion bumps in G11a/G12a/G14b/G8b/G11b. Future providers that need post-exchange registration / activation steps override the hook the same way Polar does.
