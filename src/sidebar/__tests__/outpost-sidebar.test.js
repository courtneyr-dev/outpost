/**
 * Outpost sidebar smoke test (G3.5c).
 *
 * Proves the build wiring works — render the component, assert the
 * placeholder text is in the document. Real component behavior tests
 * land alongside real components.
 */

import { render } from '@testing-library/react';
import '@testing-library/jest-dom';
import { OutpostSidebar } from '../outpost-sidebar';

// `@wordpress/edit-post` exports PluginSidebar as a registered slot. In a
// test renderer with no slot infrastructure mounted, the slot renders nothing —
// fine for our smoke test. We mock the slot to render its children directly so
// the placeholder text reaches the DOM and we can assert on it.
jest.mock( '@wordpress/edit-post', () => ( {
	PluginSidebar: ( { children } ) => <div data-testid="plugin-sidebar">{ children }</div>,
	PluginSidebarMoreMenuItem: ( { children } ) => (
		<div data-testid="plugin-sidebar-more-menu-item">{ children }</div>
	),
} ) );

// Mock the fetch-recent panel away — its own tests cover its behavior;
// the parent test just needs OutpostSidebar to render without spawning
// API requests from child components.
jest.mock( '../fetch-recent/fetch-recent-panel.js', () => ( {
	FetchRecentPanel: () => <div data-testid="fetch-recent-panel" />,
} ) );

describe( 'OutpostSidebar', () => {
	it( 'renders the placeholder text', () => {
		const { getByText } = render( <OutpostSidebar /> );
		expect( getByText( /Outpost sidebar is loaded/i ) ).toBeInTheDocument();
	} );

	it( 'registers the More-menu entry labelled Outpost', () => {
		const { getAllByText } = render( <OutpostSidebar /> );
		expect( getAllByText( /Outpost/ ).length ).toBeGreaterThan( 0 );
	} );
} );
