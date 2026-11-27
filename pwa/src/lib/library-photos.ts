/**
 * Media Library reuse client.
 *
 * Talks to `/wp-json/outpost/v1/library-photos` (includes/class-library-photos-endpoint.php):
 * a body without `attachmentId` lists ready images, a body with one saves a
 * draft holding a core/image block for that attachment.
 *
 * The token travels only in the Authorization header, never in the body or
 * the URL. `credentials: 'omit'` keeps a logged-in wp-admin cookie from
 * turning the request into a cookie request that demands a nonce (OUT-001).
 */

export interface LibraryPhotosEnvironment {
	fetch: typeof fetch;
}

const default_env = (): LibraryPhotosEnvironment => ({
	fetch: globalThis.fetch.bind(globalThis),
});

const ENDPOINT = '/wp-json/outpost/v1/library-photos';

export interface LibraryImage {
	id: number;
	title: string;
	caption: string;
	/** The attachment's stored alt text. */
	alt: string;
	/** Alt WordPress read from the file's embedded IPTC metadata, or ''. */
	embeddedAlt: string;
	thumbnail: string | null;
	editUrl: string | null;
}

/** The author's alt decision. A decorative image carries no text. */
export type AltChoice = { kind: 'text'; alt: string } | { kind: 'decorative' };

export interface SavedLibraryDraft {
	id: number;
	editUrl: string | null;
}

export class LibraryPhotosError extends Error {
	constructor(
		message: string,
		public readonly status: number,
	) {
		super(message);
		this.name = 'LibraryPhotosError';
	}
}

async function post<T>(
	token: string,
	fields: Record<string, string>,
	env: LibraryPhotosEnvironment,
): Promise<T> {
	const response = await env.fetch(ENDPOINT, {
		method: 'POST',
		credentials: 'omit',
		redirect: 'error',
		headers: {
			Authorization: 'Bearer ' + token,
			'Content-Type': 'application/x-www-form-urlencoded',
			Accept: 'application/json',
		},
		body: new URLSearchParams(fields).toString(),
	});
	if (!response.ok) {
		throw new LibraryPhotosError('WordPress returned ' + String(response.status) + '.', response.status);
	}
	return (await response.json()) as T;
}

function str(value: unknown): string {
	return typeof value === 'string' ? value : '';
}

function to_image(row: unknown): LibraryImage | null {
	const r = (row ?? {}) as Record<string, unknown>;
	if (typeof r['id'] !== 'number' || r['id'] <= 0) return null;
	return {
		id: r['id'],
		title: str(r['title']),
		caption: str(r['caption']),
		alt: str(r['alt']),
		embeddedAlt: str(r['embeddedAlt']),
		thumbnail: str(r['thumbnail']) || null,
		editUrl: str(r['editUrl']) || null,
	};
}

export async function list_library_photos(
	token: string,
	search: string,
	env: LibraryPhotosEnvironment = default_env(),
): Promise<LibraryImage[]> {
	const json = await post<{ items?: unknown }>(token, { search }, env);
	const rows = Array.isArray(json.items) ? json.items : [];
	return rows.map(to_image).filter((image): image is LibraryImage => image !== null);
}

export async function save_library_photo_draft(
	token: string,
	attachment_id: number,
	choice: AltChoice,
	env: LibraryPhotosEnvironment = default_env(),
): Promise<SavedLibraryDraft> {
	const fields: Record<string, string> =
		choice.kind === 'decorative'
			? { attachmentId: String(attachment_id), alt: '', decorative: 'true' }
			: { attachmentId: String(attachment_id), alt: choice.alt, decorative: 'false' };
	const json = await post<{ id?: unknown; editUrl?: unknown }>(token, fields, env);
	if (typeof json.id !== 'number') {
		throw new LibraryPhotosError('WordPress did not return the draft.', 500);
	}
	return { id: json.id, editUrl: str(json.editUrl) || null };
}
