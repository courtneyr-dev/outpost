import { describe, it, expect, beforeEach } from 'vitest';
import {
	DRAFTS_KEY,
	clear_drafts,
	discard_draft,
	drafts_available,
	read_draft,
	write_draft,
	type DraftStorage,
} from './drafts';

/** In-memory Storage double; `fail` makes every call throw the given error. */
function memory_storage(fail?: { get?: Error; set?: Error; remove?: Error }): DraftStorage & {
	data: Map<string, string>;
} {
	const data = new Map<string, string>();
	return {
		data,
		getItem(key: string): string | null {
			if (fail?.get) throw fail.get;
			return data.get(key) ?? null;
		},
		setItem(key: string, value: string): void {
			if (fail?.set) throw fail.set;
			data.set(key, value);
		},
		removeItem(key: string): void {
			if (fail?.remove) throw fail.remove;
			data.delete(key);
		},
	};
}

const ME = 'https://example.test/';
const OTHER = 'https://other.test/';

let storage: ReturnType<typeof memory_storage>;

beforeEach(() => {
	storage = memory_storage();
});

describe('write_draft / read_draft', () => {
	it('round-trips fields and meta for the same me', () => {
		expect(write_draft('note', ME, { title: 'Hi', content: 'Body' }, { variant: 'note' }, storage)).toBe(true);
		expect(read_draft('note', ME, storage)).toEqual({
			fields: { title: 'Hi', content: 'Body' },
			meta: { variant: 'note' },
		});
	});

	it('keeps each mode separate', () => {
		write_draft('note', ME, { content: 'note text' }, {}, storage);
		write_draft('reply', ME, { content: 'reply text' }, {}, storage);
		expect(read_draft('note', ME, storage)?.fields.content).toBe('note text');
		expect(read_draft('reply', ME, storage)?.fields.content).toBe('reply text');
	});

	it("ignores another account's draft", () => {
		write_draft('note', OTHER, { content: 'theirs' }, {}, storage);
		expect(read_draft('note', ME, storage)).toBeNull();
	});

	it("replaces another account's drafts on the first write instead of mixing them", () => {
		write_draft('reply', OTHER, { content: 'theirs' }, {}, storage);
		write_draft('note', ME, { content: 'mine' }, {}, storage);
		expect(read_draft('reply', OTHER, storage)).toBeNull();
		expect(read_draft('reply', ME, storage)).toBeNull();
		expect(read_draft('note', ME, storage)?.fields.content).toBe('mine');
	});

	it('removes the mode entry when every field is blank, so meta alone is not a draft', () => {
		write_draft('note', ME, { content: 'x' }, { variant: 'note' }, storage);
		expect(write_draft('note', ME, { title: ' ', content: '' }, { variant: 'aside' }, storage)).toBe(true);
		expect(read_draft('note', ME, storage)).toBeNull();
	});

	it('counts non-empty arrays of photo descriptions as content', () => {
		write_draft('photo', ME, { photos: [{ alt: 'A dog', caption: '', decorative: false }] }, {}, storage);
		expect(read_draft('photo', ME, storage)?.fields.photos).toEqual([
			{ alt: 'A dog', caption: '', decorative: false },
		]);
	});

	it('drops the whole key when the last mode entry goes', () => {
		write_draft('note', ME, { content: 'x' }, {}, storage);
		discard_draft('note', ME, storage);
		expect(storage.data.has(DRAFTS_KEY)).toBe(false);
	});

	it('treats corrupt JSON as no draft and still writes over it', () => {
		storage.data.set(DRAFTS_KEY, '{not json');
		expect(read_draft('note', ME, storage)).toBeNull();
		expect(write_draft('note', ME, { content: 'x' }, {}, storage)).toBe(true);
		expect(read_draft('note', ME, storage)?.fields.content).toBe('x');
	});
});

describe('storage that refuses', () => {
	it('returns false when the write is denied (private browsing, sandboxed frame)', () => {
		const denied = memory_storage({ set: new DOMException('denied', 'SecurityError') });
		expect(write_draft('note', ME, { content: 'x' }, {}, denied)).toBe(false);
	});

	it('returns false when storage is full', () => {
		const full = memory_storage({ set: new DOMException('full', 'QuotaExceededError') });
		expect(write_draft('note', ME, { content: 'x' }, {}, full)).toBe(false);
	});

	it('reads nothing when reads throw', () => {
		const blocked = memory_storage({ get: new DOMException('denied', 'SecurityError') });
		expect(read_draft('note', ME, blocked)).toBeNull();
	});

	it('treats missing storage as unavailable', () => {
		expect(write_draft('note', ME, { content: 'x' }, {}, null)).toBe(false);
		expect(read_draft('note', ME, null)).toBeNull();
		expect(drafts_available(null)).toBe(false);
	});

	it('drafts_available probes a write and cleans up after itself', () => {
		expect(drafts_available(storage)).toBe(true);
		expect(storage.data.size).toBe(0);
		expect(drafts_available(memory_storage({ set: new DOMException('full', 'QuotaExceededError') }))).toBe(false);
	});

	it('clear_drafts and discard_draft swallow storage errors', () => {
		const blocked = memory_storage({
			get: new DOMException('denied', 'SecurityError'),
			remove: new DOMException('denied', 'SecurityError'),
		});
		expect(() => clear_drafts(blocked)).not.toThrow();
		expect(() => discard_draft('note', ME, blocked)).not.toThrow();
	});
});

describe('clear_drafts (sign-out)', () => {
	it('removes every mode for every account', () => {
		write_draft('note', ME, { content: 'x' }, {}, storage);
		write_draft('reply', ME, { content: 'y' }, {}, storage);
		clear_drafts(storage);
		expect(storage.data.has(DRAFTS_KEY)).toBe(false);
	});

	it('refuses later writes to that storage for the rest of the page load', () => {
		clear_drafts(storage);
		// A debounced save that fires between sign-out and the reload must not
		// put the text back.
		expect(write_draft('note', ME, { content: 'late' }, {}, storage)).toBe(false);
		expect(storage.data.has(DRAFTS_KEY)).toBe(false);
	});
});
