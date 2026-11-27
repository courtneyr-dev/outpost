import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render } from 'preact';
import { RecentDrafts, type RecentDraftsEnvironment } from './recent-drafts';

let root: HTMLDivElement;

beforeEach(() => {
	root = document.createElement('div');
	document.body.appendChild(root);
});

afterEach(() => {
	render(null, root);
	root.remove();
});

interface Call {
	url: string;
	init: RequestInit;
	fields: URLSearchParams;
}

const PAGES: Record<
	string,
	{ items: Array<{ id: number; title: string }>; hasNext: boolean }
> = {
	'1': {
		items: [
			{ id: 11, title: 'First draft' },
			{ id: 12, title: '' },
		],
		hasNext: true,
	},
	'2': { items: [{ id: 21, title: 'Older draft' }], hasNext: false },
};

function site(
	open: (id: string, fields: URLSearchParams) => Response = (id) =>
		Response.json({
			id: Number(id),
			title: 'First draft',
			content: 'Plain text',
			editUrl: `${window.location.origin}/wp-admin/post.php?post=${id}&action=edit`,
			version: 'v1',
		})
): { env: RecentDraftsEnvironment; calls: Call[] } {
	const calls: Call[] = [];
	const env: RecentDraftsEnvironment = {
		fetch: (async (
			input: RequestInfo | URL,
			init?: RequestInit
		): Promise<Response> => {
			const fields = new URLSearchParams(String(init?.body));
			calls.push({ url: String(input), init: init ?? {}, fields });
			const id = fields.get('id');
			if (id) return open(id, fields);
			return Response.json(PAGES[fields.get('page') ?? '1']);
		}) as typeof fetch,
	};
	return { env, calls };
}

async function button(name: string): Promise<HTMLButtonElement> {
	return vi.waitFor(() => {
		const match = Array.from(root.querySelectorAll('button')).find(
			(b) => b.textContent === name && !b.disabled
		);
		if (!match) throw new Error(`No enabled button named ${name}`);
		return match;
	});
}

async function settle(): Promise<void> {
	await new Promise((resolve) => setTimeout(resolve, 0));
	await vi.waitFor(() =>
		expect(root.querySelector('[aria-busy="true"]')).toBeNull()
	);
}

