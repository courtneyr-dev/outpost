/**
 * SyndicationDetailView — per-post detail view of the manual-share
 * audit log. Lists every entry (complete, pending, abandoned) with
 * the appropriate per-entry actions:
 *
 *   - Complete entries: show silo URL link + strategy.
 *   - Pending entries:  show Add URL button + Snooze menu trigger
 *                        (renders {@link SyndicationCaptureForm} +
 *                        {@link SnoozeMenu} inline).
 *   - Abandoned entries: show "Add URL anyway" + "Forget reminder"
 *                        (un-abandon by clearing
 *                        reminder_dismissed_until).
 *
 * Pure component with injected callbacks for snooze + capture +
 * forget-reminder actions. Tests pass synthetic handlers; production
 * wires to the F12 capture-api + F13 dismiss-reminder endpoints.
 *
 * Hard Contract: zero color values; structural classes only.
 */

import { type FunctionComponent } from 'preact';
import { useState } from 'preact/hooks';
import { SnoozeMenu, type SnoozeDuration } from './snooze-menu';
import { SyndicationCaptureForm } from './syndication-capture-form';
import type { CaptureApiEnvironment, PendingEntry } from '../lib/manual-share/capture-api';

export interface DetailEntry extends PendingEntry {
	reminder_dismissed_until?: string | null;
}

const ABANDONED_THRESHOLD_YEARS = 10;

function is_abandoned( entry: DetailEntry ): boolean {
	const until = entry.reminder_dismissed_until;
	if ( ! until ) {
		return false;
	}
	const ts = Date.parse( until );
	if ( Number.isNaN( ts ) ) {
		return false;
	}
	const cutoff = Date.now() + ABANDONED_THRESHOLD_YEARS * 365 * 24 * 3600 * 1000;
	return ts > cutoff;
}

function entry_state( entry: DetailEntry ): 'complete' | 'abandoned' | 'pending' {
	if ( entry.completed_at ) {
		return 'complete';
	}
	if ( is_abandoned( entry ) ) {
		return 'abandoned';
	}
	return 'pending';
}

const PLATFORM_LABELS: Record<string, string> = {
	'instagram-feed':    'Instagram',
	'instagram-stories': 'Instagram Stories',
	facebook:            'Facebook',
	'x-twitter':         'X',
	linkedin:            'LinkedIn',
	threads:             'Threads',
	tiktok:              'TikTok',
	pinterest:           'Pinterest',
	'reddit-manual':     'Reddit',
	'flickr-manual':     'Flickr',
};

function platform_label( id: string ): string {
	return PLATFORM_LABELS[ id ] ?? id.replace( /-/g, ' ' ).replace( /\b\w/g, ( c ) => c.toUpperCase() );
}

function format_time( iso: string ): string {
	const ts = Date.parse( iso );
	if ( Number.isNaN( ts ) ) {
		return iso;
	}
	return new Date( ts ).toLocaleString();
}

export interface SyndicationDetailViewProps {
	post_id: number;
	post_title: string;
	posted_at?: string;
	entries: DetailEntry[];
	api_env: CaptureApiEnvironment;
	on_snooze: ( post_id: number, audit_log_id: string, duration: SnoozeDuration ) => Promise<void>;
	on_un_abandon: ( post_id: number, audit_log_id: string ) => Promise<void>;
	on_entry_recorded?: ( post_id: number, audit_log_id: string, silo_url: string ) => void;
}

