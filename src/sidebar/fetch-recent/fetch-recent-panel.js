/**
 * Fetch-recent sidebar panel (G-fetch-recent-picker).
 *
 * Renders one button per registered fetch-recent provider. Each button
 * opens the picker modal scoped to that provider.
 *
 * Provider list is fetched once on first mount via
 * /outpost/v1/fetch-recent-providers (returns label + provider_id +
 * oauth_provider). The picker modal handles per-provider auth-state
 * messaging — this panel just renders the buttons.
 *
 * @file
 */

import { useState, useEffect } from '@wordpress/element';
import { Button, PanelBody, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { FetchRecentPickerModal } from './fetch-recent-picker-modal.js';

/**
 * Sidebar panel.
 *
 * @return {JSX.Element} Panel.
 */
export function FetchRecentPanel() {
	const [ providers, setProviders ] = useState( null );
	const [ active, setActive ]       = useState( null );

	useEffect( () => {
		let cancelled = false;
		apiFetch( { path: '/outpost/v1/fetch-recent-providers' } )
			.then( ( data ) => {
				if ( cancelled ) {
					return;
				}
				setProviders(
					Array.isArray( data && data.providers ) ? data.providers : []
				);
			} )
			.catch( () => {
				if ( cancelled ) {
					return;
				}
				setProviders( [] );
			} );

		return () => {
			cancelled = true;
		};
	}, [] );

	if ( null === providers ) {
		return (
			<PanelBody title={ __( 'Add from connected platforms', 'outpost' ) } initialOpen>
				<Spinner />
			</PanelBody>
		);
	}

	if ( providers.length === 0 ) {
		return (
			<PanelBody title={ __( 'Add from connected platforms', 'outpost' ) }>
				<p>
					{ __(
						'No fetch-recent providers registered. Connect a provider (Oura, WHOOP, Polar) to see "Add from …" buttons here.',
						'outpost'
					) }
				</p>
			</PanelBody>
		);
	}

	return (
		<PanelBody title={ __( 'Add from connected platforms', 'outpost' ) } initialOpen>
			{ providers.map( ( provider ) => (
				<Button
					key={ provider.id }
					variant="secondary"
					className="outpost-fetch-recent-button"
					onClick={ () => setActive( provider ) }
				>
					{ sprintf(
						/* translators: %s: provider label */
						__( 'Add from %s', 'outpost' ),
						provider.label
					) }
				</Button>
			) ) }

			{ active && (
				<FetchRecentPickerModal
					providerId={ active.id }
					providerLabel={ active.label }
					onClose={ () => setActive( null ) }
				/>
			) }
		</PanelBody>
	);
}
