import { describe, expect, it, vi } from 'vitest';
import { list_library_photos, save_library_photo_draft, LibraryPhotosError } from './library-photos';

function env_returning(status: number, body: unknown) {
	const fetch = vi.fn(async () => new Response(JSON.stringify(body), { status }));
	return { env: { fetch: fetch as unknown as typeof globalThis.fetch }, fetch };
}

function sent(fetch: ReturnType<typeof vi.fn>): { url: string; init: RequestInit; body: URLSearchParams } {
	const [url, init] = fetch.mock.calls[0] as [string, RequestInit];
	return { url, init, body: new URLSearchParams(String(init.body)) };
}

describe('library photos client', () => {
	it('sends the token only in the Authorization header', async () => {
		const { env, fetch } = env_returning(200, { items: [] });
		await list_library_photos('secret-token', 'cercle', env);

		const { url, init, body } = sent(fetch);
		expect(url).toBe('/wp-json/outpost/v1/library-photos');
		expect(url).not.toContain('secret-token');
		expect(init.method).toBe('POST');
		expect(init.credentials).toBe('omit');
		expect((init.headers as Record<string, string>)['Authorization']).toBe('Bearer secret-token');
		expect(body.get('search')).toBe('cercle');
		expect(body.has('access_token')).toBe(false);
		expect(String(init.body)).not.toContain('secret-token');
	});

	it('keeps embedded alt apart from the stored alt and drops malformed rows', async () => {
		const { env } = env_returning(200, {
			items: [
				{ id: 5, title: 'Fixture', caption: 'Original fixture caption', alt: '', embeddedAlt: 'Un cercle jaune sur fond bleu', thumbnail: null, editUrl: '/wp-admin/post.php?post=5&action=edit' },
				{ id: 'x' },
			],
		});
		expect(await list_library_photos('t', '', env)).toEqual([
			{ id: 5, title: 'Fixture', caption: 'Original fixture caption', alt: '', embeddedAlt: 'Un cercle jaune sur fond bleu', thumbnail: null, editUrl: '/wp-admin/post.php?post=5&action=edit' },
		]);
	});

	it('saves described text literally', async () => {
		const { env, fetch } = env_returning(201, { id: 77, editUrl: 'http://localhost/wp-admin/post.php?post=77&action=edit' });
		const draft = await save_library_photo_draft('t', 5, { kind: 'text', alt: 'Un cercle jaune à côté' }, env);

		const { body } = sent(fetch);
		expect(body.get('attachmentId')).toBe('5');
		expect(body.get('alt')).toBe('Un cercle jaune à côté');
		expect(body.get('decorative')).toBe('false');
		expect(draft).toEqual({ id: 77, editUrl: 'http://localhost/wp-admin/post.php?post=77&action=edit' });
	});

	it('saves a decorative choice as an explicit empty alt', async () => {
		const { env, fetch } = env_returning(201, { id: 78, editUrl: null });
		await save_library_photo_draft('t', 5, { kind: 'decorative' }, env);

		const { body } = sent(fetch);
		expect(body.get('alt')).toBe('');
		expect(body.get('decorative')).toBe('true');
	});

	it('reports a refused attachment', async () => {
		const { env } = env_returning(403, { code: 'outpost_library_image_unavailable' });
		await expect(save_library_photo_draft('t', 5, { kind: 'decorative' }, env)).rejects.toBeInstanceOf(LibraryPhotosError);
	});
});
