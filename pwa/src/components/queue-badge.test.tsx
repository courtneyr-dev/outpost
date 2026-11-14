import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import 'fake-indexeddb/auto';
import { IDBFactory } from 'fake-indexeddb';
import { render } from 'preact';
import { QueueBadge, QUEUE_RETRY_DELAYS_MS } from './queue-badge';
import { enqueue, list, remove, type OfflineQueueEnvironment } from '../lib/offline-queue';
import type { MicropubEnvironment } from '../lib/micropub';
import { write_token } from '../lib/token-store';

let root: HTMLDivElement;
let queueEnv: OfflineQueueEnvironment;

beforeEach(async () => {
	root = document.createElement('div');
	document.body.appendChild(root);
	queueEnv = { indexedDB: new IDBFactory() };
	// replay() reads the token fresh from token-store.ts; seed the default
	// (fake-indexeddb-polyfilled) store so a queued entry's replay can reach
	// the mock fetch in these tests instead of failing with no_token.
	await write_token({ accessToken: 'tk', tokenType: 'Bearer', scope: '', me: 'https://example.test/' });
});

afterEach(() => {
	render(null, root);
	root.remove();
	vi.useRealTimers();
});

function note(content: string): Parameters<typeof enqueue>[0] {
	return {
		source: 'note',
		properties: { content },
		micropubEndpoint: 'https://example.test/mp',
		me: 'https://example.test/',
	};
}

function site(answer: () => Response | Promise<Response>): MicropubEnvironment {
	return { fetch: (async (): Promise<Response> => answer()) as typeof fetch };
}

const offline = site(() => {
	throw new TypeError('Failed to fetch');
});

function created(): Response {
	return new Response('', { status: 201, headers: { Location: 'https://example.test/p/1' } });
}

function badge(): HTMLButtonElement | null {
	return root.querySelector('.outpost-queue-badge');
}

describe('QueueBadge', () => {
	it('shows a post queued after mount, without a reload', async () => {
		render(<QueueBadge owner="https://example.test/" queueEnv={queueEnv} micropubEnv={offline} />, root);
		await new Promise((resolve) => setTimeout(resolve, 10));
		expect(badge()).toBeNull();

		await enqueue(note('first'), queueEnv);
		await vi.waitFor(() =>
			expect(badge()?.getAttribute('aria-label')).toBe('1 queued post. Tap to inspect.'),
		);

		await enqueue(note('second'), queueEnv);
		await vi.waitFor(() =>
			expect(badge()?.querySelector('.outpost-queue-badge__count')?.textContent).toBe('2'),
		);
	});

	it('hides when another writer removes the last entry', async () => {
		const id = await enqueue(note('only'), queueEnv);
		render(<QueueBadge owner="https://example.test/" queueEnv={queueEnv} micropubEnv={offline} />, root);
		await vi.waitFor(() => expect(badge()).not.toBeNull());

		await remove(id, queueEnv);
		await vi.waitFor(() => expect(badge()).toBeNull());
	});

	it('replays on the online event and clears, even when the event lands mid-flush', async () => {
		await enqueue(note('queued'), queueEnv);
		let reachable = false;
		let posts = 0;
		const env = site(() => {
			if (!reachable) throw new TypeError('Failed to fetch');
			posts += 1;
			return created();
		});
		render(<QueueBadge owner="https://example.test/" queueEnv={queueEnv} micropubEnv={env} />, root);
		await vi.waitFor(() => expect(badge()).not.toBeNull());

		reachable = true;
		window.dispatchEvent(new Event('online'));
		await vi.waitFor(() => expect(badge()).toBeNull());
		expect(posts).toBe(1);
		expect(await list(queueEnv)).toHaveLength(0);
	});

	it('retries a server error on a backoff until the post goes through', async () => {
		vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
		await enqueue(note('retry me'), queueEnv);
		let posts = 0;
		const env = site(() => {
			posts += 1;
			return posts === 1 ? new Response('down', { status: 500 }) : created();
		});
		render(<QueueBadge owner="https://example.test/" queueEnv={queueEnv} micropubEnv={env} />, root);

		await vi.waitFor(() => expect(posts).toBe(1));
		await vi.waitFor(() => expect(vi.getTimerCount()).toBeGreaterThan(0));
		const [kept] = await list(queueEnv);
		expect(kept?.attempts).toBe(1);
		expect(kept?.retryable).toBe(true);
		expect(badge()).not.toBeNull();

		// vi.waitFor advances fake timers while it polls, so check at half the delay.
		await vi.advanceTimersByTimeAsync(QUEUE_RETRY_DELAYS_MS[0]! / 2);
		expect(posts).toBe(1);
		await vi.advanceTimersByTimeAsync(QUEUE_RETRY_DELAYS_MS[0]! / 2);
		await vi.waitFor(() => expect(badge()).toBeNull());
		expect(posts).toBe(2);
	});

	it('does not retry a 4xx on its own', async () => {
		vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
		await enqueue(note('rejected'), queueEnv);
		let posts = 0;
		const env = site(() => {
			posts += 1;
			return new Response('bad', { status: 400 });
		});
		render(<QueueBadge owner="https://example.test/" queueEnv={queueEnv} micropubEnv={env} />, root);
		await vi.waitFor(async () => expect((await list(queueEnv))[0]?.retryable).toBe(false));
		await vi.advanceTimersByTimeAsync(QUEUE_RETRY_DELAYS_MS.reduce((a, b) => a + b, 0));
		expect(posts).toBe(1);
		expect(badge()).not.toBeNull();
	});
});

describe('QueueBadge on a shared device (T10-S3-07)', () => {
	const ALICE = 'https://example.test/alice/';
	const BOB = 'https://example.test/bob/';

	function alice_note(content: string): Parameters<typeof enqueue>[0] {
		return { source: 'note', properties: { content }, micropubEndpoint: 'https://example.test/mp', me: ALICE };
	}

	it('shows Bob only a count of Alice’s queued posts, never their content, and sends nothing', async () => {
		await enqueue(alice_note('Alice private draft one'), queueEnv);
		await enqueue(alice_note('Alice private draft two'), queueEnv);
		await write_token({ accessToken: 'bob-token', tokenType: 'Bearer', scope: '', me: BOB });
		const before = await list(queueEnv);
		let requests = 0;
		const counting = site(() => {
			requests += 1;
			return created();
		});

		render(<QueueBadge owner="https://example.test/bob" queueEnv={queueEnv} micropubEnv={counting} />, root);
		await new Promise((resolve) => setTimeout(resolve, 30));

		expect(badge()).toBeNull();
		expect(root.querySelector('.outpost-queue-others')?.textContent).toBe(
			'2 queued posts on this device belong to another account or an older version of Outpost. They stay here and aren’t shown.',
		);
		expect(root.textContent).not.toContain('Alice');
		expect(requests).toBe(0);
		expect(await list(queueEnv)).toEqual(before);
	});

	it('shows Alice her own queued posts', async () => {
		await enqueue(alice_note('Alice private draft one'), queueEnv);
		await write_token({ accessToken: 'alice-token', tokenType: 'Bearer', scope: '', me: ALICE });

		render(<QueueBadge owner="https://example.test/alice" queueEnv={queueEnv} micropubEnv={offline} />, root);
		await new Promise((resolve) => setTimeout(resolve, 30));

		expect(badge()?.textContent).toContain('1');
		expect(root.querySelector('.outpost-queue-others')).toBeNull();
	});
});
