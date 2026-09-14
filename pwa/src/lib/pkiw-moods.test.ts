import { describe, it, expect, beforeEach } from 'vitest';
import en_us from '../../../tests/fixtures/pkiw/moods-en_US.json';
import en_gb from '../../../tests/fixtures/pkiw/moods-en_GB.json';
import {
	fetch_pkiw_moods,
	is_moods_response,
	mood_labels,
	read_cached_moods,
	write_cached_moods,
	MOODS_CACHE_KEY,
	type MoodsEnvironment,
} from './pkiw-moods';

interface Call {
	url: string;
	init: RequestInit | undefined;
}

function site(answer: () => Response | Promise<Response>, calls: Call[] = []): MoodsEnvironment {
	return {
		fetch: (async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
			calls.push({ url: String(input), init });
			return answer();
		}) as typeof fetch,
	};
}

function json(body: unknown, status = 200): Response {
	return new Response(JSON.stringify(body), {
		status,
		headers: { 'Content-Type': 'application/json' },
	});
}

beforeEach(() => {
	localStorage.clear();
});

describe('fetch_pkiw_moods: request', () => {
	it('POSTs to the PKIW moods route as GET with the token in the header and body, never the URL', async () => {
		const calls: Call[] = [];
		await fetch_pkiw_moods('secret-token', site(() => json(en_us), calls));

		expect(calls).toHaveLength(1);
		const [call] = calls;
		const url = new URL(call!.url, 'https://example.test');
		expect(url.pathname).toBe('/wp-json/post-kinds-indieweb/v1/moods');
		expect(url.searchParams.get('_method')).toBe('GET');
		expect(call!.url).not.toContain('secret-token');
		expect(call!.init?.method).toBe('POST');
		expect(call!.init?.credentials).toBe('omit');
		const headers = call!.init?.headers as Record<string, string>;
		expect(headers.Authorization).toBe('Bearer secret-token');
		expect(headers['Content-Type']).toBe('application/x-www-form-urlencoded');
		expect(new URLSearchParams(String(call!.init?.body)).get('access_token')).toBe('secret-token');
	});
});

describe('fetch_pkiw_moods: response contract', () => {
	it.each([
		['en_US', en_us],
		['en_GB', en_gb],
	])('accepts the %s response and returns it unchanged', async (_locale, fixture) => {
		const result = await fetch_pkiw_moods('tk', site(() => json(fixture)));
		expect(result).toEqual({ kind: 'ok', data: fixture });
	});

	it('the fixtures carry every documented key', () => {
		for (const fixture of [en_us, en_gb]) {
			expect(Object.keys(fixture).sort()).toEqual(['locale', 'moods', 'spelling', 'version']);
			for (const mood of fixture.moods) {
				expect(Object.keys(mood).sort()).toEqual(['key', 'label', 'variants']);
			}
		}
	});

	it.each(['spelling', 'locale', 'version', 'moods'])(
		'treats a response without `%s` as unsupported',
		async (key) => {
			const body: Record<string, unknown> = { ...en_us };
			delete body[key];
			expect(is_moods_response(body)).toBe(false);
			expect(await fetch_pkiw_moods('tk', site(() => json(body)))).toEqual({ kind: 'unsupported' });
		},
	);

	it.each(['key', 'label', 'variants'])(
		'treats a mood without `%s` as unsupported',
		async (key) => {
			const mood: Record<string, unknown> = { ...en_us.moods[0] };
			delete mood[key];
			const body = { ...en_us, moods: [mood, ...en_us.moods.slice(1)] };
			expect(await fetch_pkiw_moods('tk', site(() => json(body)))).toEqual({ kind: 'unsupported' });
		},
	);

	it('treats a non-JSON 200 as unsupported', async () => {
		const result = await fetch_pkiw_moods('tk', site(() => new Response('<html>', { status: 200 })));
		expect(result).toEqual({ kind: 'unsupported' });
	});
});

describe('fetch_pkiw_moods: failures', () => {
	it.each([
		[401, 'signed out / token rejected'],
		[403, 'user without edit_posts'],
		[404, 'Post Kinds without the moods route'],
	])('%i (%s) is unsupported', async (status) => {
		const result = await fetch_pkiw_moods(
			'tk',
			site(() => json({ code: 'rest_forbidden', message: 'x', data: { status } }, status)),
		);
		expect(result).toEqual({ kind: 'unsupported' });
	});

	it.each([500, 502, 429])('%i is unavailable, so a stored copy is kept', async (status) => {
		const result = await fetch_pkiw_moods('tk', site(() => json({}, status)));
		expect(result).toEqual({ kind: 'unavailable' });
	});

	it('a network failure is unavailable', async () => {
		const result = await fetch_pkiw_moods(
			'tk',
			site(() => {
				throw new TypeError('Failed to fetch');
			}),
		);
		expect(result).toEqual({ kind: 'unavailable' });
	});
});

describe('stored copy', () => {
	it('round-trips a response and clears it', () => {
		write_cached_moods(en_gb);
		expect(read_cached_moods()).toEqual(en_gb);
		write_cached_moods(null);
		expect(read_cached_moods()).toBeNull();
		expect(localStorage.getItem(MOODS_CACHE_KEY)).toBeNull();
	});

	it('ignores a corrupt or off-contract stored value', () => {
		localStorage.setItem(MOODS_CACHE_KEY, '{not json');
		expect(read_cached_moods()).toBeNull();
		localStorage.setItem(MOODS_CACHE_KEY, JSON.stringify({ moods: [] }));
		expect(read_cached_moods()).toBeNull();
	});
});

describe('mood_labels', () => {
	it('returns labels in the served order with the served spelling', () => {
		expect(mood_labels(en_us)).toEqual(en_us.moods.map((m) => m.label));
		expect(mood_labels(en_gb)).toEqual(en_gb.moods.map((m) => m.label));
		expect(mood_labels(en_gb)).toContain('Energised');
		expect(mood_labels(en_us)).toContain('Energized');
	});

	it('keeps a filtered-in site mood and drops empty or repeated labels', () => {
		const data = {
			...en_us,
			moods: [
				{ key: 'custom_site_mood', label: 'Frazzled but fine', variants: [] },
				{ key: 'blank', label: '', variants: [] },
				{ key: 'happy', label: 'Happy', variants: ['Happy'] },
				{ key: 'glad', label: 'Happy', variants: [] },
			],
		};
		expect(mood_labels(data)).toEqual(['Frazzled but fine', 'Happy']);
	});
});
