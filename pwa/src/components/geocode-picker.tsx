import { useState } from 'preact/hooks';
import {
	geocode,
	geo_uri,
	GeocodeError,
	type GeocodeResult,
} from '../lib/geocode';

/**
 * Reusable OpenStreetMap geocode lookup widget.
 *
 * Mobile-first IndieWeb posts often want to attach a location, regardless of
 * post kind. The composer's Doing tab has bespoke geocode UI for the
 * Checkin/Eat/Drink variants where location is the post's primary property,
 * but this component lets every other mode (Note, Reply, Photo, Life,
 * Recipe) optionally attach a location to any h-entry by emitting the
 * `location: geo:lat,lon` property.
 *
 * Always optional. Empty state hides everything but the disclosure summary;
 * once the user picks a result, the component renders a compact "Tagged at:
 * <name>" with a Clear button.
 *
 * Wraps a `<details>` so the component is collapsible by default, doesn't
 * occupy vertical space when the user isn't tagging, and works without JS
 * for the open/close affordance.
 */

export interface GeocodePickerProps {
	/** Used as a unique-id prefix so multiple pickers can coexist on one page. */
	idPrefix: string;
	/** The bearer token used to authenticate the geocode request. */
	accessToken: string;
	/** Currently picked result, or null. Parent owns the state. */
	picked: GeocodeResult | null;
	/** Fires when the user picks a result. Parent stores it and reads it on submit. */
	onPick: (result: GeocodeResult) => void;
	/** Fires when the user removes the picked location. */
	onClear: () => void;
	/** Disable interaction (e.g., during form submission). */
	disabled?: boolean;
	/** Optional summary text override; defaults to "Add location (optional)". */
	summary?: string;
}

export function GeocodePicker({
	idPrefix,
	accessToken,
	picked,
	onPick,
	onClear,
	disabled,
	summary,
}: GeocodePickerProps) {
	const [query, setQuery] = useState('');
	const [results, setResults] = useState<GeocodeResult[]>([]);
	const [searching, setSearching] = useState(false);
	const [error, setError] = useState<string | null>(null);
	const [attribution, setAttribution] = useState('');

	const handle_search = async (event?: Event): Promise<void> => {
		event?.preventDefault();
		const q = query.trim();
		if (q.length < 2) return;
		setSearching(true);
		setError(null);
		setResults([]);
		try {
			const response = await geocode({ query: q, accessToken });
			setResults(response.results);
			setAttribution(response.attribution);
			if (response.results.length === 0) {
				setError('No matches. Try a different search.');
			}
		} catch (err) {
			const message =
				err instanceof GeocodeError
					? err.code === 'rate_limited'
						? `Too many lookups — try again in ${String(err.retryAfter ?? 60)}s.`
						: err.message
					: err instanceof Error
						? err.message
						: 'Search failed';
			setError(message);
		} finally {
			setSearching(false);
		}
	};

	const handle_pick = (result: GeocodeResult): void => {
		onPick(result);
		setResults([]);
		setQuery('');
	};

	const handle_clear = (): void => {
		onClear();
		setQuery('');
		setResults([]);
		setError(null);
	};

	// When a location is picked, show a compact summary instead of the search
	// UI. The user can clear and pick another. This keeps the form short.
	if (picked) {
		return (
			<div class="outpost-geocode-picked" aria-live="polite">
				<div class="outpost-geocode-picked__row">
					<span class="outpost-geocode-picked__label">📍 Tagged at: </span>
					<span class="outpost-geocode-picked__value">{picked.displayName}</span>
				</div>
				<div class="outpost-geocode-picked__row">
					<code class="outpost-geocode-picked__coords">
						{geo_uri(picked.lat, picked.lon)}
					</code>
					<button
						type="button"
						class="outpost-button outpost-button--secondary"
						onClick={handle_clear}
						disabled={disabled}
					>
						Clear location
					</button>
				</div>
			</div>
		);
	}

	return (
		<details class="outpost-collapsible">
			<summary class="outpost-collapsible__summary">
				{summary ?? 'Add location (optional)'}
			</summary>
			<div class="outpost-collapsible__body">
				<label class="outpost-label" for={`${idPrefix}-geocode-query`}>
					Search a place
				</label>
				<div class="outpost-form-inline">
					<input
						id={`${idPrefix}-geocode-query`}
						class="outpost-input"
						type="search"
						value={query}
						onInput={(event): void =>
							setQuery((event.target as HTMLInputElement).value)
						}
						onKeyDown={(event): void => {
							if (event.key === 'Enter') {
								event.preventDefault();
								void handle_search();
							}
						}}
						placeholder="e.g. Big Bend National Park"
						disabled={disabled || searching}
					/>
					<button
						type="button"
						class="outpost-button outpost-button--secondary"
						onClick={(): void => {
							void handle_search();
						}}
						disabled={disabled || searching || query.trim().length < 2}
					>
						{searching ? 'Searching…' : 'Search'}
					</button>
				</div>

				{error && (
					<p class="outpost-status outpost-status--warn" role="alert">
						{error}
					</p>
				)}

				{results.length > 0 && (
					<>
						<ul class="outpost-geocode-results">
							{results.map((result) => (
								<li key={`${String(result.lat)},${String(result.lon)}`}>
									<button
										type="button"
										class="outpost-geocode-result"
										onClick={(): void => handle_pick(result)}
										disabled={disabled}
									>
										<span class="outpost-geocode-result__name">
											{result.displayName}
										</span>
										<span class="outpost-geocode-result__coords">
											{result.lat.toFixed(4)},{' '}
											{result.lon.toFixed(4)}
											{result.type ? ` · ${result.type}` : ''}
										</span>
									</button>
								</li>
							))}
						</ul>
						{attribution && (
							<p class="outpost-geocode-attribution">{attribution}</p>
						)}
					</>
				)}
			</div>
		</details>
	);
}
