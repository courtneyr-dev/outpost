/**
 * URL validation utilities.
 *
 * Outpost's TypeScript surface treats user-input URLs (e.g. the `me` URL
 * from LoginScreen) and externally-supplied URLs (e.g. the Location
 * header from the Micropub endpoint's response) as untrusted. Before any
 * of these URLs reach `fetch()` or get rendered as `<a href>`, validate
 * they parse as well-formed http(s) — never `javascript:`, `data:`,
 * `file:`, `mailto:`, or other schemes.
 *
 * This is the JS-side analogue of the wordpress-security PHP guidance to
 * use `wp_safe_remote_get()` and validate URL schemes at every boundary.
 *
 * Same-origin XSS still wins (the attacker is already running JS in our
 * origin), but defense in depth here closes the supply-chain vectors:
 *   - user types a non-http URL by accident or under social engineering
 *   - Micropub endpoint returns a crafted Location header (compromised
 *     server or MitM)
 */

/**
 * Returns true when `value` parses as an http:// or https:// URL.
 *
 * Rejects: malformed strings, relative URLs (no scheme), `javascript:`,
 * `data:`, `file:`, `mailto:`, `ws:`, `wss:`, custom schemes.
 */
export function is_safe_http_url(value: string): boolean {
	let parsed: URL;
	try {
		parsed = new URL(value);
	} catch {
		return false;
	}
	return parsed.protocol === 'http:' || parsed.protocol === 'https:';
}
