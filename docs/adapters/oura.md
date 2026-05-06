# Oura adapter

G11a ships the Oura Ring OAuth provider. Reads from Oura's v2 API using the connected user's access token. Future PRs add the source class (URL detection + content fetch + composer integration).

## Setup

1. Register an OAuth application at [https://cloud.ouraring.com/oauth/applications](https://cloud.ouraring.com/oauth/applications) with redirect URI `https://your-site.example/wp-json/outpost/v1/oauth/oura/callback`.
2. Add credentials to `wp-config.php`:

   ```php
   define( 'OUTPOST_OURA_CLIENT_ID', 'xxx' );
   define( 'OUTPOST_OURA_CLIENT_SECRET', 'yyy' );
   ```

3. Visit Settings → OAuth Connections in wp-admin and click **Connect Oura**.
4. Authorize Outpost in Oura's UI; the redirect lands back on the settings page with a success notice.

## OAuth quirks Outpost handles

- **Standard OAuth 2.0** — authorize, callback with `code`, exchange for `{access_token, refresh_token, token_type, expires_in}`.
- **Tokens expire after ~24 hours.** The base provider's `is_expired()` + `refresh_access_token()` handle the refresh dance lazily on next data fetch.
- **RFC 7009 revocation** at `https://api.ouraring.com/oauth/revoke`. Disconnect calls revoke before deleting local credentials; failures fall through to local-delete only.

## Membership-gate handling

Oura's API returns HTTP 401 with a JSON body containing wording like `"detail": "expired_oura_membership"` (or any of the known phrasings — `membership_required`, `oura membership has lapsed`, `subscription required`) when the connected user's Oura Membership has lapsed for Gen3 hardware + Ring 4. This is **distinct from a token-expired error**.

`verify_connection()` detects this signature and returns `{ ok: false, reason: 'membership_required' }`. The settings UI surfaces a renewal prompt without dropping the connection — the OAuth token is still valid; only data access is gated.

`Outpost_OAuth_Provider_Oura::is_membership_gate_response( $body )` is the static detector and is exposed for tests; it's a substring match against the known phrasing list, intentionally generous so future Oura API copy revisions don't silently revert to `auth_failed`.

## Verify-connection endpoint

`GET /wp-json/outpost/v1/oauth/oura/verify` returns one of:

- `{ ok: true, email: "…" }` — token works, data access works
- `{ ok: false, reason: "no_credentials" }` — no creds stored
- `{ ok: false, reason: "membership_required" }` — token works, data is gated
- `{ ok: false, reason: "auth_failed", status: NNN }` — token rejected for non-membership reason
- `{ ok: false, reason: "transport_failed" }` — network error

Capability gate: `manage_options` + logged-in user (matches Connect/Disconnect).

## Storage scope filter

Per-user storage by default. Site-wide via `add_filter('outpost_credentials_storage_scope_oura', fn() => 'site')` — same precedent as G3.5a Notion. Personal Oura accounts almost always want per-user, so the default holds.

## What's NOT in G11a

- **Source class** for Oura URL pattern detection + content fetch + composer integration (deferred to a follow-up PR; Oura doesn't have shareable activity URLs, so the UX shape is "fetch recent activities" picker rather than URL paste).
- **Outbound** (Outpost → Oura) — not in scope; Oura's API is read-only for the consumer-facing endpoints.
- **WHOOP and Polar Flow** OAuth providers (deferred to a follow-up batch once Oura proves the wellness-platform pattern).
