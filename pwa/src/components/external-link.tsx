import type { JSX } from 'preact';

/**
 * Shared "opens in a new tab" link.
 *
 * Every outbound `target="_blank"` link in the composer renders through this
 * component so screen-reader users get a spoken heads-up before the tab
 * switch. `target="_blank"` alone gives assistive tech no such warning, and
 * several call sites used to print the raw URL as the link's only visible
 * (and accessible) text — which reads as noise to a screen reader rather
 * than telling the user where the link goes. Callers pass descriptive text
 * as children; this component appends the hidden hint.
 */
export interface ExternalLinkProps
	extends Omit<JSX.HTMLAttributes<HTMLAnchorElement>, 'href' | 'target' | 'rel'> {
	href: string;
	children: preact.ComponentChildren;
}

export function ExternalLink({ href, children, ...rest }: ExternalLinkProps): preact.JSX.Element {
	return (
		<a href={href} target="_blank" rel="noopener noreferrer" {...rest}>
			{children}
			<span class="outpost-visually-hidden"> (opens in new tab)</span>
		</a>
	);
}
