/**
 * ReminderRollup — composer surface aggregating all pending
 * syndications across the user's posts.
 *
 * Renders:
 *   - Summary line ("X posts have pending syndications")
 *   - Sorted list (oldest pending first) with "View" buttons that
 *     open the per-post detail view via on_open_post.
 *   - Snooze-all action (rate-limited server-side; UI disables the
 *     button when can_snooze_all=false).
 *
 * Pure presentational. Tests pass synthetic props.
 */

import { type FunctionComponent } from 'preact';
import { useState } from 'preact/hooks';
import { SnoozeMenu, type SnoozeDuration } from './snooze-menu';
import type { PendingPost } from '../lib/manual-share/capture-api';

export interface ReminderRollupProps {
	pending: PendingPost[];
	count: number;
	can_snooze_all: boolean;
	on_open_post: ( post_id: number ) => void;
	on_snooze_all: ( duration: SnoozeDuration ) => Promise<void>;
}

export const ReminderRollup: FunctionComponent<ReminderRollupProps> = ( {
	pending,
	count,
	can_snooze_all,
	on_open_post,
	on_snooze_all,
} ) => {
	const [ snooze_open, set_snooze_open ] = useState( false );

	if ( count === 0 ) {
		return (
			<section class="outpost-reminder-rollup outpost-reminder-rollup--empty">
				<p>No pending syndications.</p>
			</section>
		);
	}

	const sorted = [ ...pending ].sort( ( a, b ) => {
		const a_oldest = oldest_fired_at( a );
		const b_oldest = oldest_fired_at( b );
		return a_oldest - b_oldest;
	} );

	return (
		<section
			class="outpost-reminder-rollup"
			aria-label="Pending syndications rollup"
		>
			<header class="outpost-reminder-rollup__header">
				<h2 class="outpost-reminder-rollup__title">
					🔔 { count } pending { count === 1 ? 'syndication' : 'syndications' }
				</h2>
				{ ! snooze_open && (
					<button
						type="button"
						class="outpost-reminder-rollup__snooze-trigger"
						data-action="open-snooze"
						disabled={ ! can_snooze_all }
						onClick={ () => set_snooze_open( true ) }
					>
						Snooze all
					</button>
				) }
			</header>

			{ snooze_open && (
				<div class="outpost-reminder-rollup__snooze">
					<SnoozeMenu
						on_pick={ async ( duration ) => {
							await on_snooze_all( duration );
							set_snooze_open( false );
						} }
						on_cancel={ () => set_snooze_open( false ) }
					/>
				</div>
			) }

			{ ! can_snooze_all && (
				<p class="outpost-reminder-rollup__rate-limit-hint">
					Snooze all was used recently. Try again in a few minutes.
				</p>
			) }

			<ul class="outpost-reminder-rollup__list">
				{ sorted.map( ( post ) => (
					<li
						key={ post.post_id }
						class="outpost-reminder-rollup__item"
						data-post-id={ post.post_id }
					>
						<span class="outpost-reminder-rollup__post-title">
							{ post.post_title }
						</span>
						<span class="outpost-reminder-rollup__entry-count">
							{ post.entries.length } pending
						</span>
						<button
							type="button"
							class="outpost-reminder-rollup__view-btn"
							data-action="view-post"
							onClick={ () => on_open_post( post.post_id ) }
						>
							View
						</button>
					</li>
				) ) }
			</ul>
		</section>
	);
};

function oldest_fired_at( post: PendingPost ): number {
	let oldest = Number.POSITIVE_INFINITY;
	for ( const entry of post.entries ) {
		const ts = Date.parse( entry.fired_at );
		if ( ! Number.isNaN( ts ) && ts < oldest ) {
			oldest = ts;
		}
	}
	return oldest;
}
