/**
 * Photo mode share intake stays unconfirmed until the person acts (T10-S3-05).
 *
 * A share reaches Photo mode from the system share sheet, but any page can
 * submit the same form, so what arrives is untrusted. It fills the visible
 * fields and attaches the photos; it must not save a draft, replace a draft
 * the person already had, post, or touch the offline queue until the person
 * interacts with the form.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import 'fake-indexeddb/auto';
import { IDBFactory } from 'fake-indexeddb';
import { render } from 'preact';
import { PhotoMode } from './photo-mode';
import type { StoredToken } from '../../lib/token-store';
import type { MicropubEnvironment } from '../../lib/micropub';
import { DRAFT_SAVE_DELAY_MS, read_draft, write_draft } from '../../lib/drafts';
import { stash_share_target } from '../../lib/share-target';
import { stash_shared_photos, type ShareInboxEnvironment } from '../../lib/share-inbox';
import { enqueue, list } from '../../lib/offline-queue';

const ME = 'https://example.test/';
const token: StoredToken = { accessToken: 'test-token', tokenType: 'Bearer', scope: 'create', me: ME, storedAt: 0 };

let network_calls: number;
const no_network: MicropubEnvironment = {
	fetch: async (): Promise<Response> => {
		network_calls++;
		throw new Error('unexpected fetch');
	},
};

let root: HTMLDivElement;
let inbox_env: ShareInboxEnvironment;
let original_create: typeof URL.createObjectURL;
let original_revoke: typeof URL.revokeObjectURL;

async function wait(ms: number): Promise<void> {
	await new Promise((resolve) => setTimeout(resolve, ms));
}

async function wait_past_autosave(): Promise<void> {
	await wait(DRAFT_SAVE_DELAY_MS + 100);
}

function field(id: string): HTMLInputElement | HTMLTextAreaElement {
	return root.querySelector('#' + id) as HTMLInputElement | HTMLTextAreaElement;
}

async function arrive_with_share(): Promise<void> {
	stash_share_target({ tab: 'photo', sharedPhotos: true });
	await stash_shared_photos(
		{
			files: [new File([new Uint8Array([1, 2, 3])], 'shared.jpg', { type: 'image/jpeg' })],
			title: 'Shared title',
			text: 'Shared text',
			received: Date.now(),
		},
		inbox_env,
	);
	render(<PhotoMode token={token} micropubEnv={no_network} shareInboxEnv={inbox_env} />, root);
	await wait(20);
}

beforeEach(() => {
	localStorage.clear();
	sessionStorage.clear();
	network_calls = 0;
	globalThis.indexedDB = new IDBFactory();
	inbox_env = { indexedDB: globalThis.indexedDB };
	root = document.createElement('div');
	document.body.appendChild(root);
	original_create = URL.createObjectURL;
	original_revoke = URL.revokeObjectURL;
	let n = 0;
	URL.createObjectURL = vi.fn(() => `blob:test-${String(++n)}`);
	URL.revokeObjectURL = vi.fn();
});

afterEach(() => {
	render(null, root);
	root.remove();
	URL.createObjectURL = original_create;
	URL.revokeObjectURL = original_revoke;
});

describe('Photo mode share intake (T10-S3-05)', () => {
	it('fills the form from a share but saves no draft before the person acts', async () => {
		await arrive_with_share();

		expect(field('outpost-photo-name').value).toBe('Shared title');
		expect(field('outpost-photo-content').value).toBe('Shared text');
		expect(root.querySelector('[id^="outpost-photo-alt-"]')).not.toBeNull();

		await wait_past_autosave();

		expect(read_draft('photo', ME)).toBeNull();
		expect(network_calls).toBe(0);
	});

	it('saves once the person edits the form', async () => {
		await arrive_with_share();
		const alt = root.querySelector('[id^="outpost-photo-alt-"]') as HTMLTextAreaElement;
		alt.value = 'A shared photo';
		alt.dispatchEvent(new Event('input', { bubbles: true }));

		await wait_past_autosave();

		expect(read_draft('photo', ME)?.fields.name).toBe('Shared title');
	});

	it('leaves an existing photo draft alone when a share arrives', async () => {
		write_draft('photo', ME, { name: 'My own draft' }, {}, localStorage);

		await arrive_with_share();
		await wait_past_autosave();

		expect(read_draft('photo', ME)?.fields.name).toBe('My own draft');
	});

	it('does not touch posts already waiting in the offline queue', async () => {
		await enqueue({ source: 'note', properties: { content: ['queued earlier'] } as never, micropubEndpoint: null, me: ME });

		await arrive_with_share();
		await wait_past_autosave();

		const queued = await list();
		expect(queued.length).toBe(1);
		expect(queued[0]?.me).toBe(ME);
	});
});
