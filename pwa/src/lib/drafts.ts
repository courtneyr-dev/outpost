/**
 * Composer drafts: unsent text that survives a reload or a fresh sign-in.
 *
 * "Sign in again" clears the token and reloads the page, which used to throw
 * away anything typed. Each mode now copies its text fields here as the
 * person types (debounced) and reads them back on mount.
 *
 * Rules:
 *   - One localStorage key holds one account's drafts, tagged with that
 *     account's `me`. A different `me` never sees them; its first write
 *     replaces them, so two people's text is never mixed on one device.
 *   - Only text the person typed and a few UI choices (`meta`, such as the
 *     selected style) are stored. Never the bearer token, which stays in
 *     the encrypted IndexedDB store, and never file bytes.
 *   - Restoring only fills the form. Nothing here submits.
 *   - Sign-out calls clear_drafts(), which also refuses later writes for
 *     the rest of the page load, so a debounced save that fires between
 *     sign-out and the reload can't put the text back.
 *   - Every storage call can throw (private browsing, quota, sandboxed
 *     frames). Reads then return null and writes return false, and the
 *     composer keeps working without drafts.
 */

import { useEffect, useRef, useState } from 'preact/hooks';

export const DRAFTS_KEY = 'outpost:drafts';
export const DRAFT_SAVE_DELAY_MS = 600;

export type DraftMode = 'note' | 'reply' | 'photo' | 'listen' | 'life' | 'recipe';

export type DraftValue = string | boolean | DraftValue[] | { [key: string]: DraftValue };
export type DraftFields = Record<string, DraftValue>;

export interface Draft {
	fields: DraftFields;
	meta: DraftFields;
}

export interface DraftStorage {
	getItem(key: string): string | null;
	setItem(key: string, value: string): void;
	removeItem(key: string): void;
}

interface StoredDrafts {
	v: 1;
	me: string;
	modes: Partial<Record<DraftMode, { savedAt: number; fields: DraftFields; meta: DraftFields }>>;
}

/** Storages cleared at sign-out during this page load. */
const signed_out = new WeakSet<DraftStorage>();

function default_storage(): DraftStorage | null {
	try {
		return typeof window !== 'undefined' && window.localStorage ? window.localStorage : null;
	} catch {
		return null;
	}
}

function is_record(value: unknown): value is Record<string, unknown> {
	return !!value && typeof value === 'object' && !Array.isArray(value);
}

function load(storage: DraftStorage): StoredDrafts | null {
	try {
		const raw: unknown = JSON.parse(storage.getItem(DRAFTS_KEY) ?? 'null');
		if (!is_record(raw) || raw.v !== 1 || typeof raw.me !== 'string' || !is_record(raw.modes)) {
			return null;
		}
		return raw as unknown as StoredDrafts;
	} catch {
		return null;
	}
}

/** True when a value holds something the person typed. Booleans alone don't count. */
function has_content(value: DraftValue): boolean {
	if (typeof value === 'string') return value.trim() !== '';
	if (typeof value === 'boolean') return false;
	if (Array.isArray(value)) return value.some(has_content);
	return Object.values(value).some(has_content);
}

/** A restored field as a string; anything else (missing, wrong type) is empty. */
export function draft_text(value: unknown): string {
	return typeof value === 'string' ? value : '';
}

export function is_blank(fields: DraftFields): boolean {
	return !Object.values(fields).some(has_content);
}

export function read_draft(
	mode: DraftMode,
	me: string,
	storage: DraftStorage | null = default_storage(),
): Draft | null {
	if (!storage) return null;
	const stored = load(storage);
	if (!stored || stored.me !== me) return null;
	const entry = stored.modes[mode];
	if (!entry || !is_record(entry.fields)) return null;
	return { fields: entry.fields, meta: is_record(entry.meta) ? entry.meta : {} };
}

/**
 * Saves one mode's draft. Blank fields remove the mode's entry. Returns true
 * only when storage accepted the change.
 */