describe('RecentDrafts', () => {
	it('pages through drafts and keeps focus on the page label when the pressed button goes away', async () => {
		const { env, calls } = site();
		render(<RecentDrafts token="tk" env={env} />, root);
		await settle();

		expect(await button('First draft')).toBeTruthy();
		expect(await button('Untitled draft')).toBeTruthy();
		expect(root.querySelector('ul')?.getAttribute('aria-labelledby')).toBe(
			'outpost-recent-drafts-page'
		);

		(await button('Next drafts')).click();
		await settle();
		expect(await button('Older draft')).toBeTruthy();
		expect(
			Array.from(root.querySelectorAll('button')).some(
				(b) => b.textContent === 'Next drafts'
			)
		).toBe(false);
		await vi.waitFor(() =>
			expect(document.activeElement?.id).toBe(
				'outpost-recent-drafts-page'
			)
		);
		expect(document.activeElement?.textContent).toBe('Your drafts, page 2');

		(await button('Previous drafts')).click();
		await vi.waitFor(() =>
			expect(calls.map((call) => call.fields.get('page'))).toEqual([
				'1',
				'2',
				'1',
			])
		);
	});

	it('moves focus to the page label before the pressed button goes away', async () => {
		const { env } = site();
		let release_page_two: (() => void) | null = null;
		const held: RecentDraftsEnvironment = {
			fetch: ((input: RequestInfo | URL, init?: RequestInit) => {
				const fields = new URLSearchParams(String(init?.body));
				if (fields.get('page') !== '2') return env.fetch(input, init);
				return new Promise<Response>((resolve) => {
					release_page_two = () => resolve(Response.json(PAGES['2']));
				});
			}) as typeof fetch,
		};
		render(<RecentDrafts token="tk" env={held} />, root);
		await settle();

		const next = await button('Next drafts');
		next.focus();
		next.click();
		await new Promise((resolve) => setTimeout(resolve, 0));

		// The Next button is gone before WordPress is even asked.
		expect(next.isConnected).toBe(false);
		expect(document.activeElement?.id).toBe('outpost-recent-drafts-page');

		// WordPress has the request and has not answered.
		await vi.waitFor(() => expect(release_page_two).not.toBeNull());
		expect(root.querySelector('[aria-busy="true"]')).not.toBeNull();
		expect(document.activeElement?.id).toBe('outpost-recent-drafts-page');

		release_page_two!();
		await settle();
		expect(document.activeElement?.id).toBe('outpost-recent-drafts-page');
		expect(document.activeElement?.textContent).toBe('Your drafts, page 2');
	});

	it('sends the token only in the Authorization header, without cookies', async () => {
		const { env, calls } = site();
		render(<RecentDrafts token="secret-token" env={env} />, root);
		await vi.waitFor(() => expect(calls).toHaveLength(1));

		const [call] = calls;
		expect(call?.url).toBe('/wp-json/outpost/v1/recent-drafts');
		expect(call?.init.credentials).toBe('omit');
		expect(call?.init.method).toBe('POST');
		expect(
			(call?.init.headers as Record<string, string>).Authorization
		).toBe('Bearer secret-token');
		expect(call?.fields.has('access_token')).toBe(false);
	});

	it('reopens a draft with focus in its title and saves it with the version it opened', async () => {
		const { env, calls } = site((id, fields) =>
			Response.json({
				id: Number(id),
				title: fields.get('title') ?? 'First draft',
				content: fields.get('content') ?? 'Plain text',
				editUrl: `${window.location.origin}/wp-admin/post.php?post=${id}&action=edit`,
				version: fields.has('title') ? 'v2' : 'v1',
			})
		);
		render(<RecentDrafts token="tk" env={env} />, root);
		await settle();

		(await button('First draft')).click();
		await settle();
		const title = root.querySelector(
			'#outpost-draft-title'
		) as HTMLInputElement;
		await vi.waitFor(() => expect(document.activeElement).toBe(title));
		expect(
			root.querySelector('label[for="outpost-draft-title"]')?.textContent
		).toBe('Draft title');
		expect(root.querySelector('a')?.getAttribute('href')).toContain(
			'/wp-admin/post.php?post=11&action=edit'
		);

		title.value = 'Retitled';
		title.dispatchEvent(new Event('input', { bubbles: true }));
		(await button('Save WordPress draft')).click();
		await settle();

		const saved = calls.at(-1)?.fields;
		expect(saved?.get('id')).toBe('11');
		expect(saved?.get('title')).toBe('Retitled');
		expect(saved?.get('version')).toBe('v1');
		expect(root.querySelector('[role="status"]')?.textContent).toBe(
			'WordPress draft saved.'
		);

		(await button('Back to recent drafts')).click();
		await vi.waitFor(() =>
			expect(document.activeElement?.id).toBe(
				'outpost-recent-drafts-page'
			)
		);
		expect(await button('Retitled')).toBeTruthy();
	});

	it('hides the editor link when the server returns another origin', async () => {
		const { env } = site((id) =>
			Response.json({
				id: Number(id),
				title: 'First draft',
				content: null,
				editUrl:
					'https://elsewhere.example/wp-admin/post.php?post=11&action=edit',
				version: 'v1',
			})
		);
		render(<RecentDrafts token="tk" env={env} />, root);
		await settle();
		(await button('First draft')).click();
		await settle();

		expect(root.querySelector('a')).toBeNull();
		expect(root.querySelector('#outpost-draft-content')).toBeNull();
		expect(root.textContent).toContain(
			'Edit this draft’s formatted content in WordPress.'
		);
	});

	it('shows a plain refusal for a draft WordPress will not open', async () => {
		const { env } = site(() =>
			Response.json(
				{ code: 'outpost_draft_unavailable' },
				{ status: 403 }
			)
		);
		render(<RecentDrafts token="tk" env={env} />, root);
		await settle();
		(await button('First draft')).click();
		await settle();

		expect(root.querySelector('[role="alert"]')?.textContent).toBe(
			'WordPress did not let Outpost open this draft.'
		);
		expect(root.querySelector('#outpost-draft-title')).toBeNull();
	});

	it('asks to reopen when the draft changed since it was opened', async () => {
		const { env } = site((id, fields) =>
			fields.has('title')
				? new Response(null, { status: 409 })
				: Response.json({
						id: Number(id),
						title: 'First draft',
						content: 'Plain text',
						editUrl: null,
						version: 'v1',
					})
		);
		render(<RecentDrafts token="tk" env={env} />, root);
		await settle();
		(await button('First draft')).click();
		await settle();
		(await button('Save WordPress draft')).click();
		await settle();

		expect(root.querySelector('[role="alert"]')?.textContent).toBe(
			'The draft has changed. Reopen it before saving.'
		);
	});
});