export const SyndicationDetailView: FunctionComponent<SyndicationDetailViewProps> = ( {
	post_id,
	post_title,
	posted_at,
	entries,
	api_env,
	on_snooze,
	on_un_abandon,
	on_entry_recorded,
} ) => {
	type RowMode = 'idle' | 'capture' | 'snooze';
	const [ row_modes, set_row_modes ] = useState<Record<string, RowMode>>( {} );

	const set_mode = ( id: string, mode: RowMode ) => {
		set_row_modes( ( prev ) => ( { ...prev, [ id ]: mode } ) );
	};

	return (
		<section
			class="outpost-syndication-detail"
			aria-label="Syndications for this post"
		>
			<header class="outpost-syndication-detail__header">
				<h2 class="outpost-syndication-detail__title">
					Syndications for &ldquo;{ post_title }&rdquo;
				</h2>
				{ posted_at && (
					<p class="outpost-syndication-detail__posted-at">
						Posted { format_time( posted_at ) }
					</p>
				) }
			</header>

			<ul class="outpost-syndication-detail__list">
				{ entries.map( ( entry ) => {
					const state = entry_state( entry );
					const mode  = row_modes[ entry.id ] ?? 'idle';
					return (
						<li
							key={ entry.id }
							class={ `outpost-syndication-detail__item outpost-syndication-detail__item--${ state }` }
							data-audit-log-id={ entry.id }
							data-state={ state }
						>
							<div class="outpost-syndication-detail__row">
								<span class="outpost-syndication-detail__platform">
									{ platform_label( entry.platform_id ) }
								</span>
								<span class="outpost-syndication-detail__time">
									{ format_time( entry.fired_at ) }
								</span>
								<span
									class="outpost-syndication-detail__state"
									data-state={ state }
								>
									{ state === 'complete' && '✓ Complete' }
									{ state === 'pending' && '⏳ Pending' }
									{ state === 'abandoned' && '⚠ Abandoned' }
								</span>
							</div>

							{ state === 'complete' && entry.silo_url && (
								<a
									class="outpost-syndication-detail__silo-link"
									href={ entry.silo_url }
									rel="noopener noreferrer"
									target="_blank"
								>
									{ entry.silo_url }
								</a>
							) }

							<div class="outpost-syndication-detail__strategy">
								Strategy: { entry.strategy }
							</div>

							{ state === 'pending' && mode === 'idle' && (
								<div class="outpost-syndication-detail__actions">
									<button
										type="button"
										class="outpost-syndication-detail__action"
										data-action="add-url"
										onClick={ () => set_mode( entry.id, 'capture' ) }
									>
										Add URL…
									</button>
									<button
										type="button"
										class="outpost-syndication-detail__action"
										data-action="snooze"
										onClick={ () => set_mode( entry.id, 'snooze' ) }
									>
										Snooze
									</button>
								</div>
							) }

							{ state === 'pending' && mode === 'capture' && (
								<SyndicationCaptureForm
									post_id={ post_id }
									audit_log_id={ entry.id }
									platform_id={ entry.platform_id }
									platform_label={ platform_label( entry.platform_id ) }
									api_env={ api_env }
									on_recorded={ ( silo_url ) => {
										set_mode( entry.id, 'idle' );
										on_entry_recorded?.( post_id, entry.id, silo_url );
									} }
									on_cancel={ () => set_mode( entry.id, 'idle' ) }
								/>
							) }

							{ state === 'pending' && mode === 'snooze' && (
								<SnoozeMenu
									on_pick={ async ( duration ) => {
										await on_snooze( post_id, entry.id, duration );
										set_mode( entry.id, 'idle' );
									} }
									on_cancel={ () => set_mode( entry.id, 'idle' ) }
								/>
							) }

							{ state === 'abandoned' && mode === 'idle' && (
								<div class="outpost-syndication-detail__actions">
									<button
										type="button"
										class="outpost-syndication-detail__action"
										data-action="add-url-anyway"
										onClick={ () => set_mode( entry.id, 'capture' ) }
									>
										Add URL anyway…
									</button>
									<button
										type="button"
										class="outpost-syndication-detail__action"
										data-action="forget-reminder"
										onClick={ () => void on_un_abandon( post_id, entry.id ) }
									>
										Forget reminder
									</button>
								</div>
							) }

							{ state === 'abandoned' && mode === 'capture' && (
								<SyndicationCaptureForm
									post_id={ post_id }
									audit_log_id={ entry.id }
									platform_id={ entry.platform_id }
									platform_label={ platform_label( entry.platform_id ) }
									api_env={ api_env }
									on_recorded={ ( silo_url ) => {
										set_mode( entry.id, 'idle' );
										on_entry_recorded?.( post_id, entry.id, silo_url );
									} }
									on_cancel={ () => set_mode( entry.id, 'idle' ) }
								/>
							) }
						</li>
					);
				} ) }
			</ul>
		</section>
	);
};