export function write_draft(
	mode: DraftMode,
	me: string,
	fields: DraftFields,
	meta: DraftFields = {},
	storage: DraftStorage | null = default_storage(),
): boolean {
	if (!storage || signed_out.has(storage)) return false;
	const current = load(storage);
	const stored: StoredDrafts = current && current.me === me ? current : { v: 1, me, modes: {} };
	if (is_blank(fields)) {
		delete stored.modes[mode];
	} else {
		stored.modes[mode] = { savedAt: Date.now(), fields, meta };
	}
	try {
		if (Object.keys(stored.modes).length === 0) {
			storage.removeItem(DRAFTS_KEY);
		} else {
			storage.setItem(DRAFTS_KEY, JSON.stringify(stored));
		}
		return true;
	} catch {
		return false;
	}
}

export function discard_draft(
	mode: DraftMode,
	me: string,
	storage: DraftStorage | null = default_storage(),
): void {
	if (!storage) return;
	write_draft(mode, me, {}, {}, storage);
}

/** Sign-out: remove every draft and refuse new ones until the page reloads. */
export function clear_drafts(storage: DraftStorage | null = default_storage()): void {
	if (!storage) return;
	signed_out.add(storage);
	try {
		storage.removeItem(DRAFTS_KEY);
	} catch {
		// Storage blocked: nothing was saved there.
	}
}

/** Whether this device can keep drafts right now (probes with a throwaway write). */
export function drafts_available(storage: DraftStorage | null = default_storage()): boolean {
	if (!storage) return false;
	const probe = DRAFTS_KEY + ':probe';
	try {
		storage.setItem(probe, '1');
		storage.removeItem(probe);
		return true;
	} catch {
		return false;
	}
}

/* ------------------------------------------------------------------ */
/* Hook                                                                */
/* ------------------------------------------------------------------ */

/**
 * 'idle'        nothing to report (blank form, or typing since the last save)
 * 'restored'    the form opened with a saved draft
 * 'saved'       the last write succeeded
 * 'unavailable' the last write failed; this device isn't keeping drafts
 */
export type DraftSaveState = 'idle' | 'restored' | 'saved' | 'unavailable';

export interface UseDraftOptions {
	mode: DraftMode;
	me: string;
	/** The text the person typed. Blank fields mean no draft. */
	fields: DraftFields;
	/** UI choices restored with the text, such as the selected style. */
	meta?: DraftFields;
	/** True when the form opened from a saved draft. */
	restored?: boolean;
	storage?: DraftStorage | null;
}

export interface UseDraftResult {
	state: DraftSaveState;
	/** Drop the draft now and cancel any pending save. Call after a publish or queue. */
	discard: () => void;
}

export function useDraft({
	mode,
	me,
	fields,
	meta = {},
	restored = false,
	storage,
}: UseDraftOptions): UseDraftResult {
	const [state, setState] = useState<DraftSaveState>(restored ? 'restored' : 'idle');
	const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
	const first = useRef(true);
	const target = storage === undefined ? default_storage() : storage;
	const snapshot = JSON.stringify([fields, meta]);
	const latest = useRef({ fields, meta });
	latest.current = { fields, meta };

	const cancel = (): void => {
		if (timer.current !== null) {
			clearTimeout(timer.current);
			timer.current = null;
		}
	};

	useEffect(() => {
		// The first render shows what was restored (or a blank form); there is
		// nothing new to save yet.
		if (first.current) {
			first.current = false;
			return;
		}
		cancel();
		setState('idle');
		timer.current = setTimeout(() => {
			timer.current = null;
			const { fields: f, meta: m } = latest.current;
			const blank = is_blank(f);
			const ok = write_draft(mode, me, f, m, target);
			setState(blank ? 'idle' : ok ? 'saved' : 'unavailable');
		}, DRAFT_SAVE_DELAY_MS);
		// eslint-disable-next-line react-hooks/exhaustive-deps -- `snapshot` is the serialized fields+meta; the objects themselves are new every render.
	}, [snapshot, mode, me]);

	useEffect(() => cancel, []);

	const discard = (): void => {
		cancel();
		discard_draft(mode, me, target);
		setState('idle');
	};

	return { state, discard };
}
