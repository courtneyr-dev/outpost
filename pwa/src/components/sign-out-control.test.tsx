/**
 * Signing out with queued posts (T10-S3-07 v1.1).
 *
 * The sign-in (a token in one IndexedDB database) and the queue (another
 * database) can't change in one transaction, so the control orders them:
 * the sign-in is always cleared first, and queued posts are only removed
 * after that succeeded. Every step's outcome is reported before the page
 * reloads:
 *
 * - token clearing fails → nothing else happens; the person is still
 *   signed in and told so;
 * - "Keep queued posts on this device" never writes the queue;
 * - "Remove my queued posts and sign out" deletes only this account's
 *   posts, after the sign-in is gone; if that deletion fails, the person is
 *   told they're signed out but the posts are still here, and can retry the
 *   removal (it needs no token) or finish signing out and keep them.
 *
 * Other accounts' posts and locked older ones survive every path.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import 'fake-indexeddb/auto';
import { IDBFactory } from 'fake-indexeddb';
import { render } from 'preact';
import { SignOutControl } from './sign-out-control';
import { enqueue, list, type OfflineQueueEnvironment } from '../lib/offline-queue';

const ALICE = 'https://example.test/alice/';
const BOB = 'https://example.test/bob/';
const ALICE_ID = 'https://example.test/alice';

const SIGN_IN_NOT_CLEARED =
	'Couldn’t remove the sign-in from this device, so you’re still signed in and nothing was removed. Try again.';
const PARTIAL =
	'You’re signed out of this device, but your queued posts couldn’t be removed and are still here. Try removing them again, or finish signing out and keep them.';

let root: HTMLDivElement;
let real: IDBFactory;
/** Order of the steps that change state: 'token', 'queue-write', 'finish'. */
let steps: string[];
let token_fails: boolean;
let queue_writes_fail: boolean;
let clear_sign_in: ReturnType<typeof vi.fn>;
let finish_sign_out: ReturnType<typeof vi.fn>;

/** The real queue database, recording read-write transactions and failing them on demand. */
function queue_env(): OfflineQueueEnvironment {
	return {
		indexedDB: {
			open(name: string, version?: number): IDBOpenDBRequest {
				const req = real.open(name, version);
				req.addEventListener('success', () => {
					const db = req.result;
					const transaction = db.transaction.bind(db);
					db.transaction = ((stores: string | string[], mode?: IDBTransactionMode) => {
						if (mode === 'readwrite') {
							steps.push('queue-write');
							if (queue_writes_fail) throw new DOMException('write blocked', 'UnknownError');
						}
						return transaction(stores, mode);
					}) as IDBDatabase['transaction'];
				});
				return req;
			},
		} as unknown as IDBFactory,
	};
}

beforeEach(() => {
	root = document.createElement('div');
	document.body.appendChild(root);
	real = new IDBFactory();
	steps = [];
	token_fails = false;
	queue_writes_fail = false;
	clear_sign_in = vi.fn(async (): Promise<void> => {
		steps.push('token');
		if (token_fails) throw new DOMException('token store blocked', 'UnknownError');
	});
	finish_sign_out = vi.fn((): void => {
		steps.push('finish');
	});
});

afterEach(() => {
	render(null, root);
	root.remove();
});

function note(me: string, content: string): Parameters<typeof enqueue>[0] {
	return { source: 'note', properties: { content }, micropubEndpoint: 'https://example.test/mp', me };
}

async function seed(...rows: Array<[string, string]>): Promise<void> {
	for (const [me, content] of rows) await enqueue(note(me, content), { indexedDB: real });
	steps = [];
}

function mount(env: OfflineQueueEnvironment = queue_env()): void {
	render(
		<SignOutControl owner={ALICE_ID} queueEnv={env} clearSignIn={clear_sign_in} finishSignOut={finish_sign_out} />,
		root,
	);
}

function button(name: string): HTMLButtonElement | null {
	return (Array.from(root.querySelectorAll('button')).find((b) => (b.textContent ?? '').trim() === name) ?? null) as HTMLButtonElement | null;
}

async function click(name: string): Promise<void> {
	const b = button(name);
	expect(b, `button "${name}"`).not.toBeNull();
	b?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
	await new Promise((resolve) => setTimeout(resolve, 40));
}

function status(): string {
	return root.querySelector('[role="status"]')?.textContent ?? '';
}

async function contents(): Promise<string[]> {
	return (await list({ indexedDB: real })).map((r) => String(r.properties.content));
}

describe('direct sign-out (nothing of this account queued)', () => {
	it('clears the sign-in, then cleans up and reloads; other accounts’ posts stay', async () => {
		await seed([BOB, 'Bob post']);
		mount();
		await click('Sign out of this device');

		expect(steps).toEqual(['token', 'finish']);
		expect(await contents()).toEqual(['Bob post']);
	});

	it('stays signed in, with no cleanup or reload, when the sign-in can’t be cleared', async () => {
		token_fails = true;
		mount();
		await click('Sign out of this device');

		expect(steps).toEqual(['token']);
		expect(finish_sign_out).not.toHaveBeenCalled();
		expect(status()).toBe(SIGN_IN_NOT_CLEARED);
		expect(button('Sign out of this device')).not.toBeNull();
	});
});

