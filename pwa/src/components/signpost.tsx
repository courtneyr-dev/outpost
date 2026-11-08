import { useId } from 'preact/hooks';

/**
 * Outpost signpost, outline form.
 *
 * Geometry is the canonical install icon's, unchanged
 * (`assets/icons/outpost-icon.svg`, blob 3f3a5c5): pole at x=121/y=44,
 * foot at x=97/y=200, the right-pointing upper board, the left-pointing
 * lower board. Derived from
 * `docs/product/pwa-ui-2026-11-07/outpost-signpost-outline.svg`: no tile,
 * no inset frame, `stroke="currentColor"`, and a mask that hides the pole
 * behind the boards and foot. The mask id is per instance, so two marks
 * on one page don't share (and break) one mask.
 *
 * Color comes from `--outpost-logo` via `currentColor` on the wrapper.
 *
 * Next to a visible "Outpost" wordmark, leave `label` unset: the mark is
 * decorative and hidden from assistive tech. On its own, pass a label.
 */
export interface SignpostProps {
	size?: number;
	label?: string;
	class?: string;
}

const UPPER_BOARD = 'M 52 66 L 168 66 L 192 81 L 168 96 L 52 96 Z';
const LOWER_BOARD = 'M 204 116 L 88 116 L 64 131 L 88 146 L 204 146 Z';

export function Signpost({ size = 32, label, class: className }: SignpostProps): preact.JSX.Element {
	const mask_id = `outpost-signpost-mask-${useId().replace(/[^a-zA-Z0-9_-]/g, '')}`;
	const labelled = typeof label === 'string' && label !== '';
	// The derivative's 5-unit stroke is 0.47 CSS px at 24px. Coordinates
	// stay canonical; only the stroke grows so it never renders under
	// 1.5 CSS px.
	const stroke = Math.max(5, (256 * 1.5) / size).toFixed(2);
	return (
		<svg
			class={['outpost-signpost', className].filter(Boolean).join(' ')}
			xmlns="http://www.w3.org/2000/svg"
			viewBox="0 0 256 256"
			width={size}
			height={size}
			focusable="false"
			{...(labelled ? { role: 'img', 'aria-label': label } : { 'aria-hidden': 'true' })}
		>
			<defs>
				<mask id={mask_id} maskUnits="userSpaceOnUse" x="0" y="0" width="256" height="256">
					<rect width="256" height="256" fill="white" />
					<path d={UPPER_BOARD} fill="black" />
					<path d={LOWER_BOARD} fill="black" />
					<rect x="97" y="200" width="62" height="12" fill="black" />
				</mask>
			</defs>
			<g fill="none" stroke="currentColor" stroke-width={stroke} stroke-linejoin="round">
				<rect x="121" y="44" width="14" height="164" mask={`url(#${mask_id})`} />
				<rect x="97" y="200" width="62" height="12" />
				<path d={UPPER_BOARD} />
				<path d={LOWER_BOARD} />
			</g>
		</svg>
	);
}
