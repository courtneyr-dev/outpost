import { useEffect, useState } from 'preact/hooks';
import {
	discover_micropub_endpoint,
	discover_media_endpoint,
	upload_media,
	post_h_entry,
	MicropubError,
	type HEntryProperties,
	type MicropubEnvironment,
} from '../../lib/micropub';
import { process_photo, PhotoError } from '../../lib/photo';
import {
	geocode,
	geo_uri,
	GeocodeError,
	type GeocodeResult,
} from '../../lib/geocode';
import { GeocodePicker } from '../geocode-picker';
import { MediaLookup } from '../media-lookup';
import type { MediaLookupResult, MediaLookupEnvironment } from '../../lib/media-lookup';
import {
	MediaPicker,
	all_entries_have_alt,
	type MediaEntry,
} from '../media-picker';
import { is_safe_http_url, is_safe_location_value } from '../../lib/url-validation';
import type { StoredToken } from '../../lib/token-store';
import type { ComposerConfig } from '../../lib/composer-config';
import { enqueue, is_network_error } from '../../lib/offline-queue';
import { mark_posted_once } from '../../lib/install-prompt-state';
import { useMoreOpen } from '../../lib/composer-prefs';
import { peek_share_target, consume_share_target } from '../../lib/share-target';
import type { DoingVariant } from '../../lib/share-target';
import { fetch_source_preview, PreviewError } from '../../lib/preview';
import { VoiceButton } from '../voice-button';
import { Drawer } from '../drawer';
import {
	MorePanel,
	empty_more_values,
	merge_more_values,
	type MorePanelValues,
} from '../more-panel';

/**
 * Listen group — life-tracking posts that point at media or places.
 *
 * Five sub-modes under one tab, picked via radio:
 *   - Listen   → listen-of  (album/track URL)
 *   - Watch    → watch-of   (movie/show URL)
 *   - Read     → read-of    (book identifier URL)
 *   - Play     → play-of    (game URL)
 *   - Checkin  → location   (place URL or geo URI; place name optional)
 *
 * All five share the form shape: URL + optional content. Checkin adds an
 * optional Place name field that gets posted as `name`. None require text.
 *
 * Companion gating: these post kinds are most useful when Post Kinds for
 * IndieWeb is active (it adds proper post-type rendering). Without Post
 * Kinds, the posts still go through Micropub but render as generic notes.
 * Outpost doesn't currently runtime-detect Post Kinds at the PWA level —
 * the tab is always visible. C3b can add a "requires Post Kinds" notice
 * when companion detection is wired client-side.
 */

export interface ListenModeProps {
	token: StoredToken;
	micropubEnv?: MicropubEnvironment;
	composerConfig?: ComposerConfig;
	/** Injectable environment for the metadata lookup (tests pass a stub). */
	mediaLookupEnv?: MediaLookupEnvironment;
}

type Variant =
	| 'listen'
	| 'watch'
	| 'read'
	| 'play'
	| 'game'
	| 'jam'
	| 'checkin'
	| 'eat'
	| 'drink'
	| 'exercise';

type VariantProperty =
	| 'listen-of'
	| 'watch-of'
	| 'read-of'
	| 'play-of'
	| 'location'
	| 'eat-of'
	| 'drink-of'
	| 'exercise';

/**
 * Per-variant feature flags drive conditional rendering and submit-payload
 * shape. Adding a feature is a row-level edit on VARIANTS rather than another
 * `variant === 'x'` branch in JSX — keeps the form rendering uniform.
 */
interface VariantConfig {
	label: string;
	property: VariantProperty;
	targetLabel: string;
	contentLabel: string;
	submitLabel: string;
	/** Whether the target URL is required. Eat/Drink may be context-free. */
	targetRequired: boolean;
	/** Show the per-kind primary input (artist for listen/jam, director for
	 *  watch, author for read, food/drink for eat/drink, place for checkin). */
	personLabel?: string;
	/** Property the personLabel maps to in the Micropub payload. */
	personProperty?: 'author' | 'name';
	/** Show the read-status dropdown (Read kind only). */
	hasReadStatus?: boolean;
	/** Show the rating input (media-consumption kinds). */
	hasRating?: boolean;
	/** Show the title input (Watch primarily). */
	hasTitle?: boolean;
	/** Show the OpenStreetMap geocode lookup (Checkin and Eat/Drink). */
	hasGeocode?: boolean;
	/** Post Kinds metadata lookup category. When set, the mode shows the
	 *  one-tap MediaLookup search that fills title/creator/cover from Post
	 *  Kinds' lookup APIs. Eat/Drink/Exercise have no catalog to search. */
	lookup?: 'video' | 'book' | 'music' | 'game' | 'venue';
}

/** Human label for the MediaLookup search input, per lookup category. */
function lookup_label(category: NonNullable<VariantConfig['lookup']>): string {
	switch (category) {
		case 'video':
			return 'Look up a movie or show';
		case 'book':
			return 'Look up a book';
		case 'music':
			return 'Look up an album or track';
		case 'game':
			return 'Look up a game';
		case 'venue':
			return 'Look up a place';
	}
}

