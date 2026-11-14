/**
 * The exact account a token or a queued post belongs to (T10-S3-07).
 *
 * An IndieAuth `me` URL names one person. Two paths on one site are two
 * people (https://example.test/alice/ and https://example.test/bob/), and so
 * are two schemes or two ports on one host. Ownership of device-local work
 * (the offline queue) compares accounts in this one canonical form, never by
 * origin.
 *
 * Canonical form: an absolute http(s) URL with no user info, parsed by the
 * platform URL parser (which lowercases the scheme and host, drops a default
 * port, and normalizes percent-encoding and dot segments), with the fragment
 * removed, the query kept, and a trailing slash removed from any path other
 * than `/`. So https://Example.TEST:443/alice/#me and
 * https://example.test/alice name the same account.
 *
 * This is stricter than the IndieAuth callback's `me_origins_match()`, which
 * only checks that the authorization server didn't move the sign-in to a
 * different site; that check is unchanged.
 */
export function canonical_account(me: unknown): string | null {
	if (typeof me !== 'string' || me === '') return null;
	let url: URL;
	try {
		url = new URL(me);
	} catch {
		return null;
	}
	if (url.protocol !== 'http:' && url.protocol !== 'https:') return null;
	if (url.username !== '' || url.password !== '') return null;
	let path = url.pathname === '' ? '/' : url.pathname;
	if (path.length > 1 && path.endsWith('/')) path = path.replace(/\/+$/, '') || '/';
	path = decode_unreserved(path);
	return url.protocol + '//' + url.host + path + url.search;
}

/**
 * Decode percent-escapes of unreserved characters (RFC 3986 §6.2.2.2), which
 * the URL parser leaves encoded: `%7E` and `~` are the same path.
 */
function decode_unreserved(path: string): string {
	return path.replace(/%([0-9A-Fa-f]{2})/g, (escape, hex: string) => {
		const char = String.fromCharCode(parseInt(hex, 16));
		return /[A-Za-z0-9\-._~]/.test(char) ? char : escape.toUpperCase();
	});
}
