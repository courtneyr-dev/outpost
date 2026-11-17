import type { Ref } from 'preact';
import type { DraftMode } from '../lib/drafts';
import { CONNECTION_GLYPH, CONNECTION_TEXT, type ConnectionState } from './modes/about-tab';
import { POST_VARIANT_LABELS } from './modes/note-mode';
import { REPLY_VARIANT_LABELS } from './modes/reply-mode';
import { DOING_VARIANT_LABELS } from './modes/listen-mode';
import { LIFE_VARIANT_LABELS } from './modes/life-mode';

/**
 * Home: a launcher, not a form. Six cards open the matching composer tab;
 * a compact status line and an About row sit around them.
 *
 * Each card is one native button. Its accessible name is the title (plus
 * "Unsent draft" when that tab holds one); the short description is its
 * accessible description. The full variant inventory, read from the mode
 * files, sits in a disclosure under the grid so the cards stay short.
 *
 * Nothing here holds composer state. The tab panels stay mounted while
 * Home shows, so opening Home never drops text or attached photos.
 */
export interface HomeScreenProps {
	siteHost: string;
	connection: ConnectionState;
	/** Tabs holding a saved text draft for this account. */
	drafts: ReadonlySet<DraftMode>;
	onOpen: (mode: DraftMode) => void;
	onAbout: () => void;
	headingRef?: Ref<HTMLHeadingElement>;
}

interface Card {
	id: DraftMode;
	title: string;
	description: string;
	inventory: string;
	icon: preact.JSX.Element;
}

const svg = {
	viewBox: '0 0 24 24',
	width: 28,
	height: 28,
	fill: 'none',
	stroke: 'currentColor',
	'stroke-width': 1.75,
	'stroke-linecap': 'round' as const,
	'stroke-linejoin': 'round' as const,
	'aria-hidden': 'true' as const,
	focusable: 'false' as const,
};

function list(labels: readonly string[]): string {
	return labels.join(', ');
}

const CARDS: readonly Card[] = [
	{
		id: 'note',
		title: 'Post',
		description: 'Articles, notes, statuses, asides, and quotes',
		inventory: list(POST_VARIANT_LABELS),
		icon: (
			<svg {...svg}>
				<path d="M4 20l1-4L16 5l3 3L8 19z" />
				<path d="M14 7l3 3" />
			</svg>
		),
	},
	{
		id: 'reply',
		title: 'Reply',
		description: 'Replies, likes, reposts, bookmarks, RSVPs, and more',
		inventory: list(REPLY_VARIANT_LABELS),
		icon: (
			<svg {...svg}>
				<path d="M4 5h16v11H9l-5 4z" />
			</svg>
		),
	},
	{
		id: 'photo',
		title: 'Photo',
		description: 'Single photos and galleries',
		inventory: 'Single photo, Gallery',
		icon: (
			<svg {...svg}>
				<path d="M3 8h4l2-3h6l2 3h4v11H3z" />
				<circle cx="12" cy="13" r="3.5" />
			</svg>
		),
	},
	{
		id: 'listen',
		title: 'Doing',
		description: 'Listening, watching, reading, playing, check-ins, and more',
		inventory: list(DOING_VARIANT_LABELS),
		icon: (
			<svg {...svg}>
				<path d="M12 3l9 5-9 5-9-5z" />
				<path d="M3 12l9 5 9-5" />
				<path d="M3 16l9 5 9-5" />
			</svg>
		),
	},
	{
		id: 'life',
		title: 'Life',
		description: 'Moods, weather, sleep, trips, itineraries, and questions',
		inventory: list(LIFE_VARIANT_LABELS),
		icon: (
			<svg {...svg}>
				<path d="M12 20s-8-4.7-8-10.2A4.3 4.3 0 0 1 12 7a4.3 4.3 0 0 1 8 2.8C20 15.3 12 20 12 20z" />
			</svg>
		),
	},
	{
		id: 'recipe',
		title: 'Recipe',
		description: 'Recipes with ingredients and step-by-step instructions',
		inventory: 'Structured recipe: title, ingredients, instructions, yield, total time, and notes',
		icon: (
			<svg {...svg}>
				<path d="M7 14a4 4 0 0 1 .5-8 5 5 0 0 1 9 0A4 4 0 0 1 17 14" />
				<path d="M7 14h10v6H7z" />
			</svg>
		),
	},
];

export function HomeScreen({ siteHost, connection, drafts, onOpen, onAbout, headingRef }: HomeScreenProps): preact.JSX.Element {
	const status = CONNECTION_TEXT[connection];
	return (
		<section class="outpost-home" aria-labelledby="outpost-home-title">
			<p class="outpost-home__status">
				<span class={`outpost-state outpost-state--${connection}`}>
					<span class="outpost-state__glyph" aria-hidden="true">
						{CONNECTION_GLYPH[connection]}
					</span>
					{status.label}
				</span>
				{siteHost && <span class="outpost-home__host">{siteHost}</span>}
				<button type="button" class="outpost-link-button" onClick={onAbout}>
					Details<span class="outpost-visually-hidden"> about the connection, in About</span>
				</button>
			</p>

			<h2 id="outpost-home-title" class="outpost-card__title outpost-home__title" tabIndex={-1} {...(headingRef ? { ref: headingRef } : {})}>
				What would you like to post?
			</h2>

			<ul class="outpost-home__grid">
				{CARDS.map((card) => {
					const has_draft = drafts.has(card.id);
					return (
						<li key={card.id}>
							<button
								type="button"
								class="outpost-home-card"
								data-mode={card.id}
								aria-labelledby={`outpost-home-${card.id}-title${has_draft ? ` outpost-home-${card.id}-draft` : ''}`}
								aria-describedby={`outpost-home-${card.id}-desc`}
								onClick={(): void => onOpen(card.id)}
							>
								<span class="outpost-home-card__icon">{card.icon}</span>
								<span class="outpost-home-card__title" id={`outpost-home-${card.id}-title`}>
									{card.title}
								</span>
								<span class="outpost-home-card__desc" id={`outpost-home-${card.id}-desc`}>
									{card.description}
								</span>
								{has_draft && (
									<span class="outpost-home-card__badge" id={`outpost-home-${card.id}-draft`}>
										Unsent draft
									</span>
								)}
							</button>
						</li>
					);
				})}
			</ul>

			<details class="outpost-disclosure">
				<summary>Everything each tab can post</summary>
				<dl class="outpost-spec-list">
					{CARDS.map((card) => (
						<div key={card.id}>
							<dt>{card.title}</dt>
							<dd>{card.inventory}</dd>
						</div>
					))}
				</dl>
			</details>

			<button type="button" class="outpost-home-about" onClick={onAbout}>
				<svg {...svg} width={24} height={24}>
					<path d="M3 5h7a2 2 0 0 1 2 2v12a2 2 0 0 0-2-2H3z" />
					<path d="M21 5h-7a2 2 0 0 0-2 2v12a2 2 0 0 1 2-2h7z" />
				</svg>
				<span class="outpost-home-about__text">
					<span class="outpost-home-about__title">About Outpost</span>
					<span class="outpost-home-about__desc">Connection, preferences, documentation, and help</span>
				</span>
				<span class="outpost-home-about__chevron" aria-hidden="true">
					›
				</span>
			</button>
		</section>
	);
}
