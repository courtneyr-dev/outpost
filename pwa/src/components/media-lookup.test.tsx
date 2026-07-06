import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { render } from 'preact';
import { MediaLookup } from './media-lookup';
import type { MediaLookupEnvironment, MediaLookupResult } from '../lib/media-lookup';

let root: HTMLDivElement;

beforeEach(() => {
	root = document.createElement('div');
	document.body.appendChild(root);
});

afterEach(() => {
	render(null, root);
	root.remove();
});

function make_response(init: { status?: number; body?: unknown }): Response {
	const body = typeof init.body === 'string' ? init.body : JSON.stringify(init.body ?? {});
	return new Response(body, {
		status: init.status ?? 200,
		headers: { 'Content-Type': 'application/json' },
	});
}

function results_env(rows: unknown[], notConfigured = false): MediaLookupEnvironment {
	return {
		fetch: async () => make_response({ body: { results: rows, kind: 'watch', notConfigured } }),
	};
}

async function flush(): Promise<void> {
	await new Promise((resolve) => setTimeout(resolve, 0));
	await new Promise((resolve) => setTimeout(resolve, 0));
}

function input_el(): HTMLInputElement {
	return root.querySelector('.outpost-media-lookup__input') as HTMLInputElement;
}

function search_button(): HTMLButtonElement {
	return root.querySelector('.outpost-media-lookup__search') as HTMLButtonElement;
}

function result_buttons(): HTMLButtonElement[] {
	return Array.from(root.querySelectorAll('.outpost-media-lookup__result')) as HTMLButtonElement[];
}

function set_value(el: HTMLInputElement, value: string): void {
	el.value = value;
	el.dispatchEvent(new Event('input', { bubbles: true }));
}

const noop = (): void => {};

describe('MediaLookup', () => {
	it('renders a labelled search input seeded with initialQuery', () => {
		render(
			<MediaLookup
				idPrefix="test"
				kind="watch"
				label="Look up a movie"
				initialQuery="Inception"
				accessToken="abc"
				onSelect={noop}
				env={results_env([])}
			/>,
			root,
		);
		const input = input_el();
		expect(input).toBeTruthy();
		expect(input.value).toBe('Inception');
		// The input has an associated label.
		const label = root.querySelector(`label[for="${input.id}"]`);
		expect(label).toBeTruthy();
	});

	it('search button is type=button so it never submits a parent form', () => {
		render(
			<MediaLookup idPrefix="test" kind="watch" label="Look up" accessToken="abc" onSelect={noop} env={results_env([])} />,
			root,
		);
		expect(search_button().getAttribute('type')).toBe('button');
	});

	it('renders results with cover, title, year and creator after searching', async () => {
		const rows = [
			{
				title: 'Inception',
				cover: 'https://img.example/inception.jpg',
				creator: 'Christopher Nolan',
				year: '2010',
				external_id: '27205',
				url: '',
			},
		];
		render(
			<MediaLookup idPrefix="test" kind="watch" label="Look up" initialQuery="Inception" accessToken="abc" onSelect={noop} env={results_env(rows)} />,
			root,
		);
		search_button().click();
		await flush();

		const buttons = result_buttons();
		expect(buttons).toHaveLength(1);
		expect(buttons[0]?.textContent).toContain('Inception');
		expect(buttons[0]?.textContent).toContain('2010');
		expect(buttons[0]?.textContent).toContain('Christopher Nolan');
		const img = buttons[0]?.querySelector('img') as HTMLImageElement;
		expect(img.getAttribute('src')).toBe('https://img.example/inception.jpg');
	});

	it('calls onSelect with the tapped result', async () => {
		let selected: MediaLookupResult | null = null;
		const rows = [
			{ title: 'Dune', cover: '', creator: 'Frank Herbert', year: '1965', external_id: 'OL1W', url: '' },
		];
		render(
			<MediaLookup
				idPrefix="test"
				kind="read"
				label="Look up"
				initialQuery="Dune"
				accessToken="abc"
				onSelect={(r): void => {
					selected = r;
				}}
				env={results_env(rows)}
			/>,
			root,
		);
		search_button().click();
		await flush();
		result_buttons()[0]?.click();
		await flush();

		expect(selected).not.toBeNull();
		expect((selected as unknown as MediaLookupResult).title).toBe('Dune');
		expect((selected as unknown as MediaLookupResult).externalId).toBe('OL1W');
	});

	it('shows an empty state when there are no matches', async () => {
		render(
			<MediaLookup idPrefix="test" kind="watch" label="Look up" initialQuery="zzz" accessToken="abc" onSelect={noop} env={results_env([])} />,
			root,
		);
		search_button().click();
		await flush();
		expect(root.textContent).toContain('No matches');
	});

	it('shows a friendly not-configured hint', async () => {
		render(
			<MediaLookup idPrefix="test" kind="game" label="Look up" initialQuery="Catan" accessToken="abc" onSelect={noop} env={results_env([], true)} />,
			root,
		);
		search_button().click();
		await flush();
		expect(root.textContent?.toLowerCase()).toContain('api connections');
	});

	it('shows an error message on server error', async () => {
		const env: MediaLookupEnvironment = {
			fetch: async () => make_response({ status: 502, body: {} }),
		};
		render(
			<MediaLookup idPrefix="test" kind="watch" label="Look up" initialQuery="Inception" accessToken="abc" onSelect={noop} env={env} />,
			root,
		);
		search_button().click();
		await flush();
		const alert = root.querySelector('[role="alert"]');
		expect(alert?.textContent).toBeTruthy();
	});

	it('search button is disabled when the query is empty', async () => {
		render(
			<MediaLookup idPrefix="test" kind="watch" label="Look up" accessToken="abc" onSelect={noop} env={results_env([])} />,
			root,
		);
		expect(search_button().disabled).toBe(true);
		set_value(input_el(), 'Inception');
		await flush();
		expect(search_button().disabled).toBe(false);
	});

	it('renders a Movie/TV toggle when showTypeToggle is set and passes type to the lookup', async () => {
		let captured_body: Record<string, unknown> = {};
		const env: MediaLookupEnvironment = {
			fetch: async (_i: RequestInfo | URL, init?: RequestInit) => {
				captured_body = JSON.parse((init?.body as string) ?? '{}');
				return make_response({ body: { results: [], kind: 'watch', notConfigured: false } });
			},
		};
		render(
			<MediaLookup
				idPrefix="test"
				kind="watch"
				label="Look up"
				initialQuery="The Bear"
				accessToken="abc"
				onSelect={noop}
				showTypeToggle
				env={env}
			/>,
			root,
		);
		const tv = root.querySelector('input[value="tv"]') as HTMLInputElement;
		expect(tv).toBeTruthy();
		tv.checked = true;
		tv.dispatchEvent(new Event('change', { bubbles: true }));
		// Let Preact re-render so the search handler closes over type='tv'.
		await flush();
		search_button().click();
		await flush();
		expect(captured_body['type']).toBe('tv');
	});

	it('does not render a type toggle without showTypeToggle', () => {
		render(
			<MediaLookup idPrefix="test" kind="read" label="Look up" accessToken="abc" onSelect={noop} env={results_env([])} />,
			root,
		);
		expect(root.querySelector('input[value="tv"]')).toBeNull();
	});
});
