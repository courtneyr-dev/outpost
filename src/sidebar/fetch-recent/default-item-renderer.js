/**
 * Default fetch-recent list item renderer (G-fetch-recent-picker).
 *
 * Provider-agnostic Card-style item with optional icon, title, subtitle
 * + relative-time footer. Custom renderers per provider can override
 * via wp.hooks filter `outpost.fetchRecent.itemRenderer.<provider_id>`.
 *
 * @file
 */

import { Card, CardBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Format an ISO 8601 timestamp as a relative time string. Tiny inline
 * helper so we don't add date-fns as a direct dep just for this.
 *
 * @param {string} isoTimestamp ISO 8601 timestamp.
 * @return {string} Human-readable relative time.
 */
export function formatRelativeTime( isoTimestamp ) {
	if ( ! isoTimestamp ) {
		return '';
	}
	const then = new Date( isoTimestamp ).getTime();
	if ( Number.isNaN( then ) ) {
		return '';
	}
	const seconds = Math.max( 0, ( Date.now() - then ) / 1000 );
	if ( seconds < 60 ) {
		return __( 'Just now', 'outpost' );
	}
	if ( seconds < 3600 ) {
		const minutes = Math.round( seconds / 60 );
		return `${ minutes } ${ minutes === 1 ? __( 'minute ago', 'outpost' ) : __( 'minutes ago', 'outpost' ) }`;
	}
	if ( seconds < 86400 ) {
		const hours = Math.round( seconds / 3600 );
		return `${ hours } ${ hours === 1 ? __( 'hour ago', 'outpost' ) : __( 'hours ago', 'outpost' ) }`;
	}
	const days = Math.round( seconds / 86400 );
	return `${ days } ${ days === 1 ? __( 'day ago', 'outpost' ) : __( 'days ago', 'outpost' ) }`;
}

/**
 * Default canonical-shape item renderer.
 *
 * @param {Object}   props
 * @param {Object}   props.item     Canonical fetch-recent item.
 * @param {Function} props.onSelect Click handler.
 * @return {JSX.Element} The rendered card.
 */
export function DefaultItemRenderer( { item, onSelect } ) {
	const handleClick = () => onSelect( item );
	return (
		<Card
			className="outpost-fetch-recent-item"
			onClick={ handleClick }
			role="button"
			tabIndex={ 0 }
			onKeyDown={ ( event ) => {
				if ( event.key === 'Enter' || event.key === ' ' ) {
					event.preventDefault();
					handleClick();
				}
			} }
		>
			<CardBody>
				{ item.icon_url && (
					<img
						src={ item.icon_url }
						alt=""
						className="outpost-fetch-recent-item-icon"
					/>
				) }
				<h4 className="outpost-fetch-recent-item-title">{ item.title }</h4>
				{ item.subtitle && (
					<p className="outpost-fetch-recent-item-subtitle">{ item.subtitle }</p>
				) }
				<small className="outpost-fetch-recent-item-time">
					{ formatRelativeTime( item.fetched_at ) }
				</small>
			</CardBody>
		</Card>
	);
}