const VARIANTS: Record<Variant, VariantConfig> = {
	listen: {
		label: 'Listen',
		property: 'listen-of',
		targetLabel: 'Track or album URL',
		contentLabel: 'Optional comment',
		submitLabel: 'Post listen',
		targetRequired: true,
		hasTitle: true,
		personLabel: 'Artist',
		personProperty: 'author',
		hasRating: true,
		lookup: 'music',
	},
	watch: {
		label: 'Watch',
		property: 'watch-of',
		targetLabel: 'Movie or show URL',
		contentLabel: 'Body (optional)',
		submitLabel: 'Post watch',
		targetRequired: true,
		hasTitle: true,
		personLabel: 'Director (optional)',
		personProperty: 'author',
		hasRating: true,
		lookup: 'video',
	},
	read: {
		label: 'Read',
		property: 'read-of',
		targetLabel: 'Book URL',
		contentLabel: 'Optional comment',
		submitLabel: 'Post read',
		targetRequired: true,
		hasTitle: true,
		personLabel: 'Author',
		personProperty: 'author',
		hasReadStatus: true,
		hasRating: true,
		lookup: 'book',
	},
	play: {
		label: 'Play',
		property: 'play-of',
		targetLabel: 'Game URL',
		contentLabel: 'Optional comment',
		submitLabel: 'Post play',
		targetRequired: true,
		hasTitle: true,
		hasRating: true,
		lookup: 'game',
	},
	game: {
		label: 'Game',
		// Per Post Kinds, Game and Play share the play-of property — they
		// render with different post-kind labels but the underlying h-entry
		// shape is identical.
		property: 'play-of',
		targetLabel: 'Game URL',
		contentLabel: 'Optional comment',
		submitLabel: 'Post game',
		targetRequired: true,
		hasTitle: true,
		hasRating: true,
		lookup: 'game',
	},
	jam: {
		// Same overlap as Game: Jam and Listen share listen-of, but the post
		// kind label is "Jam" — used when sharing a single track you're
		// currently into rather than logging an album you finished.
		label: 'Jam',
		property: 'listen-of',
		targetLabel: 'Track URL',
		contentLabel: 'Why this track?',
		submitLabel: 'Post jam',
		targetRequired: true,
		hasTitle: true,
		personLabel: 'Artist',
		personProperty: 'author',
		hasRating: true,
		lookup: 'music',
	},
	checkin: {
		label: 'Checkin',
		property: 'location',
		targetLabel: 'Location URL or geo:lat,lon',
		contentLabel: 'Optional note',
		submitLabel: 'Post checkin',
		targetRequired: true,
		personLabel: 'Place name (optional)',
		personProperty: 'name',
		hasGeocode: true,
		lookup: 'venue',
	},
	eat: {
		// Post Kinds maps Eat to `eat-of` (food name string), with optional
		// location URL/coords as a sibling property. The "target URL" here is
		// the optional venue — the food-name input is the post's primary
		// content.
		label: 'Eat',
		property: 'eat-of',
		targetLabel: 'Venue URL or geo:lat,lon (optional)',
		contentLabel: 'Optional note',
		submitLabel: 'Post meal',
		targetRequired: false,
		personLabel: 'What did you eat?',
		personProperty: 'name', // posted as eat-of via property mapping below
		hasGeocode: true,
	},
	drink: {
		label: 'Drink',
		property: 'drink-of',
		targetLabel: 'Venue URL or geo:lat,lon (optional)',
		contentLabel: 'Optional note',
		submitLabel: 'Post drink',
		targetRequired: false,
		personLabel: 'What did you drink?',
		personProperty: 'name',
		hasGeocode: true,
	},
	exercise: {
		// Exercise lives in Doing (not Life) because its primary axis is
		// activity-as-event, with optional location/venue much like a
		// Checkin. The activity name maps to the `exercise` h-entry
		// property; the optional location URL/geo URI maps to `location`.
		label: 'Exercise',
		property: 'exercise',
		targetLabel: 'Venue URL or geo:lat,lon (optional)',
		contentLabel: 'How did it feel? (optional)',
		submitLabel: 'Post exercise',
		targetRequired: false,
		personLabel: 'What activity?',
		personProperty: 'name', // routed to `exercise` via the eat/drink-shaped branch below
		hasGeocode: true,
	},
};

const VARIANT_ORDER: Variant[] = [
	'listen',
	'watch',
	'read',
	'play',
	'game',
	'jam',
	'checkin',
	'eat',
	'drink',
	'exercise',
];

type Status =
	| { kind: 'idle' }
	| { kind: 'discovering-endpoint' }
	| { kind: 'processing-photo' }
	| { kind: 'uploading-photo'; current: number; total: number }
	| { kind: 'posting' }
	| { kind: 'posted'; location?: string }
	| { kind: 'queued' }
	| { kind: 'error'; message: string };

/**
 * Drain the share-target stash only when this Doing tab is the
 * intended target. Mirrors the F6 / Phase E0 pattern used by Note
 * and Reply modes so the stash isn't drained by an unrelated tab.
 *
 * F7 Spotify → variant='listen', F15 YouTube → variant='watch',
 * F16 Goodreads / Readwise → variant='read', etc. The url+content
 * are pre-populated from the share payload so the user lands on the
 * right Doing variant with the URL already in u-listen-of /
 * u-watch-of / u-read-of (per-variant routing inside ListenMode's
 * submit handler).
 */
function consume_share_target_for_doing(): {
	variant?: DoingVariant;
	url?: string;
	content?: string;
	sourceId?: string;
} {
	const data = peek_share_target();
	if (!data || data.tab !== 'listen') return {};
	consume_share_target();
	const out: { variant?: DoingVariant; url?: string; content?: string; sourceId?: string } = {};
	if (data.doingVariant) out.variant = data.doingVariant;
	if (data.url) out.url = data.url;
	if (data.content) out.content = data.content;
	if (data.sourceId) out.sourceId = data.sourceId;
	return out;
}

