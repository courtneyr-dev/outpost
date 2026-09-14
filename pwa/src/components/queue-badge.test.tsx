import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import 'fake-indexeddb/auto';
import { IDBFactory } from 'fake-indexeddb';
import { render } from 'preact';
import { QueueBadge, QUEUE_RETRY_DELAYS_MS } from './queue-badge';
import { enqueue, list, remove, type OfflineQueueEnvironment } from '../lib/offline-queue';
import type { MicropubEnvironment } from '../lib/micropub';

let root: HTMLDivElement;
let queueEnv: OfflineQueueEnvironment;

beforeEach(() => {
	root = document.createElement('div');
	document.body.appendChild(root);
	queueEnv = { indexedDB: new IDBFactory() };
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
		accessToken: 'tk',
		micropubEndpoint: 'https://example.test/mp',
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
		render(<QueueBadge queueEnv={queueEnv} micropubEnv={offline} />, root);
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
		render(<QueueBadge queueEnv={queueEnv} micropubEnv={offline} />, root);
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
		render(<QueueBadge queueEnv={queueEnv} micropubEnv={env} />, root);
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
		render(<QueueBadge queueEnv={queueEnv} micropubEnv={env} />, root);

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
		render(<QueueBadge queueEnv={queueEnv} micropubEnv={env} />, root);
		await vi.waitFor(async () => expect((await list(queueEnv))[0]?.retryable).toBe(false));
		await vi.advanceTimersByTimeAsync(QUEUE_RETRY_DELAYS_MS.reduce((a, b) => a + b, 0));
		expect(posts).toBe(1);
		expect(badge()).not.toBeNull();
	});
});