describe('signing out with queued posts', () => {
	beforeEach(async () => {
		await seed([ALICE, 'Alice post one'], [BOB, 'Bob post'], [ALICE, 'Alice post two']);
	});

	it('asks first, naming only the count', async () => {
		mount();
		await click('Sign out of this device');

		expect(steps).toEqual([]);
		expect(root.textContent).toContain('You have 2 queued posts on this device that haven’t been sent.');
		expect(root.textContent).not.toContain('Alice post');
	});

	it('Keep: clears the sign-in, never writes the queue, then cleans up and reloads', async () => {
		mount();
		await click('Sign out of this device');
		await click('Keep queued posts on this device');

		expect(steps).toEqual(['token', 'finish']);
		expect(await contents()).toEqual(['Alice post one', 'Bob post', 'Alice post two']);
	});

	it('Keep: when the sign-in can’t be cleared, keeps everything and says the person is still signed in', async () => {
		token_fails = true;
		mount();
		await click('Sign out of this device');
		await click('Keep queued posts on this device');

		expect(steps).toEqual(['token']);
		expect(status()).toBe(SIGN_IN_NOT_CLEARED);
		expect(await contents()).toEqual(['Alice post one', 'Bob post', 'Alice post two']);
		expect(button('Keep queued posts on this device')).not.toBeNull();
	});

	it('Remove: clears the sign-in before the queue transaction, removes only this account’s posts, then reloads', async () => {
		mount();
		await click('Sign out of this device');
		await click('Remove my queued posts and sign out');

		expect(steps).toEqual(['token', 'queue-write', 'finish']);
		expect(await contents()).toEqual(['Bob post']);
	});

	it('Remove: when the sign-in can’t be cleared, removes nothing, cleans up nothing, and says the person is still signed in', async () => {
		token_fails = true;
		mount();
		await click('Sign out of this device');
		await click('Remove my queued posts and sign out');

		expect(steps).toEqual(['token']);
		expect(status()).toBe(SIGN_IN_NOT_CLEARED);
		expect(await contents()).toEqual(['Alice post one', 'Bob post', 'Alice post two']);
	});

	it('Remove: when the posts can’t be removed after the sign-in is gone, says so and keeps every post', async () => {
		queue_writes_fail = true;
		mount();
		await click('Sign out of this device');
		await click('Remove my queued posts and sign out');

		expect(steps).toEqual(['token', 'queue-write']);
		expect(finish_sign_out).not.toHaveBeenCalled();
		expect(status()).toBe(PARTIAL);
		expect(await contents()).toEqual(['Alice post one', 'Bob post', 'Alice post two']);
		expect(button('Try removing my queued posts again')).not.toBeNull();
		expect(button('Finish signing out')).not.toBeNull();
		expect(button('Keep queued posts on this device')).toBeNull();
	});

	it('a retry after that partial failure removes this account’s posts without clearing the sign-in again', async () => {
		queue_writes_fail = true;
		mount();
		await click('Sign out of this device');
		await click('Remove my queued posts and sign out');
		queue_writes_fail = false;
		await click('Try removing my queued posts again');

		expect(clear_sign_in).toHaveBeenCalledTimes(1);
		expect(steps).toEqual(['token', 'queue-write', 'queue-write', 'finish']);
		expect(await contents()).toEqual(['Bob post']);
	});

	it('a retry that fails again keeps the posts and the same message', async () => {
		queue_writes_fail = true;
		mount();
		await click('Sign out of this device');
		await click('Remove my queued posts and sign out');
		await click('Try removing my queued posts again');

		expect(clear_sign_in).toHaveBeenCalledTimes(1);
		expect(finish_sign_out).not.toHaveBeenCalled();
		expect(status()).toBe(PARTIAL);
		expect(await contents()).toEqual(['Alice post one', 'Bob post', 'Alice post two']);
	});

	it('“Finish signing out” after that partial failure reloads and keeps every post', async () => {
		queue_writes_fail = true;
		mount();
		await click('Sign out of this device');
		await click('Remove my queued posts and sign out');
		await click('Finish signing out');

		expect(steps).toEqual(['token', 'queue-write', 'finish']);
		expect(await contents()).toEqual(['Alice post one', 'Bob post', 'Alice post two']);
	});
});

describe('the control itself', () => {
	it('stays signed in and says so when the queue can’t be read', async () => {
		const broken: OfflineQueueEnvironment = {
			indexedDB: {
				open: (): IDBOpenDBRequest => {
					const req = { error: new DOMException('blocked'), onerror: null as null | (() => void) };
					setTimeout(() => req.onerror?.(), 0);
					return req as unknown as IDBOpenDBRequest;
				},
			} as unknown as IDBFactory,
		};
		mount(broken);
		await click('Sign out of this device');

		expect(steps).toEqual([]);
		expect(status()).toBe('Couldn’t check this device for queued posts, so you’re still signed in. Try again.');
	});

	it('keeps its status line mounted for screen readers', () => {
		mount();
		expect(root.querySelector('[role="status"][aria-live="polite"]')).not.toBeNull();
	});
});
