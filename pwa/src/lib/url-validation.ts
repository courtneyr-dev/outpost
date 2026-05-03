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

/**
 * Returns true when `value` is acceptable as an h-entry `location` property
 * value: either an http(s) URL or a `geo:lat,lon` URI per RFC 5870.
 *
 * Used by Checkin in Listen mode where the user can either point at a place
 * URL (Foursquare, OSM way page, etc.) OR drop in raw coordinates from the
 * geocode lookup. Both are spec-valid IndieWeb checkin location values.
 *
 * The `geo:` regex deliberately validates the structure tightly — `geo:1,1`
 * is fine, `geo:1` or `geo:javascript:alert(1)` are not. URL.protocol can't
 * be used for `geo:` because URL parses opaque URIs without slashes
 * inconsistently across engines.
 */
export function is_safe_location_value(value: string): boolean {
	if (is_safe_http_url(value)) return true;
	// geo:lat,lon[,alt] — RFC 5870 form. Outpost's `geo_uri()` only ever
	// emits this shape (no parameters), so we deliberately don't accept the
	// `;param=value` extensions. Stricter regex closes the smuggling vector
	// where a parameter value could carry hostile characters into a future
	// renderer that doesn't escape carefully.
	return /^geo:-?\d+(\.\d+)?,-?\d+(\.\d+)?(,-?\d+(\.\d+)?)?$/.test(value);
}
