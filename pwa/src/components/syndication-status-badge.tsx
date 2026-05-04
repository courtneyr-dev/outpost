/**
 * SyndicationStatusBadge — composer-side status indicator for a post.
 *
 * Five states (`SyndicationStatus`) match the server's
 * `Outpost_Manual_Share_Status_Computer::compute_status_for_post`:
 *
 *   no_syndication → '—'  (no audit log)
 *   complete       → '✓'  (every entry captured)
 *   pending        → '⏳' (no captures yet)
 *   partial        → '⏳' (mix of captured + pending)
 *   abandoned      → '⚠'  (every entry user-marked abandoned)
 *
 * Hard Contract: zero color values. State conveys via Unicode glyph
 * + visible "X/Y" text + aria-label. Theme styles colors via the
 * `outpost-syndication-badge--{status}` class.
 *
 * Accessibility: `role="img"` + `aria-label` give screen readers the
 * full text-equivalent state. Glyph is `aria-hidden="true"` so AT
 * doesn't double-read.
 */

import { h, type FunctionComponent } from 'preact';

export type SyndicationStatus =
	| 'no_syndication'
	| 'complete'
	| 'partial'
	| 'pending'
	| 'abandoned';

export interface SyndicationSummary {
	total: number;
	complete: number;
	pending: number;
	abandoned: number;
}

export interface SyndicationStatusBadgeProps {
	status: SyndicationStatus;
	summary: SyndicationSummary;
}

const GLYPHS: Record<SyndicationStatus, string> = {
	no_syndication: '—',
	complete:       '✓',
	partial:        '⏳',
	pending:        '⏳',
	abandoned:      '⚠',
};

function display_text( status: SyndicationStatus, summary: SyndicationSummary ): string {
	switch ( status ) {
		case 'complete':
			return `${ summary.total }/${ summary.total }`;
		case 'partial':
			return `${ summary.complete }/${ summary.total }`;
		case 'pending':
			return `0/${ summary.total }`;
		case 'abandoned':
			return 'abandoned';
		case 'no_syndication':
		default:
			return 'none';
	}
}

function aria_label( status: SyndicationStatus, summary: SyndicationSummary ): string {
	switch ( status ) {
		case 'complete':
			return `Syndication complete: ${ summary.total } platforms`;
		case 'partial':
			return `Syndication partial: ${ summary.complete } of ${ summary.total } completed`;
		case 'pending':
			return `Syndication pending: ${ summary.total } platforms`;
		case 'abandoned':
			return `Syndication abandoned: ${ summary.total } platforms`;
		case 'no_syndication':
		default:
			return 'No syndication';
	}
}

export const SyndicationStatusBadge: FunctionComponent<SyndicationStatusBadgeProps> = ( {
	status,
	summary,
} ) => {
	return (
		<span
			class={ `outpost-syndication-badge outpost-syndication-badge--${ status }` }
			data-status={ status }
			role="img"
			aria-label={ aria_label( status, summary ) }
		>
			<span class="outpost-syndication-badge__glyph" aria-hidden="true">
				{ GLYPHS[ status ] }
			</span>{ ' ' }
			<span class="outpost-syndication-badge__text">
				{ display_text( status, summary ) }
			</span>
		</span>
	);
};
