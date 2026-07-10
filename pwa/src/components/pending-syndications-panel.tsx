/**
 * PendingSyndicationsPanel — composer surface listing pending captures.
 *
 * Renders one row per pending audit-log entry with:
 *
 *   - Platform label + relative time ("Instagram, fired 2 days ago")
 *   - "Add URL" action that opens an inline {@link SyndicationCaptureForm}
 *
 * Empty state: no pending entries → render nothing (the panel
 * disappears entirely; composer doesn't surface dead UI).
 *
 * Hard Contract: zero color values; structural classes only.
 */

import { type FunctionComponent } from 'preact';
import { useState } from 'preact/hooks';
import { SyndicationCaptureForm } from './syndication-capture-form';
import type { CaptureApiEnvironment, PendingPost } from '../lib/manual-share/capture-api';

export interface PendingSyndicationsPanelProps {
	pending: PendingPost[];
	api_env: CaptureApiEnvironment;
	platform_labels?: Record<string, string>;
	on_entry_recorded?: ( post_id: number, audit_log_id: string, silo_url: string ) => void;
	on_dismiss_entry?: ( post_id: number, audit_log_id: string ) => void;
}

const DEFAULT_LABELS: Record<string, string> = {
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

function label_for( platform_id: string, overrides: Record<string, string> ): string {
	if ( overrides[ platform_id ] ) {
		return overrides[ platform_id ];
	}
	return platform_id.replace( /-/g, ' ' ).replace( /\b\w/g, ( c ) => c.toUpperCase() );
}

function relative_time( iso: string ): string {
	const ts = Date.parse( iso );
	if ( Number.isNaN( ts ) ) {
		return '';
	}
	const seconds_ago = Math.max( 0, Math.floor( ( Date.now() - ts ) / 1000 ) );
	if ( seconds_ago < 60 ) {
		return `${ seconds_ago }s ago`;
	}
	if ( seconds_ago < 3600 ) {
		return `${ Math.floor( seconds_ago / 60 ) }m ago`;
	}
	if ( seconds_ago < 86400 ) {
		return `${ Math.floor( seconds_ago / 3600 ) }h ago`;
	}
	return `${ Math.floor( seconds_ago / 86400 ) }d ago`;
}

export const PendingSyndicationsPanel: FunctionComponent<PendingSyndicationsPanelProps> = ( {
	pending,
	api_env,
	platform_labels,
	on_entry_recorded,
	on_dismiss_entry,
} ) => {
	const labels = { ...DEFAULT_LABELS, ...( platform_labels ?? {} ) };
	const [ active_id, set_active_id ] = useState<string | null>( null );

	if ( pending.length === 0 ) {
		return null;
	}

	return (
		<section class="outpost-pending-syndications" aria-label="Pending syndications">
			<h2 class="outpost-pending-syndications__title">Pending syndications</h2>
			<ul class="outpost-pending-syndications__list">
				{ pending.flatMap( ( post ) =>
					post.entries.map( ( entry ) => {
						const platform_label = label_for( entry.platform_id, labels );
						const fired_relative = relative_time( entry.fired_at );
						const is_active = active_id === entry.id;
						return (
							<li
								key={ entry.id }
								class="outpost-pending-syndications__item"
								data-post-id={ post.post_id }
								data-audit-log-id={ entry.id }
							>
								<div class="outpost-pending-syndications__row">
									<span class="outpost-pending-syndications__post-title">
										{ post.post_title }
									</span>
									<span class="outpost-pending-syndications__platform">
										{ platform_label }
									</span>
									<span class="outpost-pending-syndications__time">
										{ fired_relative }
									</span>
									{ ! is_active && (
										<button
											type="button"
											class="outpost-pending-syndications__action"
											data-action="add-url"
											onClick={ () => set_active_id( entry.id ) }
										>
											Add URL
										</button>
									) }
									{ on_dismiss_entry && ! is_active && (
										<button
											type="button"
											class="outpost-pending-syndications__action outpost-pending-syndications__action--skip"
											data-action="skip"
											onClick={ () => on_dismiss_entry( post.post_id, entry.id ) }
										>
											Skip
										</button>
									) }
								</div>
								{ is_active && (
									<SyndicationCaptureForm
										post_id={ post.post_id }
										audit_log_id={ entry.id }
										platform_id={ entry.platform_id }
										platform_label={ platform_label }
										api_env={ api_env }
										on_recorded={ ( silo_url ) => {
											set_active_id( null );
											on_entry_recorded?.( post.post_id, entry.id, silo_url );
										} }
										on_cancel={ () => set_active_id( null ) }
									/>
								) }
							</li>
						);
					} )
				) }
			</ul>
		</section>
	);
};
