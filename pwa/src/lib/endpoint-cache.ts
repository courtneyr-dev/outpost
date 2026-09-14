/**
 * Last-known Micropub and media endpoints, one record per signed-in "me" URL.
 *
 * Discovery needs the network, so a composer opened offline can't find the
 * endpoint on its own. Every successful discovery writes the result here;
 * the submit path and the offline-queue replay read it back when discovery
 * can't run. These are URLs the site advertises in its own public HTML, not
 * credentials, so localStorage is the right tier. The token stays in the
 * encrypted IndexedDB store.
 */

const PREFIX = 'outpost:endpoints:';

export interface KnownEndpoints {
	micropub: string | null;
	media: string | null;
}

function read(key: string): string | null {
	try {
		return globalThis.localStorage?.getItem(key) ?? null;
	} catch {
		// localStorage throws in private browsing and sandboxed iframes.
		return null;
	}
}

function as_url(value: unknown): string | null {
	return typeof value === 'string' && value !== '' ? value : null;
}

export function recall_endpoints(me: string): KnownEndpoints {
	const raw = read(PREFIX + me);
	if (!raw) return { micropub: null, media: null };
	try {
		const parsed = JSON.parse(raw) as { micropub?: unknown; media?: unknown };
		return { micropub: as_url(parsed.micropub), media: as_url(parsed.media) };
	} catch {
		return { micropub: null, media: null };
	}
}

export function remember_endpoints(
	me: string,
	found: { micropub?: string; media?: string },
): void {
	const next = { ...recall_endpoints(me), ...found };
	try {
		globalThis.localStorage?.setItem(PREFIX + me, JSON.stringify(next));
	} catch {
		// Storage full or blocked: the next submit discovers again.
	}
}
