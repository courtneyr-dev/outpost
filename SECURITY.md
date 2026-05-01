# Security Policy

## Supported Versions

| Version | Supported |
| ------- | --------- |
| 0.1.x   | Yes (pre-release) |

## Reporting a Vulnerability

If you discover a security vulnerability in Outpost, please report it responsibly.

**Do not open a public GitHub issue for security vulnerabilities.**

### How to Report

1. Email security concerns to the plugin author via [WordPress.org profile](https://profiles.wordpress.org/courane01/).
2. Use the [WordPress Plugin Security reporting process](https://developer.wordpress.org/plugins/wordpress-org/plugin-security/) for issues affecting WordPress.org users.

### What to Include

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
- Rate-limit bypasses on the preview, draft, or bridgy-suggest endpoints.

### Out of scope

- Issues in the Micropub, IndieAuth, or other companion plugins (report those upstream).
- Issues that require an attacker to already have administrator access.
- Self-XSS (issues that require the victim to paste attacker-controlled JavaScript into their own browser console).
- Reports against the WordPress.org-hosted demo install (when one exists).

## Response Timeline

- **Acknowledgement:** within 48 hours.
- **Initial assessment:** within 1 week.
- **Fix release:** as soon as practical; typically within 2 weeks for critical issues.

## Coordinated Disclosure

We follow coordinated disclosure. We ask reporters to give us a reasonable window to ship a fix before public disclosure (typically 90 days for medium-severity issues, sooner for actively exploited issues).
