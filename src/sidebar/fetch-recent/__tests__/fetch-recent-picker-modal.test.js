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

const mockInsertBlocks = jest.fn();
const mockEditPost = jest.fn();
jest.mock( '@wordpress/data', () => ( {
	dispatch: jest.fn( () => ( {
		insertBlocks: mockInsertBlocks,
		editPost: mockEditPost,
	} ) ),
} ) );

const mockGetBlockType = jest.fn();
jest.mock( '@wordpress/blocks', () => ( {
	createBlock: jest.fn( ( name, attrs ) => ( { name, attrs } ) ),
	getBlockType: ( ...args ) => mockGetBlockType( ...args ),
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

	describe( 'inserting a pick', () => {
		const sleep = {
			id: 'oura-sleep-1',
			title: 'Sleep',
			subtitle: '7.7 hours',
			fetched_at: '2026-11-13',
			post_payload: {
				content: '<p>Sleep</p>',
				post_meta: { _outpost_oura_sleep_day: '2026-11-13' },
				blocks: [
					{
						name: 'post-kinds-indieweb/sleep-card',
						attributes: { durationMinutes: 462 },
					},
				],
				terms: { kind: [ 5 ] },
				date: '2026-11-12T12:00:00',
			},
		};

		async function pick( item ) {
			apiFetch.mockResolvedValue( { provider_id: 'oura', items: [ item ] } );
			const onClose = jest.fn();
			const { findByText } = render(
				<FetchRecentPickerModal providerId="oura" providerLabel="Oura" onClose={ onClose } />
			);
			( await findByText( item.title ) ).click();
			return onClose;
		}

		beforeEach( () => {
			mockInsertBlocks.mockReset();
			mockEditPost.mockReset();
			mockGetBlockType.mockReset();
		} );

		it( 'inserts the server-built blocks and sets their kind and date (#260)', async () => {
			mockGetBlockType.mockImplementation( ( name ) =>
				name === 'post-kinds-indieweb/sleep-card' ? { name } : undefined
			);
			const onClose = await pick( sleep );
			expect( mockInsertBlocks ).toHaveBeenCalledWith( [
				{ name: 'post-kinds-indieweb/sleep-card', attrs: { durationMinutes: 462 } },
			] );
			expect( mockEditPost ).toHaveBeenCalledWith( {
				meta: { _outpost_oura_sleep_day: '2026-11-13' },
				kind: [ 5 ],
				date: '2026-11-12T12:00:00',
			} );
			expect( onClose ).toHaveBeenCalled();
		} );

		it( 'falls back to the paragraph when the editor lacks a block type', async () => {
			mockGetBlockType.mockReturnValue( undefined );
			await pick( sleep );
			expect( mockInsertBlocks ).toHaveBeenCalledWith( [
				{ name: 'core/paragraph', attrs: { content: '<p>Sleep</p>' } },
			] );
			expect( mockEditPost ).toHaveBeenCalledWith( {
				meta: { _outpost_oura_sleep_day: '2026-11-13' },
			} );
		} );

		it( 'inserts the Post Kinds exercise card the route sends (F-43)', async () => {
			mockGetBlockType.mockImplementation( ( name ) => ( { name } ) );
			await pick( {
				id: 'polar-exercise-1',
				title: 'Training — Running, 5.2 km',
				subtitle: '2026-11-12',
				fetched_at: '2026-11-12',
				post_payload: {
					content: '<p>Training — Running, 5.2 km</p>',
					post_meta: { _outpost_polar_sport: 'Running' },
					blocks: [
						{
							name: 'post-kinds-indieweb/exercise-card',
							attributes: { activity: 'Running', distance: 5.23, distanceUnit: 'km', duration: 'PT29M13S' },
						},
					],
					terms: { kind: [ 9 ] },
				},
			} );
			expect( mockInsertBlocks ).toHaveBeenCalledWith( [
				{
					name: 'post-kinds-indieweb/exercise-card',
					attrs: { activity: 'Running', distance: 5.23, distanceUnit: 'km', duration: 'PT29M13S' },
				},
			] );
			expect( mockEditPost ).toHaveBeenCalledWith( {
				meta: { _outpost_polar_sport: 'Running' },
				kind: [ 9 ],
			} );
		} );

		it.each( [
			[ 'core/html' ],
			[ 'acme/sleep-card' ],
			[ 'post-kinds-indieweb-evil/sleep-card' ],
			[ 'post-kinds-indieweb/Sleep-Card' ],
		] )( 'inserts the paragraph, not %s, even when the editor knows it (F-43)', async ( name ) => {
			mockGetBlockType.mockImplementation( ( blockName ) => ( { name: blockName } ) );
			await pick( {
				...sleep,
				post_payload: {
					...sleep.post_payload,
					blocks: [ { name, attributes: { content: '<b>x</b>' } } ],
				},
			} );
			expect( mockInsertBlocks ).toHaveBeenCalledWith( [
				{ name: 'core/paragraph', attrs: { content: '<p>Sleep</p>' } },
			] );
			expect( mockEditPost ).toHaveBeenCalledWith( {
				meta: { _outpost_oura_sleep_day: '2026-11-13' },
			} );
		} );

		it( 'keeps the paragraph for a payload without blocks', async () => {
			await pick( {
				...sleep,
				post_payload: { content: '<p>Run</p>', post_meta: {} },
			} );
			expect( mockInsertBlocks ).toHaveBeenCalledWith( [
				{ name: 'core/paragraph', attrs: { content: '<p>Run</p>' } },
			] );
			expect( mockEditPost ).not.toHaveBeenCalled();
		} );
	} );
} );
