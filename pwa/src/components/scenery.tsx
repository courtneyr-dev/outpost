/**
 * Decorative mountain-and-pine silhouette for the foot of the composer.
 *
 * Drawn for Outpost from flat polygons (no raster, no gradient, no
 * remote asset), released with the plugin under GPL-2.0-or-later. It
 * conveys nothing: hidden from assistive tech, never animated, and kept
 * outside the writing area. Colors come from `--outpost-scenery-far` and
 * `--outpost-scenery-near`.
 */
export function Scenery(): preact.JSX.Element {
	return (
		<div class="outpost-scenery" aria-hidden="true">
			<svg viewBox="0 0 400 64" preserveAspectRatio="none" focusable="false" xmlns="http://www.w3.org/2000/svg">
				<path class="outpost-scenery__far" d="M0 64 L0 44 L48 22 L86 40 L132 12 L178 38 L214 26 L262 46 L304 18 L352 40 L400 28 L400 64 Z" />
				<path
					class="outpost-scenery__near"
					d="M0 64 L0 56 L14 56 L20 38 L26 56 L40 56 L48 30 L56 56 L320 56 L328 34 L336 56 L350 56 L358 26 L366 56 L380 56 L386 40 L392 56 L400 56 L400 64 Z"
				/>
			</svg>
		</div>
	);
}
