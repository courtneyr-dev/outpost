/**
 * Tests for SnoozeMenu.
 */

import { describe, expect, it, beforeEach, afterEach, vi } from 'vitest';
import { render } from 'preact';
import { h } from 'preact';
import { SnoozeMenu, DEFAULT_SNOOZE_OPTIONS } from './snooze-menu';

let root: HTMLDivElement;

beforeEach( () => {
	root = document.createElement( 'div' );
	document.body.appendChild( root );
} );

afterEach( () => {
	render( null, root );
	root.remove();
} );

describe( 'SnoozeMenu', () => {
	it( 'renders the four default snooze options', () => {
		render( h( SnoozeMenu, { on_pick: vi.fn() } ), root );

		const buttons = root.querySelectorAll( '.outpost-snooze-menu__option' );
		expect( buttons.length ).toBe( DEFAULT_SNOOZE_OPTIONS.length );
		expect( DEFAULT_SNOOZE_OPTIONS.length ).toBe( 4 );
	} );

	it( "calls on_pick with selected duration", () => {
		const on_pick = vi.fn();
		render( h( SnoozeMenu, { on_pick } ), root );

		const p7d_btn = root.querySelector( '[data-snooze-value="P7D"]' ) as HTMLButtonElement;
		p7d_btn.click();

		expect( on_pick ).toHaveBeenCalledWith( 'P7D' );
	} );

	it( "'forever' option calls on_pick with forever", () => {
		const on_pick = vi.fn();
		render( h( SnoozeMenu, { on_pick } ), root );

		const forever_btn = root.querySelector( '[data-snooze-value="forever"]' ) as HTMLButtonElement;
		forever_btn.click();

		expect( on_pick ).toHaveBeenCalledWith( 'forever' );
	} );

	it( 'renders Cancel button only when on_cancel provided', () => {
		render( h( SnoozeMenu, { on_pick: vi.fn() } ), root );
		expect( root.querySelector( '[data-action="cancel"]' ) ).toBeNull();

		render( null, root );
		render( h( SnoozeMenu, { on_pick: vi.fn(), on_cancel: vi.fn() } ), root );
		expect( root.querySelector( '[data-action="cancel"]' ) ).not.toBeNull();
	} );

	it( "calls on_cancel when Cancel clicked", () => {
		const on_cancel = vi.fn();
		render( h( SnoozeMenu, { on_pick: vi.fn(), on_cancel } ), root );

		const cancel = root.querySelector( '[data-action="cancel"]' ) as HTMLButtonElement;
		cancel.click();

		expect( on_cancel ).toHaveBeenCalledOnce();
	} );

	it( 'disables all options when disabled prop is true', () => {
		render( h( SnoozeMenu, { on_pick: vi.fn(), disabled: true } ), root );

		const buttons = root.querySelectorAll( '.outpost-snooze-menu__option' );
		buttons.forEach( ( btn ) => {
			expect( ( btn as HTMLButtonElement ).disabled ).toBe( true );
		} );
	} );

	it( 'role="menu" + role="menuitem" for accessibility', () => {
		render( h( SnoozeMenu, { on_pick: vi.fn() } ), root );

		expect( root.querySelector( '[role="menu"]' ) ).not.toBeNull();
		const menuitems = root.querySelectorAll( '[role="menuitem"]' );
		expect( menuitems.length ).toBe( 4 );
	} );

	it( 'has aria-label "Snooze duration"', () => {
		render( h( SnoozeMenu, { on_pick: vi.fn() } ), root );
		const menu = root.querySelector( '[role="menu"]' );
		expect( menu?.getAttribute( 'aria-label' ) ).toBe( 'Snooze duration' );
	} );

	it( 'options override default list', () => {
		const custom_options = [
			{ value: 'P1D' as const, label: 'Tomorrow' },
		];
		render( h( SnoozeMenu, { on_pick: vi.fn(), options: custom_options } ), root );

		const buttons = root.querySelectorAll( '.outpost-snooze-menu__option' );
		expect( buttons.length ).toBe( 1 );
		expect( buttons[0]?.textContent ).toBe( 'Tomorrow' );
	} );
} );
