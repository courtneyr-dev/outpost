# Encryption key

Outpost stores third-party credentials (OAuth access tokens, refresh tokens, API tokens) encrypted with XChaCha20-Poly1305 via libsodium. The 32-byte symmetric key is resolved per request from one of two sources, in priority order.

## Sources, in priority order

1. **`OUTPOST_ENCRYPTION_KEY` constant in `wp-config.php` (preferred).** 32 raw bytes. The key never lands in the database.

   ```php
   // Generate locally, then paste into wp-config.php:
   //   php -r "echo base64_encode(random_bytes(32));"
   define( 'OUTPOST_ENCRYPTION_KEY', base64_decode( 'paste-base64-here' ) );
   ```

2. **`outpost_encryption_key` wp_option (fallback).** Auto-generated and base64-stored on first use when the constant is unset. Triggers a persistent admin notice recommending migration to `wp-config.php`.

If neither is set, Outpost generates a key on first call to the credentials store and persists it as the wp_option fallback. Subsequent requests reuse the same key.

## Why the constant is preferred

- Database backups, migration tools, and shared hosting backups commonly include `wp_options` and exclude `wp-config.php`.
- A leaked database dump containing the key + the encrypted credential rows is a complete compromise.
- A leaked dump without the key forces an attacker to also exfiltrate the wp-config file separately.

The wp_options fallback exists so Outpost works out of the box for users who can't edit `wp-config.php` (managed-WP environments where wp-config is owned by the host). The admin notice keeps that posture visible and dismissable per-user, but the dismissal version-stamps so it re-appears on plugin upgrade — a periodic nudge to migrate.

## Rotation

Rotation is not implemented in G3.5a. Rotating today requires:

1. Decrypt every stored credential with the old key (programmatically, via `Outpost_Credentials_Store::get()` while old key is still resolvable).
2. Set the new key.
3. Re-store every credential.

Tracked as a follow-up; the credentials store's user-keyed layout makes per-user re-encryption tractable when this lands.

## Threat model

- **In scope:** ciphertext-at-rest tampering (auth tag fails on any byte flip), database-only leaks (can't decrypt without the key).
- **Out of scope:** same-server compromise (PHP can read both wp-config and the database; encryption raises the bar but doesn't defeat root). Memory-resident plaintext during request handling (any process inspecting PHP memory sees decrypted credentials).

The encryption is not a substitute for least-privilege host hardening. It's defense-in-depth for the most common leak vector: database-only exfiltration.