/**
 * Map Doing-tab variant → WordPress post format. Used by `mp-post-format`
 * so the Post Formats for Block Themes companion classifies the post when
 * Outpost can decide unambiguously from the variant choice alone.
 *
 *   - listen / jam       → audio (always audio — track, album, podcast)
 *   - watch              → video (always video)
 *
 * Variants whose format depends on attached media (Checkin / Eat / Drink /
 * Exercise — may carry images / video / both / neither) intentionally
 * return null. Outpost leaves `mp-post-format` unset so PFBT_Format_Detector
 * (auto-detection re-enabled in PFBT v2.3.0+) decides from the post content
 * on save. Per coordination contract C1: when Outpost DOES set
 * `mp-post-format`, the Micropub bridge calls
 * `PFBT_Format_Detector::mark_as_manual()` so the detector respects
 * Outpost's choice on subsequent saves.
 *
 * Read / Play / Game are URL-anchored without a clean format mapping —
 * also null; user's More-panel selection or site default wins.
 */
function post_format_for_variant(variant: Variant): string | null {
	switch (variant) {
		case 'listen':
		case 'jam':
			return 'audio';
		case 'watch':
			return 'video';
		default:
			return null;
	}
}

/** Streaming hosts WordPress/Post Kinds oEmbed into an inline player. */
const EMBEDDABLE_MEDIA_HOSTS = [
	'open.spotify.com',
	'spotify.link',
	'youtube.com',
	'youtu.be',
	'music.apple.com',
	'soundcloud.com',
	'bandcamp.com',
	'tidal.com',
	'deezer.com',
	'mixcloud.com',
];

/**
 * Whether a target URL points at a streaming provider that oEmbeds into a
 * player showing its own artwork. When it does, the composer suppresses the
 * separately looked-up cover so the art isn't duplicated beneath the card.
 */
function is_embeddable_media_url(url: string): boolean {
	let host: string;
	try {
		host = new URL(url).hostname.toLowerCase();
	} catch {
		return false;
	}
	return EMBEDDABLE_MEDIA_HOSTS.some((provider) => host === provider || host.endsWith('.' + provider));
}

