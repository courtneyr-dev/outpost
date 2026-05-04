import { useEffect, useState } from 'preact/hooks';
import {
	has_posted,
	is_ios_safari,
	is_running_standalone,
	mark_install_dismissed,
	was_install_dismissed,
} from '../lib/install-prompt-state';

/**
 * Install prompt — surfaces a small "Add to Home Screen" banner above
 * the composer once the user has shown intent (one successful post)
 * and isn't already running the PWA in standalone mode.
 *
 * Phase D3. Two flows:
 *
 *   - Android Chrome / Edge / Samsung Internet — listen for the
 *     `beforeinstallprompt` event, prevent default, hold the deferred
 *     prompt object, surface an "Install" button that calls
 *     `prompt()` on tap. After the choice the event is consumed.
 *   - iOS Safari — has no programmatic A2HS, so the banner shows
 *     instructions ("Tap the Share button, then Add to Home Screen")
 *     with a single Got it button.
 *
 * Dismiss is permanent for the device. The banner doesn't reappear
 * unless the user clears storage.
 *
 * Once-only: even before the first post, if the user is in a browser
 * that fires `beforeinstallprompt` we still capture the event so we
 * can show the install button immediately when they DO post. We just
 * don't render the banner UI yet.
 */

interface BeforeInstallPromptEvent extends Event {
	prompt: () => Promise<void>;
	userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

export function InstallPrompt() {
	const [should_render, setShouldRender] = useState(false);
	const [deferred, setDeferred] = useState<BeforeInstallPromptEvent | null>(null);
	const [ios, setIos] = useState(false);

	useEffect(() => {
		if (is_running_standalone()) return;
		if (was_install_dismissed()) return;

		setIos(is_ios_safari());

		// Listen for the Android-side prompt event up front so we capture
		// it whenever the browser decides to fire — but only render the
		// banner once the user has posted.
		const handler = (event: Event): void => {
			event.preventDefault();
			setDeferred(event as BeforeInstallPromptEvent);
			if (has_posted() && !was_install_dismissed()) {
				setShouldRender(true);
			}
		};
		window.addEventListener('beforeinstallprompt', handler);

		// Periodic re-check: the user might post after this component
		// mounts. Cheap polling — once a second is plenty for a UI
		// trigger that's already mid-second-precision. Bails immediately
		// once the user has dismissed so the dismissed banner stays gone.
		const interval = window.setInterval(() => {
			if (was_install_dismissed()) {
				window.clearInterval(interval);
				setShouldRender(false);
				return;
			}
			if (has_posted() && (deferred || is_ios_safari())) {
				setShouldRender(true);
			}
		}, 1000);

		// Initial check — the user might already have posted from a
		// previous session.
		if (has_posted() && (deferred || is_ios_safari())) {
			setShouldRender(true);
		}

		return (): void => {
			window.removeEventListener('beforeinstallprompt', handler);
			window.clearInterval(interval);
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps -- intentional one-time setup with internal polling
	}, []);

	const install = async (): Promise<void> => {
		if (!deferred) return;
		try {
			await deferred.prompt();
			// Whether accepted or dismissed, consume — the event fires once.
			setDeferred(null);
			setShouldRender(false);
		} catch (_err) {
			// no-op
		}
	};

	const dismiss = (): void => {
		mark_install_dismissed();
		setShouldRender(false);
	};

	if (!should_render) return null;

	if (ios) {
		return (
			<aside class="outpost-install-prompt" role="status">
				<strong>Add Outpost to your iPhone Home Screen</strong>
				<p>
					Tap the Share button{' '}
					<span aria-hidden="true">⎋</span>
					{' '}in Safari, then scroll down to <strong>Add to Home Screen</strong>.
					Outpost will open like a regular app from then on.
				</p>
				<div class="outpost-install-prompt__actions">
					<button
						class="outpost-button outpost-button--secondary"
						type="button"
						onClick={dismiss}
					>
						Got it
					</button>
				</div>
			</aside>
		);
	}

	return (
		<aside class="outpost-install-prompt" role="status">
			<strong>Install Outpost</strong>
			<p>Add Outpost to your home screen for a faster launch and a standalone window.</p>
			<div class="outpost-install-prompt__actions">
				<button class="outpost-button" type="button" onClick={install}>
					Install
				</button>
				<button
					class="outpost-button outpost-button--secondary"
					type="button"
					onClick={dismiss}
				>
					Not now
				</button>
			</div>
		</aside>
	);
}
