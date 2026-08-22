/**
 * FetchRecentPickerModal smoke tests (G-fetch-recent-picker).
 *
 * Verifies the loading / error / not-connected / items render branches.
 * Insert flow is exercised via the dispatch/createBlock mock.
 */

import { render } from '@testing-library/react';
import '@testing-library/jest-dom';
import apiFetch from '@wordpress/api-fetch';
import { FetchRecentPickerModal } from '../fetch-recent-picker-modal';

jest.mock( '@wordpress/components', () => ( {
	Modal: ( { children, title } ) => (
		<div role="dialog" aria-label={ title }>{ children }</div>
	),
	Button: ( { children, onClick } ) => (
		<button onClick={ onClick }>{ children }</button>
	),
	Spinner: () => <span data-testid="spinner" />,
	Notice: ( { children } ) => <div role="status">{ children }</div>,
	Card: ( { children, onClick } ) => <div onClick={ onClick }>{ children }</div>,
	CardBody: ( { children } ) => <div>{ children }</div>,
} ) );

jest.mock( '@wordpress/data', () => ( {
	dispatch: jest.fn( () => ( {
		insertBlocks: jest.fn(),
		editPost: jest.fn(),
	} ) ),
} ) );

jest.mock( '@wordpress/blocks', () => ( {
	createBlock: jest.fn( ( name, attrs ) => ( { name, attrs } ) ),
} ) );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

jest.mock( '@wordpress/hooks', () => ( {
	applyFilters: ( name, value ) => value,
} ) );

// apiFetch is imported at the top; babel-jest hoists the jest.mock calls
// above the imports, so the binding is already the mock by the time the
// tests run.

describe( 'FetchRecentPickerModal', () => {
	beforeEach( () => {
		apiFetch.mockReset();
	} );

	it( 'renders the loading spinner before the response arrives', () => {
		apiFetch.mockReturnValue( new Promise( () => {} ) ); // never resolves
		const { getByTestId } = render(
			<FetchRecentPickerModal
				providerId="test"
				providerLabel="Test"
				onClose={ () => {} }
			/>
		);
		expect( getByTestId( 'spinner' ) ).toBeInTheDocument();
	} );

	it( 'renders item titles when the response arrives', async () => {
		apiFetch.mockResolvedValue( {
			provider_id: 'test',
			items: [
				{
					id: 'a',
					title: 'Workout One',
					subtitle: 'subtitle',
					fetched_at: '2026-05-06T00:00:00+00:00',
					post_payload: { content: '<p>One</p>' },
				},
				{
					id: 'b',
					title: 'Workout Two',
					subtitle: '',
					fetched_at: '2026-05-06T00:00:00+00:00',
					post_payload: { content: '<p>Two</p>' },
				},
			],
		} );

		const { findByText } = render(
			<FetchRecentPickerModal
				providerId="test"
				providerLabel="Test"
				onClose={ () => {} }
			/>
		);

		expect( await findByText( 'Workout One' ) ).toBeInTheDocument();
		expect( await findByText( 'Workout Two' ) ).toBeInTheDocument();
	} );

	it( 'renders the not-connected notice when reason="not_connected"', async () => {
		apiFetch.mockResolvedValue( {
			provider_id: 'oura',
			items: [],
			reason: 'not_connected',
			message: 'Connect Oura first.',
		} );

		const { findByText } = render(
			<FetchRecentPickerModal
				providerId="oura"
				providerLabel="Oura"
				onClose={ () => {} }
			/>
		);

		expect( await findByText( /Connect Oura first/i ) ).toBeInTheDocument();
	} );

	it( 'renders the error notice + retry button on apiFetch reject', async () => {
		apiFetch.mockRejectedValue( { message: 'Network error' } );

		const { findByText } = render(
			<FetchRecentPickerModal
				providerId="test"
				providerLabel="Test"
				onClose={ () => {} }
			/>
		);

		expect( await findByText( /Network error/i ) ).toBeInTheDocument();
		expect( await findByText( 'Retry' ) ).toBeInTheDocument();
	} );

	it( 'renders an empty-state message when items array is empty', async () => {
		apiFetch.mockResolvedValue( {
			provider_id: 'test',
			items: [],
		} );

		const { findByText } = render(
			<FetchRecentPickerModal
				providerId="test"
				providerLabel="Test"
				onClose={ () => {} }
			/>
		);

		expect( await findByText( /No recent items available/i ) ).toBeInTheDocument();
	} );
} );
