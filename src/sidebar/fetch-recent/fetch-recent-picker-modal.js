/**
 * Fetch-recent picker modal (G-fetch-recent-picker).
 *
 * Opens when the user taps "Add from {provider}" in the sidebar.
 * Fetches /outpost/v1/fetch-recent/<provider_id>, renders the items,
 * and inserts the chosen item's payload into the editor as a paragraph
 * block.
 *
 * Loading / error / not-connected / items states are mutually
 * exclusive and decided per-render from {loading, error, response}
 * state.
 *
 * @file
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { Modal, Button, Spinner, Notice } from '@wordpress/components';
import { dispatch } from '@wordpress/data';
import { createBlock } from '@wordpress/blocks';
import apiFetch from '@wordpress/api-fetch';
import { applyFilters } from '@wordpress/hooks';
import { __, sprintf } from '@wordpress/i18n';
import { DefaultItemRenderer } from './default-item-renderer.js';

/**
 * @param {Object}   props
 * @param {string}   props.providerId    Provider id.
 * @param {string}   props.providerLabel Human-readable provider label.
 * @param {Function} props.onClose       Close-modal callback.
 */
export function FetchRecentPickerModal( { providerId, providerLabel, onClose } ) {
	const [ loading, setLoading ]   = useState( true );
	const [ error, setError ]       = useState( null );
	const [ response, setResponse ] = useState( null );
	const [ retryToken, setRetryToken ] = useState( 0 );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		setError( null );

		apiFetch( {
			path: `/outpost/v1/fetch-recent/${ encodeURIComponent( providerId ) }?count=10`,
		} )
			.then( ( data ) => {
				if ( cancelled ) {
					return;
				}
				setResponse( data );
				setLoading( false );
			} )
			.catch( ( err ) => {
				if ( cancelled ) {
					return;
				}
				setError( err && err.message ? err.message : __( 'Request failed.', 'outpost' ) );
				setLoading( false );
			} );

		return () => {
			cancelled = true;
		};
	}, [ providerId, retryToken ] );

	const handleSelect = useCallback(
		( item ) => {
			if ( ! item || ! item.post_payload ) {
				return;
			}
			const block = createBlock( 'core/paragraph', {
				content: item.post_payload.content || '',
			} );
			dispatch( 'core/block-editor' ).insertBlocks( [ block ] );

			const meta = ( item.post_payload && item.post_payload.post_meta ) || {};
			if ( meta && Object.keys( meta ).length > 0 ) {
				dispatch( 'core/editor' ).editPost( { meta } );
			}

			onClose();
		},
		[ onClose ]
	);

	const renderItem = ( item ) => {
		const Renderer = applyFilters(
			`outpost.fetchRecent.itemRenderer.${ providerId }`,
			DefaultItemRenderer,
			providerId
		);
		return <Renderer key={ item.id } item={ item } onSelect={ handleSelect } />;
	};

	const title = sprintf(
		/* translators: %s: provider label */
		__( 'Pick a recent item from %s', 'outpost' ),
		providerLabel
	);

	return (
		<Modal
			title={ title }
			onRequestClose={ onClose }
			className="outpost-fetch-recent-modal"
			style={ { width: '600px' } }
		>
			{ loading && (
				<div className="outpost-fetch-recent-loading">
					<Spinner />
					<p>{ __( 'Fetching recent items…', 'outpost' ) }</p>
				</div>
			) }

			{ ! loading && error && (
				<>
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
					<Button
						variant="secondary"
						onClick={ () => setRetryToken( ( token ) => token + 1 ) }
					>
						{ __( 'Retry', 'outpost' ) }
					</Button>
				</>
			) }

			{ ! loading && ! error && response && response.reason === 'not_connected' && (
				<Notice status="info" isDismissible={ false }>
					{ response.message ||
						__( 'Connect this provider in OAuth settings before using this picker.', 'outpost' ) }
				</Notice>
			) }

			{ ! loading && ! error && response && response.reason === 'auth_failed' && (
				<Notice status="warning" isDismissible={ false }>
					{ response.message ||
						__( 'Connection expired. Reconnect in OAuth settings.', 'outpost' ) }
				</Notice>
			) }

			{ ! loading &&
				! error &&
				response &&
				! response.reason &&
				Array.isArray( response.items ) && (
					<div className="outpost-fetch-recent-items">
						{ response.items.length === 0 ? (
							<p>{ __( 'No recent items available.', 'outpost' ) }</p>
						) : (
							response.items.map( renderItem )
						) }
					</div>
				) }
		</Modal>
	);
}
