import { useState } from 'preact/hooks';
import {
	lookup_media,
	MediaLookupError,
	type MediaLookupEnvironment,
	type MediaLookupResult,
} from '../lib/media-lookup';

/**
 * One-tap metadata lookup for the media Doing modes.
 *
 * The user types (or accepts the seeded) title, taps "Look it up", and picks a
 * result to fill the composer's title / creator / cover / URL fields. Data
 * comes from Post Kinds for IndieWeb's lookup APIs via Outpost's same-site
 * proxy (`lookup_media`), the same source behind Post Kinds' "Fetch from
 * TMDB" button — surfaced here for every media kind (Watch/Read/Listen/Jam/
 * Play/Game/Checkin).
 *
 * Lives inside the ListenMode form, so the button is `type="button"` and the
 * input's Enter key preventDefaults — neither must submit the parent form.
 *
 * Hard Contract: this component ships structural CSS only. Every color / font
 * resolves through `var(--outpost-*)`; the theme owns paint.
 */

export interface MediaLookupProps {
	/** Unique-id prefix so multiple lookups can coexist on one page. */
	idPrefix: string;
	/** Composer kind sent to the proxy (watch/read/listen/jam/play/game/checkin). */
	kind: string;
	/** Visible label for the search input (e.g. "Look up a movie"). */
	label: string;
	/** Seed the search box from the mode's current title. */
	initialQuery?: string;
	/** Bearer token used to authenticate the lookup request. */
	accessToken: string;
	/** Fires when the user taps a result. */
	onSelect: (result: MediaLookupResult) => void;
	/** Show the Movie/TV toggle (Watch only) and pass `type` to the lookup. */
	showTypeToggle?: boolean;
	/** Disable interaction (e.g. during form submission). */
	disabled?: boolean;
	/** Injectable environment — tests pass a stubbed fetch. */
	env?: MediaLookupEnvironment;
}

type Status =
	| { kind: 'idle' }
	| { kind: 'searching' }
	| { kind: 'results'; items: MediaLookupResult[] }
	| { kind: 'empty' }
	| { kind: 'not_configured' }
	| { kind: 'error'; message: string };

export function MediaLookup({
	idPrefix,
	kind,
	label,
	initialQuery,
	accessToken,
	onSelect,
	showTypeToggle,
	disabled,
	env,
}: MediaLookupProps) {
	const [query, setQuery] = useState(initialQuery ?? '');
	const [type, setType] = useState<'movie' | 'tv'>('movie');
	const [status, setStatus] = useState<Status>({ kind: 'idle' });

	const searching = status.kind === 'searching';

	const handle_search = async (event?: Event): Promise<void> => {
		event?.preventDefault();
		const q = query.trim();
		if (q.length < 2) {
			return;
		}
		setStatus({ kind: 'searching' });
		try {
			const params = {
				kind,
				query: q,
				accessToken,
				...(showTypeToggle ? { type } : {}),
			};
			const results = env ? await lookup_media(params, env) : await lookup_media(params);
			if (results.length === 0) {
				setStatus({ kind: 'empty' });
				return;
			}
			setStatus({ kind: 'results', items: results });
		} catch (err) {
			if (err instanceof MediaLookupError && err.code === 'not_configured') {
				setStatus({ kind: 'not_configured' });
				return;
			}
			const message =
				err instanceof MediaLookupError
					? err.code === 'post_kinds_inactive'
						? 'Install and activate Post Kinds for IndieWeb to look things up.'
						: err.code === 'rate_limited'
							? 'Too many lookups — try again in a minute.'
							: err.message
					: err instanceof Error
						? err.message
						: 'Lookup failed.';
			setStatus({ kind: 'error', message });
		}
	};

	const handle_pick = (result: MediaLookupResult): void => {
		onSelect(result);
		setStatus({ kind: 'idle' });
	};

	const query_id = `${idPrefix}-media-lookup-query`;
	const type_name = `${idPrefix}-media-lookup-type`;

	return (
		<div class="outpost-media-lookup">
			{showTypeToggle && (
				<fieldset class="outpost-media-lookup__type">
					<legend class="outpost-label">Type</legend>
					<label class="outpost-radio">
						<input
							type="radio"
							name={type_name}
							value="movie"
							checked={type === 'movie'}
							onChange={(): void => setType('movie')}
							disabled={disabled || searching}
						/>
						<span>Movie</span>
					</label>
					<label class="outpost-radio">
						<input
							type="radio"
							name={type_name}
							value="tv"
							checked={type === 'tv'}
							onChange={(): void => setType('tv')}
							disabled={disabled || searching}
						/>
						<span>TV</span>
					</label>
				</fieldset>
			)}

			<label class="outpost-label" for={query_id}>
				{label}
			</label>
			<div class="outpost-form-inline">
				<input
					id={query_id}
					class="outpost-input outpost-media-lookup__input"
					type="search"
					value={query}
					onInput={(event): void => setQuery((event.target as HTMLInputElement).value)}
					onKeyDown={(event): void => {
						if (event.key === 'Enter') {
							event.preventDefault();
							void handle_search();
						}
					}}
					placeholder="Search a title…"
					autoCapitalize="words"
					disabled={disabled || searching}
				/>
				<button
					type="button"
					class="outpost-button outpost-button--secondary outpost-media-lookup__search"
					onClick={(): void => {
						void handle_search();
					}}
					disabled={disabled || searching || query.trim().length < 2}
				>
					{searching ? 'Looking…' : 'Look it up'}
				</button>
			</div>

			{status.kind === 'error' && (
				<p class="outpost-status outpost-status--warn" role="alert">
					{status.message}
				</p>
			)}

			{status.kind === 'empty' && (
				<p class="outpost-status" role="status" aria-live="polite">
					No matches. Try a different search.
				</p>
			)}

			{status.kind === 'not_configured' && (
				<p class="outpost-status" role="status" aria-live="polite">
					This lookup provider isn&rsquo;t configured yet. Add its API key in Post Kinds
					&rarr; API Connections, then try again.
				</p>
			)}

			{status.kind === 'results' && (
				<ul class="outpost-media-lookup__results">
					{status.items.map((item, index) => (
						<li key={item.externalId || item.title || String(index)}>
							<button
								type="button"
								class="outpost-media-lookup__result"
								onClick={(): void => handle_pick(item)}
								disabled={disabled}
							>
								{item.cover ? (
									<img
										class="outpost-media-lookup__cover"
										src={item.cover}
										alt=""
										loading="lazy"
										width={46}
										height={69}
									/>
								) : (
									<span class="outpost-media-lookup__cover outpost-media-lookup__cover--empty" aria-hidden="true" />
								)}
								<span class="outpost-media-lookup__meta">
									<span class="outpost-media-lookup__title">
										{item.title || 'Untitled'}
										{item.year ? <span class="outpost-media-lookup__year"> ({item.year})</span> : null}
									</span>
									{item.creator ? (
										<span class="outpost-media-lookup__creator">{item.creator}</span>
									) : null}
								</span>
							</button>
						</li>
					))}
				</ul>
			)}
		</div>
	);
}
