import { useState } from 'preact/hooks';
import {
	geocode,
	geo_uri,
	GeocodeError,
	type GeocodeResult,
} from '../lib/geocode';

/**
 * Reusable location widget — venue name + optional OpenStreetMap coordinates.
 *
 * Mobile-first IndieWeb posts often want to attach a location, regardless of
 * post kind. v0.1.60 expanded the original geocode-only picker to support
 * both halves of a typical "checkin"-shaped location:
 *
 *   - **Venue name** — always-visible text input. The user can type a free
 *     place name ("the gym", "my kitchen") with no coordinates.
 *   - **OpenStreetMap coordinates** — collapsed disclosure that fires the
 *     server-side Nominatim proxy. When the user picks a result, the venue
 *     name auto-fills with the OSM display name (still editable), and the
 *     parent stores both the GeocodeResult and the venue text.
 *
 * Parent emits these as separate h-entry properties on submit:
 *   - `location: geo:lat,lon` (RFC 5870, only when coordinates were picked)
 *   - `mp-place-name: <venue>` (Outpost-controlled custom property, only
 *     when venue name was filled). The Outpost Micropub bridge persists
 *     this as `_outpost_place_name` post meta.
 *
 * The Doing-tab Checkin/Eat/Drink variants keep their bespoke inline UI
 * because location is the post's primary property there. Everywhere else
 * (Note, Reply, Photo, Life, Recipe, non-checkin Doing variants) uses
 * this component.
 */

export interface GeocodePickerProps {
	/** Used as a unique-id prefix so multiple pickers can coexist on one page. */
	idPrefix: string;
	/** The bearer token used to authenticate the geocode request. */
	accessToken: string;
	/** Currently picked OSM result, or null. Parent owns the state. */
	picked: GeocodeResult | null;
	/** Fires when the user picks an OSM result. Parent stores both the
	 * picked result AND auto-fills `venueName` with the display name. */
	onPick: (result: GeocodeResult) => void;
	/** Fires when the user removes the picked coordinates. */
	onClear: () => void;
	/** Always-visible venue name string. Empty when not tagging. */
	venueName: string;
	/** Fires on every keystroke into the venue name input. */
	onVenueChange: (value: string) => void;
	/** Disable interaction (e.g., during form submission). */
	disabled?: boolean;
}

export function GeocodePicker({
	idPrefix,
	accessToken,
	picked,
	onPick,
	onClear,
	venueName,
	onVenueChange,
	disabled,
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
		// Auto-fill venue name with the OSM display name. User can edit it
		// before posting (e.g., shorten "Big Bend National Park, …, USA"
		// to "Big Bend").
		onVenueChange(result.displayName);
		setResults([]);
		setQuery('');
	};

	const handle_clear_coords = (): void => {
		onClear();
		setQuery('');
		setResults([]);
		setError(null);
	};

	const venue_id = `${idPrefix}-venue-name`;
	const query_id = `${idPrefix}-geocode-query`;

	return (
		<div class="outpost-location-picker">
			<label class="outpost-label" for={venue_id}>
				Venue name (optional)
			</label>
			<input
				id={venue_id}
				class="outpost-input"
				type="text"
				value={venueName}
				onInput={(event): void =>
					onVenueChange((event.target as HTMLInputElement).value)
				}
				placeholder="e.g. Big Bend National Park, my kitchen, the gym"
				disabled={disabled}
				autoCapitalize="words"
			/>

			{picked ? (
				// User has picked OSM coordinates. Show them as a compact
				// row with a "Clear coordinates" button. The venue name
				// stays in its own input above; clearing coords does NOT
				// clear the venue name (the user might still want to keep
				// the typed venue without coordinates).
				<div class="outpost-geocode-picked" aria-live="polite">
					<div class="outpost-geocode-picked__row">
						<span class="outpost-geocode-picked__label">📍 Coordinates: </span>
						<code class="outpost-geocode-picked__coords">
							{geo_uri(picked.lat, picked.lon)}
						</code>
						<button
							type="button"
							class="outpost-button outpost-button--secondary"
							onClick={handle_clear_coords}
							disabled={disabled}
						>
							Clear coordinates
						</button>
					</div>
				</div>
			) : (
				<details class="outpost-collapsible">
					<summary class="outpost-collapsible__summary">
						Add coordinates from OpenStreetMap (optional)
					</summary>
					<div class="outpost-collapsible__body">
						<label class="outpost-label" for={query_id}>
							Search a place
						</label>
						<div class="outpost-form-inline">
							<input
								id={query_id}
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
								disabled={
									disabled || searching || query.trim().length < 2
								}
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
										<li
											key={`${String(result.lat)},${String(result.lon)}`}
										>
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
									<p class="outpost-geocode-attribution">
										{attribution}
									</p>
								)}
							</>
						)}
					</div>
				</details>
			)}
		</div>
	);
}
