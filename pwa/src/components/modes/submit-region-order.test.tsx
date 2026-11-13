import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import 'fake-indexeddb/auto';
import { render, type JSX } from 'preact';
import type { ComposerConfig } from '../../lib/composer-config';
import type { StoredToken } from '../../lib/token-store';
import { LifeMode } from './life-mode';
import { ListenMode } from './listen-mode';
import { NoteMode } from './note-mode';
import { PhotoMode } from './photo-mode';
import { RecipeMode } from './recipe-mode';
import { ReplyMode } from './reply-mode';

const token: StoredToken = {
	accessToken: 'test-token',
	tokenType: 'Bearer',
	scope: 'create',
	me: 'https://example.test/',
	storedAt: 0,
};

const composer_config: ComposerConfig = {
	companions: {
		'post-kinds': 'absent',
		'post-formats': 'absent',
		xfn: 'absent',
		'syndication-links': 'absent',
		yoast: 'absent',
		activitypub: 'absent',
		'accessibility-checker': 'absent',
		'rss-chat-routing': 'absent',
	},
	postFormats: null,
	xfnRels: [],
	existingCategories: [],
	existingTags: [],
	bridgyHostMap: {},
	siteSettings: { bridgyAutoSuggest: false, defaultPostVariant: 'note' },
};

const modes: Array<[string, () => JSX.Element]> = [
	['Post', () => <NoteMode token={token} composerConfig={composer_config} />],
	['Reply', () => <ReplyMode token={token} composerConfig={composer_config} />],
	['Photo', () => <PhotoMode token={token} composerConfig={composer_config} />],
	['Doing', () => <ListenMode token={token} composerConfig={composer_config} />],
	['Life', () => <LifeMode token={token} composerConfig={composer_config} />],
	['Recipe', () => <RecipeMode token={token} composerConfig={composer_config} />],
];

let root: HTMLDivElement;
let scroll_into_view: ReturnType<typeof vi.fn>;
let original_scroll_into_view: PropertyDescriptor | undefined;

beforeEach(() => {
	root = document.createElement('div');
	document.body.appendChild(root);
	scroll_into_view = vi.fn();
	original_scroll_into_view = Object.getOwnPropertyDescriptor(
		HTMLElement.prototype,
		'scrollIntoView',
	);
	Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', {
		configurable: true,
		value: scroll_into_view,
	});
});

afterEach(() => {
	render(null, root);
	root.remove();
	if (original_scroll_into_view) {
		Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', original_scroll_into_view);
	} else {
		delete (HTMLElement.prototype as Partial<HTMLElement>).scrollIntoView;
	}
});

describe.each(modes)('%s submit region', (_name, node) => {
	it('places the alert after More options and directly before status and Publish', () => {
		render(node(), root);
		const alert = root.querySelector('.outpost-error[role="alert"]');
		const drawer = root.querySelector('.outpost-drawer');

		expect(alert).not.toBeNull();
		expect(drawer).not.toBeNull();
		expect(alert?.nextElementSibling).toMatchObject({ className: expect.stringContaining('outpost-status') });
		expect(alert?.nextElementSibling?.nextElementSibling).toMatchObject({
			className: expect.stringContaining('outpost-publish'),
		});
		expect(alert?.compareDocumentPosition(drawer as Element)).toBe(
			Node.DOCUMENT_POSITION_PRECEDING,
		);
	});
});

describe('submit error reveal target', () => {
	it('scrolls Recipe’s persistent alert when empty submit reports its validation error', async () => {
		render(<RecipeMode token={token} composerConfig={composer_config} />, root);
		root
			.querySelector('form')
			?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

		await vi.waitFor(() => expect(scroll_into_view).toHaveBeenCalledOnce());
		const alert = root.querySelector('.outpost-error[role="alert"]');
		expect(scroll_into_view.mock.contexts[0]).toBe(alert);
	});

	// Post, Reply, Photo, Doing, and Life use disabled Publish hints for
	// empty required fields; their handlers return without reporting an alert.
});
