import { useCallback, useEffect, useRef, useState } from 'preact/hooks';
import {
	AppearanceError,
	apply_to_document,
	effective_scheme,
	read_cache,
	reconcile,
	sync_appearance,
	write_cache,
	CACHE_KEY,
	NEW_USER_DEFAULT,
	type AppearanceEnvironment,
	type AppearanceErrorCode,
	type AppearanceMode,
	type AppearanceState,
	type AppearanceStorage,
	type EffectiveScheme,
	type StyleSource,
} from './appearance';

/**
 * Save status shown in the settings sheet:
 *   - `idle`        nothing to report yet
 *   - `saving`      a change is on its way to the site
 *   - `saved`       the site confirmed the current choice
 *   - `device-only` the choice applies here; the site hasn't stored it
 *                   (offline, timeout, or the site refused) — it is sent
 *                   again on the next successful contact
 */
export type AppearanceStatus =
	| { kind: 'idle' }
	| { kind: 'saving' }
	| { kind: 'saved' }
	| { kind: 'device-only'; reason: AppearanceErrorCode; stored: boolean };

export interface UseAppearance {
	state: AppearanceState;
	scheme: EffectiveScheme;
	status: AppearanceStatus;
	setMode: (mode: AppearanceMode) => void;
	setSource: (source: StyleSource) => void;
	retry: () => void;
}

export interface UseAppearanceOptions {
	accessToken: string;
	me: string;
	env?: AppearanceEnvironment;
	storage?: AppearanceStorage | null;
}

function without_pending(state: AppearanceState): AppearanceState {
	const { pending: _sent, ...rest } = state;
	return rest;
}

function prefers_dark(): boolean {
	return typeof window !== 'undefined' && typeof window.matchMedia === 'function'
		? window.matchMedia('(prefers-color-scheme: dark)').matches
		: false;
}

export function useAppearance({ accessToken, me, env, storage }: UseAppearanceOptions): UseAppearance {
	const initial = (): AppearanceState => {
		const cached = read_cache(storage);
		// A cache written for a different account is not this person's choice.
		if (cached && (!cached.me || cached.me === me)) return cached;
		return { ...NEW_USER_DEFAULT, me };
	};
	const [state, setState] = useState<AppearanceState>(initial);
	const [dark, setDark] = useState<boolean>(prefers_dark);
	const [status, setStatus] = useState<AppearanceStatus>({ kind: 'idle' });
	const state_ref = useRef(state);
	state_ref.current = state;
	const [attempt, setAttempt] = useState(0);

	const scheme = effective_scheme(state.mode, dark);

	// Keep the document in step with the state and the OS preference.
	useEffect(() => {
		apply_to_document(state, scheme);
	}, [state, scheme]);

	// System follows the OS while the app is open.
	useEffect(() => {
		if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return;
		const mq = window.matchMedia('(prefers-color-scheme: dark)');
		const on_change = (e: MediaQueryListEvent): void => setDark(e.matches);
		mq.addEventListener?.('change', on_change);
		return (): void => mq.removeEventListener?.('change', on_change);
	}, []);

	// Another tab changed the choice.
	useEffect(() => {
		const on_storage = (e: StorageEvent): void => {
			if (e.key !== CACHE_KEY) return;
			const next = read_cache(storage);
			if (next && (!next.me || next.me === me)) setState(next);
		};
		window.addEventListener('storage', on_storage);
		return (): void => window.removeEventListener('storage', on_storage);
	}, [me, storage]);

	const commit = useCallback(
		(next: AppearanceState): boolean => {
			setState(next);
			return write_cache({ ...next, me }, storage);
		},
		[me, storage],
	);

	// Talk to the site: send a pending choice if there is one, else read.
	useEffect(() => {
		let cancelled = false;
		const local = state_ref.current;
		const pending = local.pending;
		if (pending) setStatus({ kind: 'saving' });
		sync_appearance(accessToken, pending ?? {}, env)
			.then((server) => {
				if (cancelled) return;
				// A choice made while this request was in flight wins.
				const latest = state_ref.current;
				const sent_all =
					!latest.pending ||
					(latest.pending.mode === pending?.mode && latest.pending.source === pending?.source);
				commit(reconcile(sent_all ? without_pending(latest) : latest, server, sent_all));
				if (pending) setStatus({ kind: 'saved' });
				if (!sent_all) setAttempt((n) => n + 1);
			})
			.catch((err: unknown) => {
				if (cancelled) return;
				if (pending) {
					const code = err instanceof AppearanceError ? err.code : 'server';
					setStatus({ kind: 'device-only', reason: code, stored: read_cache(storage) !== null });
				}
			});
		return (): void => {
			cancelled = true;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps -- runs on mount, on an explicit retry, and after each local change (attempt).
	}, [accessToken, attempt]);

	// Reconnecting retries a pending save.
	useEffect(() => {
		const on_online = (): void => {
			if (state_ref.current.pending) setAttempt((n) => n + 1);
		};
		window.addEventListener('online', on_online);
		return (): void => window.removeEventListener('online', on_online);
	}, []);

	const change = useCallback(
		(patch: { mode?: AppearanceMode; source?: StyleSource }): void => {
			const current = state_ref.current;
			const next: AppearanceState = {
				...current,
				...(patch.mode ? { mode: patch.mode } : {}),
				...(patch.source ? { source: patch.source } : {}),
				pending: { ...current.pending, ...patch },
			};
			// Switching to Outpost Default can drop Site Theme CSS at once;
			// switching to Site Theme waits for the site's CSS.
			if (patch.source === 'outpost' && current.source !== 'outpost') next.css = '';
			commit(next);
			setStatus({ kind: 'saving' });
			setAttempt((n) => n + 1);
		},
		[commit],
	);

	return {
		state,
		scheme,
		status,
		setMode: (mode) => change({ mode }),
		setSource: (source) => change({ source }),
		retry: () => setAttempt((n) => n + 1),
	};
}
