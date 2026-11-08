# OAuth foundation

Outpost speaks OAuth 2.0 authorization-code flow against per-provider endpoints. Each provider is a subclass of `Outpost_OAuth_Provider_Base` declaring its endpoints, scopes, and shape transformations.

## Components

- **`Outpost_OAuth_Provider_Base`** — abstract. Subclasses implement `id()`, `label()`, `authorize_url()`, `token_url()`, `revocation_endpoint()`, `client_id()`, `client_secret()`, `shape_credentials()`. Concrete behavior (state handling, code-for-token exchange, revocation, persistence) lives in the base.
- **`Outpost_OAuth_Controller`** — four REST routes under `outpost/v1`: `GET /oauth/{provider}/start`, `GET /oauth/{provider}/callback`, `POST /oauth/{provider}/disconnect`, and `GET /oauth/{provider}/verify`. Start, disconnect, and verify require a logged-in user with `manage_options` and refuse bearer-token requests. The callback's permission callback is `__return_true`, because the provider's redirect can't carry a REST nonce; the handler validates the state value before any side effect. No admin screen calls `/verify` today; it returns the provider's `verify_connection()` result, or an `outpost_oauth_verify_not_implemented` error for providers that don't override it.
- **`Outpost_OAuth_State`** — single-use 32-byte random state, base64url-encoded, 600-second TTL. Stored as a WordPress transient keyed on a hash of the state value, holding the user id and provider; deleted on validation (success or failure).
- **`Outpost_Credentials_Store`** — stores the resulting credentials under usermeta `outpost_creds_{provider}` (or the `outpost_creds_{provider}` option when the storage-scope filter switches to site-wide). Encrypts via `Outpost_Encryption`. Provides cheap presence-check via `is_configured()`.

## Why no `league/oauth2-client`

The original G3.5a plan called for `league/oauth2-client: ^2.7`. The dependency was dropped after measuring:

- The auth-code flow plus token POST is ~120 lines of focused PHP.
- League's value is multi-provider abstractions (PKCE, token refresh, multi-grant) that didn't apply when Notion was the only provider.
- Adding a Composer dep for one provider cost more in maintenance than it saved.

Outpost now ships six providers in `includes/oauth/providers/` (Notion, Oura, Polar, Ravelry, Ride with GPS, WHOOP) and still has no league dependency. The provider base's surface (endpoint URLs, scope set, header overrides, credential shape) is intentionally close to league's `AbstractProvider` so the migration is mechanical.

## Per-provider client credentials

Resolved from PHP constants. Site owners place these in `wp-config.php`:

```php
define( 'OUTPOST_NOTION_CLIENT_ID', 'xxx' );
define( 'OUTPOST_NOTION_CLIENT_SECRET', 'yyy' );
```

The provider's `client_id()` and `client_secret()` methods read the constants. Every provider follows the same naming: `OUTPOST_{PROVIDER}_CLIENT_ID`, `OUTPOST_{PROVIDER}_CLIENT_SECRET`.

## Redirect URI

Each provider's redirect URI is `rest_url( 'outpost/v1/oauth/{provider}/callback' )`. Site owners register this URL with the provider's app dashboard verbatim. Multi-site installs register one callback per site since `rest_url()` is per-site.

## State validation

State is single-use and time-bounded. Replay of the same state value on a second callback returns false even within the TTL — the validator clears the stored value before the comparison. Callbacks that fail validation redirect to the OAuth Connections page (Outpost → OAuth Connections) with `outpost_oauth_status=state_invalid`.

## Revocation

When the provider exposes RFC 7009 revocation, `disconnect()` posts the access token to that endpoint before deleting local credentials. Revocation transport failures log at debug level and do NOT block local deletion — disconnecting a misbehaving provider must always succeed locally.

Notion does not currently expose RFC 7009; its `revocation_endpoint()` returns null and `disconnect()` falls through to the local-delete path.

## What's NOT here

- Automatic token refresh. `Outpost_OAuth_Provider_Base::refresh_access_token()` exists, but nothing calls it, so Oura, Polar, WHOOP, and other providers that issue expiring tokens aren't refreshed. When a token expires, disconnect and reconnect the provider under Outpost → OAuth Connections. The Notion access token doesn't expire.
- PKCE. No provider sends a `code_challenge`; every provider uses the confidential-client flow with a client secret.
- Multi-account-per-user storage (current shape: one account per user per provider).
- Site-wide credential sharing (current shape: per-user; switchable via `outpost_credentials_storage_scope_{provider}` filter).
