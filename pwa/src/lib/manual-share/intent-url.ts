/**
 * Intent URL builder — fills runtime placeholders in the server-supplied
 * `fallback_url`.
 *
 * The server (PHP `Outpost_Manual_Share_Intent_Payload_Builder`) does
 * most of the URL construction. It fills `@caption`, `@caption_encoded`,
 * `@source_url`, and `@image_url` placeholders server-side because those
 * values are knowable at request time. The PWA fills `@image_uri` at
 * the moment the user taps the chip — that placeholder represents an
 * Android content:// URI which only exists after the PWA has fetched
 * the file into a Blob and (on platforms that allow it) registered an
 * object URL.
 *
 * Reality check: Web PWAs cannot construct content:// URIs (those are
 * Android-internal). The `@image_uri` placeholder still gets a value
 * filled — the PWA passes its blob: object URL — but Android target
 * apps generally won't accept blob: URIs in EXTRA_STREAM. The intent://
 * URL path is therefore most useful for caption-only platforms (X,
 * Threads, Pinterest, Reddit, Tumblr, where `web_intent_url` is set
 * to a public https://...intent endpoint). The image-bearing path
 * relies on `navigator.share()` instead.
 *
 * The builder is intentionally minimal — string replacement plus URL
 * encoding. Tests cover all 5 placeholder variants.
 */

export interface IntentSubstitutions {
	/** `@caption` — raw caption text. */
	caption?: string;
	/** `@caption_encoded` — URL-encoded caption. Computed from caption when omitted. */
	caption_encoded?: string;
	/** `@source_url` — post permalink (already filled server-side; here for completeness). */
	source_url?: string;
	/** `@image_url` — public https URL of the primary image. */
	image_url?: string;
	/** `@image_uri` — runtime blob: object URL or content:// URI for the image. */
	image_uri?: string;
}

/**
 * Substitute placeholders in a URL template. Order is fixed
 * (longest token first) so `@caption_encoded` doesn't accidentally
 * match `@caption`.
 */
export function fill_intent_url(
	template: string,
	subs: IntentSubstitutions,
): string {
	const caption          = subs.caption ?? '';
	const caption_encoded  = subs.caption_encoded ?? encodeURIComponent( caption );
	const source_url       = subs.source_url ?? '';
	const image_url        = subs.image_url ?? '';
	const image_uri        = subs.image_uri ?? '';

	// Longest tokens first so `@caption_encoded` is not partially
	// matched as `@caption` + literal `_encoded`.
	const replacements: Array<[ string, string ]> = [
		[ '@caption_encoded', encodeURIComponent( caption_encoded === '' ? '' : caption_encoded ) === caption_encoded ? caption_encoded : caption_encoded ],
		[ '@source_url',      source_url ],
		[ '@image_url',       image_url ],
		[ '@image_uri',       image_uri ],
		[ '@caption',         caption ],
	];

	let out = template;
	for ( const [ token, value ] of replacements ) {
		out = out.split( token ).join( value );
		// The server URL-encodes the @-prefixed tokens themselves
		// (e.g. `%40caption_encoded`). Handle the encoded form too
		// so PWA-side fills work whether the server emitted raw or
		// encoded placeholders.
		const encoded_token = encodeURIComponent( token );
		out = out.split( encoded_token ).join( value );
	}
	return out;
}

/**
 * Whether a URL string has any of the known runtime placeholders.
 * Tests use this to assert PWA-side substitution actually fired.
 */
export function url_has_unfilled_placeholders( url: string ): boolean {
	const tokens = [
		'@caption_encoded',
		'@caption',
		'@source_url',
		'@image_url',
		'@image_uri',
	];
	for ( const token of tokens ) {
		if ( url.includes( token ) ) {
			return true;
		}
		if ( url.includes( encodeURIComponent( token ) ) ) {
			return true;
		}
	}
	return false;
}
