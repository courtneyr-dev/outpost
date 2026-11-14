/**
 * Signing out with queued posts (T10-S3-07).
 *
 * When the signed-in account has queued posts, signing out asks first:
 * "Keep queued posts on this device" signs out and leaves them as they are;
 * "Remove my queued posts and sign out" deletes only this account's posts.
 * Other accounts' posts and locked older ones survive either choice. If the
 * queue can't be read or changed, the person stays signed in and sees a
 * retryable error; nothing claims a deletion that didn't happen.
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

let root: HTMLDivElement;
let queueEnv: OfflineQueueEnvironment;
let signed_out: ReturnType<typeof vi.fn>;

beforeEach(() => {
	root = document.createElement('div');
	document.body.appendChild(root);
	queueEnv = { indexedDB: new IDBFactory() };
	signed_out = vi.fn();
});

afterEach(() => {
	render(null, root);
	root.remove();
});

function note(me: string, content: string): Parameters<typeof enqueue>[0] {
	return { source: 'note', properties: { content }, micropubEndpoint: 'https://example.test/mp', me };
}

function mount(env: OfflineQueueEnvironment = queueEnv): void {
	render(<SignOutControl owner={ALICE_ID} queueEnv={env} onSignOut={signed_out} />, root);
}

function button(name: string): HTMLButtonElement | null {
	return (Array.from(root.querySelectorAll('button')).find((b) => (b.textContent ?? '').trim() === name) ?? null) as HTMLButtonElement | null;
}

async function click(name: string): Promise<void> {
	const b = button(name);
	expect(b, `button "${name}"`).not.toBeNull();
	b?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
	await new Promise((resolve) => setTimeout(resolve, 30));
}

function status(): string {
	return root.querySelector('[role="status"]')?.textContent ?? '';
}

/** Reads work; every read-write transaction throws. */
function write_failing(real: IDBFactory): OfflineQueueEnvironment {
	return {
		indexedDB: {
			open(name: string, version?: number): IDBOpenDBRequest {
				const req = real.open(name, version);
				req.addEventListener('success', () => {
					const db = req.result;
					const transaction = db.transaction.bind(db);
					db.transaction = ((stores: string | string[], mode?: IDBTransactionMode) => {
						if (mode === 'readwrite') throw new DOMException('write blocked', 'UnknownError');
						return transaction(stores, mode);
					}) as IDBDatabase['transaction'];
				});
				return req;
			},
		} as unknown as IDBFactory,
	};
}

describe('SignOutControl', () => {
	it('signs out directly when this account has nothing queued, even if others do', async () => {
		await enqueue(note(BOB, 'Bob post'), queueEnv);
		mount();
		await click('Sign out of this device');

		expect(signed_out).toHaveBeenCalledTimes(1);
		expect(button('Keep queued posts on this device')).toBeNull();
		expect(await list(queueEnv)).toHaveLength(1);
	});

	it('asks first when this account has queued posts, naming only the count', async () => {
		await enqueue(note(ALICE, 'Alice post one'), queueEnv);
		await enqueue(note(ALICE, 'Alice post two'), queueEnv);
		mount();
		await click('Sign out of this device');

		expect(signed_out).not.toHaveBeenCalled();
		expect(root.textContent).toContain('You have 2 queued posts on this device that haven’t been sent.');
		expect(button('Keep queued posts on this device')).not.toBeNull();
		expect(button('Remove my queued posts and sign out')).not.toBeNull();
		expect(root.textContent).not.toContain('Alice post');
	});

	it('"Keep queued posts on this device" signs out and leaves every post as it was', async () => {
		await enqueue(note(ALICE, 'Alice post one'), queueEnv);
		await enqueue(note(BOB, 'Bob post'), queueEnv);
		const before = await list(queueEnv);
		mount();
		await click('Sign out of this device');
		await click('Keep queued posts on this device');

		expect(signed_out).toHaveBeenCalledTimes(1);
		expect(await list(queueEnv)).toEqual(before);
	});

	it('"Remove my queued posts and sign out" removes only this account’s posts, then signs out', async () => {
		await enqueue(note(ALICE, 'Alice post one'), queueEnv);
		await enqueue(note(BOB, 'Bob post'), queueEnv);
		await enqueue(note(ALICE, 'Alice post two'), queueEnv);
		mount();
		await click('Sign out of this device');
		await click('Remove my queued posts and sign out');

		expect(signed_out).toHaveBeenCalledTimes(1);
		expect((await list(queueEnv)).map((r) => r.properties.content)).toEqual(['Bob post']);
	});

	it('stays signed in, removes nothing, and says so when the posts can’t be removed', async () => {
		const real = new IDBFactory();
		const env: OfflineQueueEnvironment = { indexedDB: real };
		await enqueue(note(ALICE, 'Alice post one'), env);
		mount(write_failing(real));
		await click('Sign out of this device');
		await click('Remove my queued posts and sign out');

		expect(signed_out).not.toHaveBeenCalled();
		expect(status()).toBe('Couldn’t remove your queued posts, so nothing was removed and you’re still signed in. Try again.');
		expect(await list(env)).toHaveLength(1);
		expect(button('Remove my queued posts and sign out')).not.toBeNull();
	});

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

		expect(signed_out).not.toHaveBeenCalled();
		expect(status()).toBe('Couldn’t check this device for queued posts, so you’re still signed in. Try again.');
	});

	it('keeps its status line mounted for screen readers', () => {
		mount();
		expect(root.querySelector('[role="status"][aria-live="polite"]')).not.toBeNull();
	});
});
