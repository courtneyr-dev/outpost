import { useEffect, useRef } from 'preact/hooks';
import type { ComponentChildren } from 'preact';

/**
 * Drawer primitive.
 *
 * Per Design System Section 4.9 + 5.25. Slides up from the bottom of the
 * viewport on open. Used for the More options pull-out (Phase DS-3) and
 * future surfaces (queue inspector, voice transcript review).
 *
 * Behavior:
 *   - Scrim covers the page, taps it to close.
 *   - Esc closes.
 *   - Focus moves into the drawer on open (first focusable element)
 *     and returns to the trigger on close.
 *   - Body scroll locks while open.
 *   - Slide animation gates on prefers-reduced-motion: instant when
 *     reduced-motion is on, transform transition otherwise.
 *
 * ARIA: `role="dialog"`, `aria-modal="true"`, `aria-labelledby` points
 * at the header title. The close button gets a clear `aria-label`.
 *
 * Drawer renders unconditionally (always in the DOM) — the open prop
 * toggles a data attribute that drives the slide CSS. Keeps animation
 * smooth on close, and lets focus-restore reliably target the trigger.
 */

export interface DrawerProps {
	open: boolean;
	onClose: () => void;
	title: string;
	children: ComponentChildren;
}

export function Drawer({ open, onClose, title, children }: DrawerProps) {
	const drawer_ref = useRef<HTMLDivElement>(null);

	// Esc closes; Tab/Shift-Tab cycles focus inside the drawer (focus trap).
	// Without the trap, tabbing forward from the last interactive element
	// inside the drawer reaches content underneath the scrim — keyboard
	// users escape the modal without intending to. The drawer is visually
	// modal (scrim covers the page) but the AT focus model needs explicit
	// containment.
	useEffect(() => {
		if (!open) return;
		const handler = (event: KeyboardEvent): void => {
			if (event.key === 'Escape') {
				event.preventDefault();
				onClose();
				return;
			}
			if (event.key !== 'Tab') return;
			const root = drawer_ref.current;
			if (!root) return;
			// Standard "interactive" selector. We DON'T include
			// `[tabindex="-1"]` — programmatically-focusable but
			// keyboard-skipped elements should stay skipped.
			const focusable = Array.from(
				root.querySelectorAll<HTMLElement>(
					'a[href]:not([disabled]), button:not([disabled]), textarea:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), details:not([disabled]) > summary, [tabindex]:not([tabindex="-1"]):not([disabled])',
				),
			).filter((el) => !el.hasAttribute('aria-hidden') && el.offsetParent !== null);
			if (focusable.length === 0) return;
			const first = focusable[0]!;
			const last = focusable[focusable.length - 1]!;
			if (event.shiftKey && document.activeElement === first) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				first.focus();
			}
		};
		window.addEventListener('keydown', handler);
		return (): void => {
			window.removeEventListener('keydown', handler);
		};
	}, [open, onClose]);

	// Focus management: move focus into the drawer on open, restore on close.
	useEffect(() => {
		if (!open) return;
		const previously_focused = document.activeElement as HTMLElement | null;
		// Defer one frame so the drawer is in its final visible state
		// before we focus inside it.
		const id = window.setTimeout(() => {
			const first = drawer_ref.current?.querySelector<HTMLElement>(
				'button:not([aria-hidden]), [href], input:not([type="hidden"]), select, textarea, [tabindex]:not([tabindex="-1"])',
			);
			first?.focus();
		}, 0);
		return (): void => {
			window.clearTimeout(id);
			if (previously_focused && typeof previously_focused.focus === 'function') {
				previously_focused.focus();
			}
		};
	}, [open]);

	// Body scroll lock — prevents the page behind the scrim from scrolling
	// when the user drags inside the drawer.
	useEffect(() => {
		if (!open) return;
		const original_overflow = document.body.style.overflow;
		document.body.style.overflow = 'hidden';
		return (): void => {
			document.body.style.overflow = original_overflow;
		};
	}, [open]);

	// Derive a per-instance title id so multiple drawers (e.g., More
	// options + Queue) can coexist without `id` collisions breaking
	// `aria-labelledby` assignments.
	const title_id =
		'outpost-drawer-title-' +
		title
			.toLowerCase()
			.replace(/\s+/g, '-')
			.replace(/[^a-z0-9-]/g, '');

	return (
		<>
			<div
				class="outpost-drawer-scrim"
				data-open={open ? 'true' : 'false'}
				onClick={onClose}
				aria-hidden="true"
			/>
			<div
				ref={drawer_ref}
				class="outpost-drawer"
				data-open={open ? 'true' : 'false'}
				role="dialog"
				aria-modal="true"
				aria-labelledby={title_id}
				tabIndex={-1}
			>
				<header class="outpost-drawer__header">
					<h2 id={title_id} class="outpost-drawer__title">
						{title}
					</h2>
					<button
						type="button"
						class="outpost-button outpost-button--secondary"
						onClick={onClose}
						aria-label="Close"
					>
						<span aria-hidden="true">✕</span>
					</button>
				</header>
				<div class="outpost-drawer__body">{children}</div>
			</div>
		</>
	);
}
