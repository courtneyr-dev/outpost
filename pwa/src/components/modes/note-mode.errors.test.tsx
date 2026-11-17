import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import 'fake-indexeddb/auto';
import { render } from 'preact';
import { NoteMode } from './note-mode';
import type { StoredToken } from '../../lib/token-store';
import type { MicropubEnvironment } from '../../lib/micropub';

// Implementation brief section 7: "Differentiate ... 401/expired sign-in,
// 403/capability/nonce failures, and server failures", offer "the right retry
// or sign-in action while retaining the draft", and "keep diagnostics out of
// ordinary composer copy".

const ME = 'https://example.test/';
const DIAGNOSTIC =
	/post_h_entry|post_failed|micropub endpoint returned|fetch threw|insufficient_scope/;

const token: StoredToken = {
	accessToken: 'secret-bearer-value',
	tokenType: 'Bearer',
	scope: 'create',
	me: ME,
	storedAt: 0,
};

const BODIES: Record<number, () => Response> = {
	401: () =>
		Response.json(
			{ error: 'unauthorized', error_description: 'Unauthorized' },
			{ status: 401 }
		),
	403: () =>
		Response.json(
			{
				error: 'insufficient_scope',
				error_description: 'insufficient to create posts',
			},
			{ status: 403 }
		),
	500: () => new Response('Internal Server Error', { status: 500 }),
};

function failing_micropub(status: 401 | 403 | 500): MicropubEnvironment {
	return {
		fetch: async (
			_input: RequestInfo | URL,
			init?: RequestInit
		): Promise<Response> => {
			if (init?.method === 'POST') return BODIES[status]!();
			return new Response('<html></html>', {
				status: 200,
				headers: { Link: '<https://example.test/mp>; rel="micropub"' },
			});
		},
	};
}

let root: HTMLDivElement;

beforeEach(() => {
	localStorage.clear();
	root = document.createElement('div');
	document.body.appendChild(root);
	vi.spyOn(console, 'warn').mockImplementation(() => undefined);
});

afterEach(() => {
	render(null, root);
	root.remove();
	vi.restoreAllMocks();
});

async function submit_with(
	status: 401 | 403 | 500,
	onSignInExpired = vi.fn()
): Promise<{ text: string; onSignInExpired: ReturnType<typeof vi.fn> }> {
	render(
		<NoteMode
			token={token}
			micropubEnv={failing_micropub(status)}
			onSignInExpired={onSignInExpired}
		/>,
		root
	);
	// The default style is Article, which needs a title before it can post.
	const title = root.querySelector('#outpost-note-title') as HTMLInputElement;
	title.value = 'A title';
	title.dispatchEvent(new Event('input', { bubbles: true }));
	const body = root.querySelector(
		'#outpost-note-content'
	) as HTMLTextAreaElement;
	body.value = `typed before a ${String(status)}`;
	body.dispatchEvent(new Event('input', { bubbles: true }));
	await new Promise((resolve) => setTimeout(resolve, 0));
	(root.querySelector('form') as HTMLFormElement).dispatchEvent(
		new Event('submit', { bubbles: true, cancelable: true })
	);
	await vi.waitFor(() =>
		expect(
			root.querySelector('.outpost-error')?.textContent ?? ''
		).not.toBe('')
	);
	return {
		text: root.querySelector('.outpost-error')?.textContent ?? '',
		onSignInExpired,
	};
}

describe('NoteMode post failures', () => {
	it('shows a plain, different message for 401, 403 and 500 and keeps the text', async () => {
		const seen: string[] = [];
		for (const status of [401, 403, 500] as const) {
			const { text } = await submit_with(status);
			expect(text).not.toMatch(DIAGNOSTIC);
			expect(
				(
					root.querySelector(
						'#outpost-note-content'
					) as HTMLTextAreaElement
				).value
			).toBe(`typed before a ${String(status)}`);
			seen.push(text);
			render(null, root);
		}
		expect(new Set(seen).size).toBe(3);
	});

	it('asks for a fresh sign-in on a 401 only', async () => {
		const expired = await submit_with(401);
		expect(expired.onSignInExpired).toHaveBeenCalledTimes(1);
		render(null, root);
		for (const status of [403, 500] as const) {
			const other = await submit_with(status);
			expect(other.onSignInExpired).not.toHaveBeenCalled();
			render(null, root);
		}
	});

	it('logs the raw failure for debugging instead of showing it', async () => {
		await submit_with(403);
		expect(console.warn).toHaveBeenCalledWith(
			'Outpost: post failed',
			expect.anything()
		);
	});
});
