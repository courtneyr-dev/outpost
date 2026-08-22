/**
 * The Outpost fingerpost mark, sized for block editor chrome.
 *
 * A signpost — upright, base, and two arms pointing opposite ways — drawn
 * to match the plugin icon (assets/icons/outpost-icon.svg) and the docs
 * site mark, flattened to a single colour so it reads at the 24px the
 * editor paints it at and works on both the light "more menu" row and the
 * dark active-sidebar button.
 *
 * No `fill` is set: the editor's own button styles apply `currentColor`,
 * which is what lets the mark invert with the button state. The same
 * geometry lives in `outpost_menu_icon()` (outpost.php) for the admin
 * menu, which needs an explicit colour because it renders as an `<img>`.
 * Keep the two in step.
 *
 * @file
 */

import { SVG, Path } from '@wordpress/primitives';

export const fingerpost = (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		{ /* Upright, then its footing. */ }
		<Path d="M11 3h2v17h-2z" />
		<Path d="M8 20h8v2H8z" />
		{ /* Upper arm, pointing right; lower arm, pointing left. */ }
		<Path d="M4 5h12.5L20 7.25 16.5 9.5H4z" />
		<Path d="M20 12H7.5L4 14.25 7.5 16.5H20z" />
	</SVG>
);