export function ListenMode({ token, micropubEnv, composerConfig, mediaLookupEnv }: ListenModeProps) {
	const initial_share = consume_share_target_for_doing();

	const [variant, setVariant] = useState<Variant>(initial_share.variant ?? 'listen');
	const [target_url, setTargetUrl] = useState(initial_share.url ?? '');
	// Source-id from share-target dispatch — drives the auto-preview
	// fetch on mount and gets passed through to the submission so
	// downstream filters (e.g., post-format inference) can see it.
	const [source_id] = useState(initial_share.sourceId ?? '');
	// Cover URL + publication populated by the auto-preview fetch.
	// User-invisible state — they round-trip into the h-entry submission
	// as `u-photo` and `p-publication` respectively.
	const [cover_url, setCoverUrl] = useState('');
	const [publication, setPublication] = useState('');
	// Unified per-variant primary input. The `personLabel` config decides
	// what the user sees ("Artist" / "Director" / "Author" / "Place name" /
	// "What did you eat?"). The submit handler below routes the value to
	// the appropriate h-entry property based on `personProperty`.
	const [person_name, setPersonName] = useState('');
	const [watch_title, setWatchTitle] = useState('');
	const [read_status, setReadStatus] = useState<'' | 'to-read' | 'reading' | 'finished'>('');
	const [rating, setRating] = useState('');
	const [content, setContent] = useState(initial_share.content ?? '');
	const [status, setStatus] = useState<Status>({ kind: 'idle' });
	const [endpoint, setEndpoint] = useState<string | null>(null);
	const [more_values, setMoreValues] = useState<MorePanelValues>(empty_more_values());
	const [more_open, setMoreOpen] = useMoreOpen();

	// Geocode-search state, used by checkin / eat / drink (any variant whose
	// config has `hasGeocode: true`).
	const [geo_query, setGeoQuery] = useState('');
	const [geo_results, setGeoResults] = useState<GeocodeResult[]>([]);
	const [geo_searching, setGeoSearching] = useState(false);
	const [geo_error, setGeoError] = useState<string | null>(null);
	const [geo_attribution, setGeoAttribution] = useState('');
	// Optional location picker for the non-hasGeocode variants (listen,
	// watch, read, play, game, jam). hasGeocode variants drive location
	// through the inline target_url + person_name flow above; the
	// "everywhere else" pattern matches the picker used in Note / Reply /
	// Photo / Life / Recipe — venue name + optional OSM coordinates.
	const [picked_location, setPickedLocation] = useState<GeocodeResult | null>(null);
	const [venue_name, setVenueName] = useState('');

	// User-attached media for the snapshot-shaped variants (Checkin / Eat /
	// Drink / Exercise — every variant whose config has hasGeocode: true).
	// Per Decision 1 of the 2026-05-13 locked-decisions doc, these variants
	// accept image attachments (file upload via the Micropub media endpoint,
	// same pipeline as PhotoMode) AND a single optional video URL paste (no
	// client-side upload — the URL gets posted verbatim as the `video`
	// h-entry property and downstream consumers handle embedding).
	const [media_entries, setMediaEntries] = useState<MediaEntry[]>([]);
	const [video_url, setVideoUrl] = useState('');
	// Media endpoint is discovered once per session and cached, matching
	// PhotoMode's pattern. Discovery only fires when the submit handler
	// actually needs to upload images.
	const [media_endpoint, setMediaEndpoint] = useState<string | null>(null);

	// Clear user-attached media when switching variants. A user picking a
	// photo for Checkin then switching to Listen would otherwise see the
	// photo "follow" the variant change, which is surprising — and Listen's
	// existing source-preview cover would conflict with it.
	useEffect(() => {
		setMediaEntries((current) => {
			for (const entry of current) {
				URL.revokeObjectURL(entry.preview_url);
			}
			return [];
		});
		setVideoUrl('');
	}, [variant]);

	// Stale geocode results from a previous variant would otherwise re-render
	// when the user comes back to a hasGeocode variant. Clear on switch.
	useEffect(() => {
		setGeoQuery('');
		setGeoResults([]);
		setGeoError(null);
	}, [variant]);

	// Auto-fetch source preview when ListenMode mounts via a share-target
	// dispatch with sourceId set (Spotify/YouTube/etc.). Best-effort:
	// errors are logged but don't surface to the user — pre-fill is a
	// nice-to-have and the user can always type fields manually. Only
	// fires on first mount; subsequent variant switches don't re-fetch.
	useEffect(() => {
		if (!source_id || !target_url) return;
		let cancelled = false;
		(async (): Promise<void> => {
			try {
				const result = await fetch_source_preview({
					url: target_url,
					accessToken: token.accessToken,
					sourceId: source_id,
				});
				if (cancelled) return;
				const e = result.extracted;
				const name = e['p-name'] ?? e.name ?? '';
				const author = e['p-author'] ?? e.author ?? '';
				const photo = e['u-photo'] ?? e.photo ?? '';
				const pub = e['p-publication'] ?? e.publication ?? '';
				if (name && !watch_title) setWatchTitle(name);
				if (author && !person_name) setPersonName(author);
				if (photo) setCoverUrl(photo);
				if (pub) setPublication(pub);
			} catch (err) {
				if (cancelled) return;
				if (typeof console !== 'undefined') {
					const code = err instanceof PreviewError ? err.code : 'unknown';
					console.warn('Outpost: source preview pre-fill failed (' + code + ')');
				}
			}
		})();
		return (): void => {
			cancelled = true;
		};
		// Mount-only effect; intentional empty-ish deps to avoid re-firing
		// on user input. The `source_id` and `target_url` are captured at
		// mount via consume_share_target_for_doing() and don't change in
		// this component's lifetime.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, []);

	const handle_geocode_search = async (event?: Event): Promise<void> => {
		event?.preventDefault();
		const q = geo_query.trim();
		if (q.length < 2) return;
		setGeoSearching(true);
		setGeoError(null);
		setGeoResults([]);
		try {
			const response = await geocode({
				query: q,
				accessToken: token.accessToken,
			});
			setGeoResults(response.results);
			setGeoAttribution(response.attribution);
			if (response.results.length === 0) {
				setGeoError('No matches. Try a different search.');
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
			setGeoError(message);
		} finally {
			setGeoSearching(false);
		}
	};

	const handle_geocode_pick = (result: GeocodeResult): void => {
		// Fill the URL field with a geo: URI (RFC 5870). For Checkin we also
		// fill the place name from the OSM display name; for Eat/Drink we
		// leave the food/drink name alone (the user's already typed what
		// they're eating; the location is contextual).
		setTargetUrl(geo_uri(result.lat, result.lon));
		if (variant === 'checkin') {
			setPersonName(result.displayName);
		}
		setGeoResults([]);
		setGeoQuery('');
	};

	// Fill composer fields from a Post Kinds metadata lookup result. Variant-
	// aware: title-bearing kinds (watch/read/listen/jam/play/game) route the
	// title to the Title field and creator to the person input (artist /
	// director / author); Checkin has no Title field, so the result title is
	// the place name (person input). Cover art and a canonical URL (when the
	// provider returns one) fill too; an empty result.url keeps whatever the
	// user typed. The referenced poster URL is NOT uploaded to the media
	// library — it rides through as `u-photo` verbatim (Courtney's call).
	const handle_lookup_select = (result: MediaLookupResult): void => {
		const config_for_select = VARIANTS[variant];
		if (config_for_select.hasTitle) {
			if (result.title) setWatchTitle(result.title);
			if (result.creator) setPersonName(result.creator);
		} else if (result.title) {
			setPersonName(result.title);
		}
		if (result.cover) setCoverUrl(result.cover);
		if (result.url) setTargetUrl(result.url);
		setStatus({ kind: 'idle' });
	};

	const config = VARIANTS[variant];
	const a11y_active = composerConfig?.companions['accessibility-checker'] === 'active';
	const trimmed_target_url_for_xfn = target_url.trim();

	const handle_submit = async (event: Event): Promise<void> => {
		event.preventDefault();
		const trimmed_content = content.trim();
		const trimmed_url = target_url.trim();
		const trimmed_person = person_name.trim();
		const trimmed_watch_title = watch_title.trim();
		const trimmed_rating = rating.trim();

		// Eat/Drink can be context-free (just a food/drink name with no URL).
		// Checkin and the URL-anchored kinds still require something in the
		// target field. For Eat/Drink the food/drink input is the required
		// signal; for everything else the URL is.
		if (config.targetRequired) {
			if (!trimmed_url) return;
		} else if (!trimmed_person) {
			return; // Eat/Drink needs at least the food/drink name.
		}

		// URL validation only applies when a URL was actually entered.
		// Checkin and Eat/Drink accept geo:lat,lon URIs (from OSM lookup) as
		// well as http(s); the other variants are URL-only.
		if (trimmed_url) {
			const accepts_geo =
				variant === 'checkin' ||
				variant === 'eat' ||
				variant === 'drink' ||
				variant === 'exercise';
			const url_ok = accepts_geo
				? is_safe_location_value(trimmed_url)
				: is_safe_http_url(trimmed_url);
			if (!url_ok) {
				const message = accepts_geo
					? 'Location must be http://, https://, or a geo:lat,lon URI.'
					: 'URL must be http:// or https://.';
				setStatus({ kind: 'error', message });
				return;
			}
		}

		// Rating sanity: numeric, 1-5 inclusive. Empty is valid (no rating).
		// Gated by hasRating: shared component state can carry a stale rating
		// from a previous variant — the 2026-07-02 Tier 2 pass hit a Listen
		// rating error surfacing on Drink, which has no rating field.
		if (config.hasRating && trimmed_rating !== '') {
			const r = Number(trimmed_rating);
			if (!Number.isFinite(r) || r < 1 || r > 5) {
				setStatus({ kind: 'error', message: 'Rating must be a number between 1 and 5.' });
				return;
			}
		}

		// Media + video inputs only render for hasGeocode (snapshot) variants;
		// scope their state the same way so values left over from another
		// variant never validate or submit here.
		const active_media = config.hasGeocode ? media_entries : [];
		const trimmed_video_url = config.hasGeocode ? video_url.trim() : '';

		// Alt-text discipline for user-attached photos. Mirrors PhotoMode's
		// rule: every entry must have alt text OR be marked decorative.
		if (active_media.length > 0 && !all_entries_have_alt(active_media)) {
			setStatus({
				kind: 'error',
				message:
					'Every photo needs alt text, or mark it decorative. Decorative photos submit empty alt to indicate they\'re purely visual.',
			});
			return;
		}

		// Video URL: optional, but if provided must be a safe http(s) URL.
		if (trimmed_video_url !== '' && !is_safe_http_url(trimmed_video_url)) {
			setStatus({ kind: 'error', message: 'Video URL must be http:// or https://.' });
			return;
		}

		try {
			let micropub_endpoint = endpoint;
			if (!micropub_endpoint) {
				setStatus({ kind: 'discovering-endpoint' });
				micropub_endpoint = await discover_micropub_endpoint(token.me, micropubEnv);
				setEndpoint(micropub_endpoint);
			}

			// Process + upload any user-attached photos. The pipeline mirrors
			// PhotoMode: EXIF strip + canvas downscale + JPEG re-encode →
			// per-photo POST to the Micropub media endpoint → collect Location
			// header URLs for the final h-entry submission.
			let uploaded_photo_urls: string[] = [];
			let alt_values: string[] = [];
			if (active_media.length > 0) {
				setStatus({ kind: 'processing-photo' });
				const processed_blobs: Blob[] = [];
				for (const entry of active_media) {
					const processed = await process_photo(entry.file);
					processed_blobs.push(processed.blob);
				}

				let resolved_media_endpoint = media_endpoint;
				if (!resolved_media_endpoint) {
					setStatus({ kind: 'discovering-endpoint' });
					resolved_media_endpoint = await discover_media_endpoint(
						micropub_endpoint,
						token.accessToken,
						micropubEnv,
					);
					setMediaEndpoint(resolved_media_endpoint);
				}

				for (let i = 0; i < processed_blobs.length; i++) {
					setStatus({
						kind: 'uploading-photo',
						current: i + 1,
						total: processed_blobs.length,
					});
					const upload = await upload_media(
						{
							blob: processed_blobs[i]!,
							filename: `photo-${String(i + 1)}.jpg`,
							accessToken: token.accessToken,
							mediaEndpoint: resolved_media_endpoint,
						},
						micropubEnv,
					);
					uploaded_photo_urls.push(upload.location);
				}
				alt_values = active_media.map((e) =>
					e.decorative ? '' : e.alt.trim(),
				);
			}

			// Build the h-entry properties. Routing is variant-aware:
			//
			//   - URL-anchored (listen, watch, read, play, game, jam):
			//     target URL → config.property; person → personProperty
			//     (artist/director/author).
			//   - checkin: target URL → location; person → name (place).
			//   - eat / drink: person → eat-of/drink-of (the food/drink IS
			//     the post kind's primary property); target URL → location
			//     (optional venue).
			const base: HEntryProperties = {};

			if (variant === 'eat' || variant === 'drink' || variant === 'exercise') {
				// Body-shaped variants: the personLabel input is the post's primary
				// content (food / drink / activity), routed to config.property
				// (`eat-of` / `drink-of` / `exercise`). Optional target_url goes
				// to `location` as the venue/place where it happened.
				if (trimmed_person) {
					base[config.property] = trimmed_person;
				}
				if (trimmed_url) {
					base.location = trimmed_url;
				}
			} else {
				base[config.property] = trimmed_url;
				// Watch's standalone title input maps to h-entry name.
				if (config.hasTitle && trimmed_watch_title) {
					base.name = trimmed_watch_title;
				}
				// Person input — checkin uses h-entry name, others use author.
				if (config.personProperty && trimmed_person) {
					if (config.personProperty === 'name' && !base.name) {
						base.name = trimmed_person;
					} else if (config.personProperty === 'author') {
						base.author = trimmed_person;
					}
				}
			}

			if (trimmed_content) {
				base.content = trimmed_content;
			}
			if (config.hasReadStatus && read_status !== '') {
				base['read-status'] = read_status;
			}
			if (config.hasRating && trimmed_rating !== '') {
				base.rating = trimmed_rating;
			}
			// Photo property: user-attached uploads win over source-preview
			// cover. hasGeocode variants (Checkin / Eat / Drink / Exercise)
			// always have cover_url empty (no source-preview flow), so the
			// fallback only matters for URL-anchored variants like Listen /
			// Watch / Read where cover_url comes from Spotify/YouTube oEmbed.
			if (uploaded_photo_urls.length > 0) {
				base.photo =
					uploaded_photo_urls.length === 1
						? uploaded_photo_urls[0]!
						: uploaded_photo_urls;
				base['mp-photo-alt'] =
					alt_values.length === 1 ? alt_values[0]! : alt_values;
			} else if (cover_url && !is_embeddable_media_url(trimmed_url)) {
				// Skip the looked-up cover when the target is a streaming URL
				// (Spotify / YouTube / Apple Music …): Post Kinds oEmbeds the
				// URL into a player that already shows album art, so attaching
				// the cover too duplicated it as a stray gallery below the card.
				base.photo = cover_url;
			}
			// Video URL: paste-only (no client-side upload pipeline). Submitted
			// verbatim as the Micropub spec's standard `video` property;
			// downstream rendering (Post Kinds, theme templates) decides
			// embedding.
			if (trimmed_video_url) {
				base.video = trimmed_video_url;
			}
			if (publication) {
				base['p-publication'] = publication;
			}
			// Variant → WP post format. listen/jam → audio, watch → video,
			// checkin → status. Other variants leave mp-post-format unset
			// so the user's More-panel selection (or site default) wins.
			const auto_format = post_format_for_variant(variant);
			if (auto_format) {
				base['mp-post-format'] = auto_format;
			}
			// Picker-driven location for non-hasGeocode variants. hasGeocode
			// variants already wrote `location` (or eat-of/drink-of) above
			// from the inline UI; don't double-write.
			if (!config.hasGeocode && picked_location) {
				base.location = geo_uri(picked_location.lat, picked_location.lon);
			}
			// Venue name (from picker) on non-hasGeocode variants. For
			// hasGeocode variants the venue lives in person_name (mapped
			// to h-entry `name` for checkin or `eat-of`/`drink-of` for
			// eat/drink), so don't double-write.
			const trimmed_venue_name = venue_name.trim();
			if (!config.hasGeocode && trimmed_venue_name) {
				base['mp-place-name'] = trimmed_venue_name;
			}
			const properties = merge_more_values(base, more_values, trimmed_url);

			setStatus({ kind: 'posting' });
			try {
				const result = await post_h_entry(
					{
						properties,
						accessToken: token.accessToken,
						micropubEndpoint: micropub_endpoint,
					},
					micropubEnv,
				);
				setStatus({
					kind: 'posted',
					...(result.location ? { location: result.location } : {}),
				});
				mark_posted_once();
				setTargetUrl('');
				setPersonName('');
				setWatchTitle('');
				setReadStatus('');
				setRating('');
				setContent('');
				setMoreValues(empty_more_values());
				setPickedLocation(null);
				setVenueName('');
				for (const entry of media_entries) {
					URL.revokeObjectURL(entry.preview_url);
				}
				setMediaEntries([]);
				setVideoUrl('');
				return;
			} catch (post_err) {
				if (is_network_error(post_err)) {
					try {
						await enqueue({
							source: 'listen',
							properties,
							accessToken: token.accessToken,
							micropubEndpoint: micropub_endpoint,
						});
						setStatus({ kind: 'queued' });
						setTargetUrl('');
						setPersonName('');
						setWatchTitle('');
						setReadStatus('');
						setRating('');
						setContent('');
						setMoreValues(empty_more_values());
						for (const entry of media_entries) {
							URL.revokeObjectURL(entry.preview_url);
						}
						setMediaEntries([]);
						setVideoUrl('');
						return;
					} catch (_q_err) {
						// Queue write failed; fall through to error display.
					}
				}
				throw post_err;
			}
		} catch (err) {
			const message =
				err instanceof PhotoError
					? err.code + ': ' + err.message
					: err instanceof MicropubError
						? err.code + ': ' + err.message
						: err instanceof Error
							? err.message
							: 'Unknown error';
			setStatus({ kind: 'error', message });
		}
	};

	const submitting =
		status.kind === 'discovering-endpoint' ||
		status.kind === 'processing-photo' ||
		status.kind === 'uploading-photo' ||
		status.kind === 'posting';
	const submit_label =
		status.kind === 'discovering-endpoint'
			? 'Finding endpoint…'
			: status.kind === 'processing-photo'
				? 'Preparing photos…'
				: status.kind === 'uploading-photo'
					? `Uploading ${String(status.current)} of ${String(status.total)}…`
					: status.kind === 'posting'
						? 'Posting…'
						: config.submitLabel;
	// Variant-aware enable: URL-anchored kinds (listen/watch/etc.) need a URL;
	// content-shaped kinds (eat/drink) need their primary text input. Without
	// this branch, eat/drink users with a food name typed in but no venue URL
	// see a permanently-disabled submit and a `required` browser-validation
	// block — caught by both the code review and a11y audit.
	//
	// hasGeocode variants with attached media also gate on alt-text discipline:
	// every photo must have alt text OR be marked decorative before submit
	// enables. This matches PhotoMode's contract.
	const base_can_submit = config.targetRequired
		? !!target_url.trim()
		: !!person_name.trim();
	const media_complete =
		!config.hasGeocode ||
		media_entries.length === 0 ||
		all_entries_have_alt(media_entries);
	const can_submit = base_can_submit && media_complete;

	return (
		<section class="outpost-card" aria-labelledby="outpost-listen-mode-title">
			<h2 id="outpost-listen-mode-title" class="outpost-card__title">
				Doing
			</h2>
			<p class="outpost-card__lede">
				Signed in as <code>{token.me || '—'}</code>
			</p>

			<form class="outpost-form-row" onSubmit={handle_submit} novalidate>
				<fieldset class="outpost-variant-picker">
					<legend class="outpost-label">Type</legend>
					{VARIANT_ORDER.map((id) => (
						<label key={id} class="outpost-radio">
							<input
								type="radio"
								name="outpost-listen-variant"
								value={id}
								checked={variant === id}
								onChange={(): void => {
									setVariant(id);
									// A success/error banner belongs to the submission
									// that produced it. Left in place across a variant
									// switch it reads as "this variant posted" — the
									// 2026-07-02 UX pass mistook a lingering Listen
									// success for a Checkin one.
									setStatus({ kind: 'idle' });
								}}
								disabled={submitting}
							/>
							<span>{VARIANTS[id].label}</span>
						</label>
					))}
				</fieldset>

				<label class="outpost-label" for="outpost-listen-target">
					{config.targetLabel}
				</label>
				<input
					id="outpost-listen-target"
					class="outpost-input"
					// Variants whose target field can carry a `geo:` URI need
					// type="text" — browsers reject `geo:` for `type="url"`.
					// All hasGeocode variants accept geo (Checkin / Eat / Drink /
					// Exercise); URL-anchored variants stay type="url".
					type={config.hasGeocode ? 'text' : 'url'}
					value={target_url}
					onInput={(event): void => setTargetUrl((event.target as HTMLInputElement).value)}
					placeholder={
						config.hasGeocode
							? 'https://example.com/place/ or geo:29.12,-103.24'
							: 'https://example.com/…'
					}
					inputMode="url"
					autoComplete="url"
					autoCapitalize="none"
					spellcheck={false}
					required={config.targetRequired}
				/>

				{config.lookup && (
					<MediaLookup
						idPrefix={`outpost-listen-${variant}`}
						kind={variant}
						label={lookup_label(config.lookup)}
						initialQuery={watch_title || person_name}
						accessToken={token.accessToken}
						onSelect={handle_lookup_select}
						showTypeToggle={config.lookup === 'video'}
						disabled={submitting}
						{...(mediaLookupEnv ? { env: mediaLookupEnv } : {})}
					/>
				)}

				{config.hasTitle && (
					<>
						<label class="outpost-label" for="outpost-listen-watch-title">
							Title (optional)
						</label>
						<input
							id="outpost-listen-watch-title"
							class="outpost-input"
							type="text"
							value={watch_title}
							onInput={(event): void =>
								setWatchTitle((event.target as HTMLInputElement).value)
							}
							disabled={submitting}
							placeholder="e.g., The Bear S2E3"
						/>
					</>
				)}

				{config.personLabel && (
					<>
						<label class="outpost-label" for="outpost-listen-person">
							{config.personLabel}
							{!config.targetRequired && (
								<>
									{' '}
									<span class="outpost-required">(required)</span>
								</>
							)}
						</label>
						<input
							id="outpost-listen-person"
							class="outpost-input"
							type="text"
							value={person_name}
							onInput={(event): void =>
								setPersonName((event.target as HTMLInputElement).value)
							}
							disabled={submitting}
							required={!config.targetRequired}
						/>
					</>
				)}

				{config.hasReadStatus && (
					<>
						<label class="outpost-label" for="outpost-listen-read-status">
							Reading status
						</label>
						<select
							id="outpost-listen-read-status"
							class="outpost-input"
							value={read_status}
							onChange={(event): void =>
								setReadStatus(
									(event.target as HTMLSelectElement)
										.value as typeof read_status,
								)
							}
							disabled={submitting}
						>
							<option value="">Not specified</option>
							<option value="to-read">To read</option>
							<option value="reading">Currently reading</option>
							<option value="finished">Finished</option>
						</select>
					</>
				)}

				{config.hasRating && (
					<>
						<label class="outpost-label" for="outpost-listen-rating">
							Rating (1–5, optional)
						</label>
						<input
							id="outpost-listen-rating"
							class="outpost-input"
							type="number"
							min={1}
							max={5}
							step={1}
							inputMode="numeric"
							value={rating}
							onInput={(event): void =>
								setRating((event.target as HTMLInputElement).value)
							}
							disabled={submitting}
						/>
					</>
				)}

				{config.hasGeocode && (
					<>
						<details class="outpost-collapsible">
							<summary class="outpost-collapsible__summary">
								Look up on OpenStreetMap
							</summary>
							<div class="outpost-collapsible__body">
								<label
									class="outpost-label"
									for="outpost-listen-geocode-query"
								>
									Search a place
								</label>
								<div class="outpost-form-inline">
									<input
										id="outpost-listen-geocode-query"
										class="outpost-input"
										type="search"
										value={geo_query}
										onInput={(event): void =>
											setGeoQuery((event.target as HTMLInputElement).value)
										}
										onKeyDown={(event): void => {
											if (event.key === 'Enter') {
												event.preventDefault();
												void handle_geocode_search();
											}
										}}
										placeholder="e.g. Big Bend National Park"
										disabled={submitting || geo_searching}
									/>
									<button
										type="button"
										class="outpost-button outpost-button--secondary"
										onClick={(): void => {
											void handle_geocode_search();
										}}
										disabled={
											submitting ||
											geo_searching ||
											geo_query.trim().length < 2
										}
									>
										{geo_searching ? 'Searching…' : 'Search'}
									</button>
								</div>

								{geo_error && (
									<p class="outpost-status outpost-status--warn" role="alert">
										{geo_error}
									</p>
								)}

								{geo_results.length > 0 && (
									<>
										<ul class="outpost-geocode-results">
											{geo_results.map((result) => (
												<li
													key={`${String(result.lat)},${String(result.lon)}`}
												>
													<button
														type="button"
														class="outpost-geocode-result"
														onClick={(): void => handle_geocode_pick(result)}
														disabled={submitting}
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
										{geo_attribution && (
											<p class="outpost-geocode-attribution">
												{geo_attribution}
											</p>
										)}
									</>
								)}
							</div>
						</details>
					</>
				)}

				{config.hasGeocode && (
					<>
						<MediaPicker
							entries={media_entries}
							onChange={setMediaEntries}
							disabled={submitting}
							idPrefix={`outpost-listen-${variant}-media`}
							emptyLabel="Attach a photo (optional)"
							nonEmptyLabel="Add more photos"
						/>

						<label class="outpost-label" for="outpost-listen-video-url">
							Video URL (optional)
						</label>
						<input
							id="outpost-listen-video-url"
							class="outpost-input"
							type="url"
							value={video_url}
							onInput={(event): void =>
								setVideoUrl((event.target as HTMLInputElement).value)
							}
							placeholder="https://… (YouTube, Vimeo, direct .mp4)"
							inputMode="url"
							autoComplete="url"
							autoCapitalize="none"
							spellcheck={false}
							disabled={submitting}
						/>
					</>
				)}

				<div class="outpost-textarea-row">
					<label class="outpost-label" for="outpost-listen-content">
						{config.contentLabel}
					</label>
					<VoiceButton
						onTranscript={(text): void =>
							setContent((c) =>
								c.length > 0 && !/\s$/.test(c) ? c + ' ' + text : c + text,
							)
						}
						disabled={submitting}
					/>
				</div>
				<textarea
					id="outpost-listen-content"
					class="outpost-textarea"
					rows={3}
					value={content}
					onInput={(event): void =>
						setContent((event.target as HTMLTextAreaElement).value)
					}
					disabled={submitting}
				/>

				{!config.hasGeocode && (
					<GeocodePicker
						idPrefix="outpost-listen"
						accessToken={token.accessToken}
						picked={picked_location}
						onPick={setPickedLocation}
						onClear={(): void => setPickedLocation(null)}
						venueName={venue_name}
						onVenueChange={setVenueName}
						disabled={submitting}
					/>
				)}

				{/* Live regions are rendered unconditionally so iOS VoiceOver
				    reliably picks up announcements; previously these mounted
				    only on state change, which the iOS AT misses. The empty
				    string content stays in the DOM as the no-op state. */}
				<div
					class="outpost-error"
					role="alert"
					aria-live="assertive"
					hidden={status.kind !== 'error'}
				>
					{status.kind === 'error' ? status.message : ''}
				</div>

				<p
					class="outpost-status"
					aria-live="polite"
					hidden={status.kind !== 'posted' && status.kind !== 'queued'}
				>
					{status.kind === 'posted' ? (
						status.location ? (
							<>
								Posted to{' '}
								<a href={status.location} target="_blank" rel="noopener noreferrer">
									{status.location}
								</a>
								{a11y_active && (
									<>
										{' · '}
										<a
											href={`${status.location}?edac_view=1`}
											target="_blank"
											rel="noopener noreferrer"
										>
											View accessibility report
										</a>
									</>
								)}
							</>
						) : (
							'Posted, but the server did not return a link. Check your site to confirm it published.'
						)
					) : status.kind === 'queued' ? (
						"Saved for later. Outpost will post this when you're back online."
					) : (
						''
					)}
				</p>

				{composerConfig && (
					<Drawer
						open={more_open}
						onClose={(): void => setMoreOpen(false)}
						title="More options"
					>
						<MorePanel
							token={token}
							composerConfig={composerConfig}
							values={more_values}
							onChange={setMoreValues}
							micropubEndpoint={endpoint}
							{...(micropubEnv ? { micropubEnv } : {})}
							xfnTargetUrl={trimmed_target_url_for_xfn || null}
							disabled={submitting}
							idPrefix="outpost-listen"
						/>
					</Drawer>
				)}

				<div class="outpost-form-actions">
					<button
						class="outpost-button"
						type="submit"
						disabled={submitting || !can_submit}
					>
						{submit_label}
					</button>
					{composerConfig && (
						<button
							class="outpost-button outpost-button--secondary"
							type="button"
							onClick={(): void => setMoreOpen(true)}
							disabled={submitting}
						>
							More options
						</button>
					)}
				</div>
			</form>
		</section>
	);
}
