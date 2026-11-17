# Security policy

## Supported versions

Security fixes ship in the latest 1.0.x release. Update to the newest version before you report, and check whether the issue still reproduces there.

| Version | Supported |
| ------- | --------- |
| Latest 1.0.x | Yes |
| Earlier 1.0.x releases | No: update to the latest 1.0.x |
| < 1.0 | No |

## Reporting a vulnerability

If you discover a security vulnerability in Outpost, please report it responsibly.

**Do not open a public GitHub issue for security vulnerabilities.**

### How to report

Report privately through GitHub's private vulnerability reporting:

1. Open the repository's [Security tab](https://github.com/courtneyr-dev/outpost/security).
2. Select **Report a vulnerability**.
3. Fill in the form. Only you and the maintainer can see the report and the discussion that follows.

If you can't use GitHub, follow the [WordPress plugin security reporting process](https://developer.wordpress.org/plugins/wordpress-org/plugin-security/).

### What to include

- Description of the vulnerability.
- Steps to reproduce.
- Affected version(s).
- Potential impact.
- Whether the issue is exploitable remotely or requires authenticated access.

## Scope

The following classes of issue are in scope for security reports:

### High-priority surfaces

- **Server-side microformats2 preview** (`/wp-json/outpost/v1/preview`) — SSRF via the `url` parameter, including loopback IPs, private network ranges, redirects to internal hosts, oversized responses, and content-type confusion.
- **IndieAuth bearer token storage** — extraction of the encrypted IndexedDB token; cross-origin token leak; service worker exfiltration; missing scope enforcement.
- **Bookmarklet `url` query parameter** at the composer endpoint — injection of `javascript:`, `data:`, `file:`, internal IPs, or oversized URLs.
- **Photo upload pipeline** — bypass of MIME validation, EXIF / metadata leak, oversized uploads, malformed image payloads.
- **Service worker scope** — registration outside the `/post/` scope, fetch handlers reaching wp-admin endpoints, third-party hosts in `connect-src`.
- **REST endpoint authorization** — missing `permission_callback`, missing nonce checks on form-style endpoints, scope-less Micropub tokens accepted.

### Other in-scope issues

- Cross-site scripting (XSS) anywhere in the composer or admin UI.
- SQL injection in any `$wpdb` query.
- Privilege escalation in settings management.
- Any bypass of WordPress capability checks (`current_user_can()`).
- CSP bypasses on the `/post/` route.
- Rate-limit bypasses on the rate-limited REST endpoints under `/wp-json/outpost/v1/`: `preview` (30 requests a minute per user), `lookup` (30 a minute), `geocode` (20 a minute signed in, 5 signed out), `composer-config` (600 a minute), and `manual-share/snooze-all` (once every 5 minutes per user).

### Out of scope

- Issues in the Micropub, IndieAuth, or other companion plugins (report those upstream).
- Issues that require an attacker to already have administrator access.
- Self-XSS (issues that require the victim to paste attacker-controlled JavaScript into their own browser console).
- Reports against the WordPress.org-hosted demo install (when one exists).

## Response timeline

- **Acknowledgement:** within 48 hours.
- **Initial assessment:** within 1 week.
- **Fix release:** as soon as practical; typically within 2 weeks for critical issues.

## Coordinated disclosure

We follow coordinated disclosure. We ask reporters to give us a reasonable window to ship a fix before public disclosure (typically 90 days for medium-severity issues, sooner for actively exploited issues).
