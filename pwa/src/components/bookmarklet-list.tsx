/**
 * Bookmarklet list — renders the four Reply-variant bookmarklets inside
 * the About tab so users can install them without leaving the PWA for
 * wp-admin.
 *
 * Mirror of `Outpost_Admin_Page::build_bookmarklet()` (PHP). Generated
 * client-side from `location.origin` so each bookmarklet points at the
 * site's own `/post/share-target` route.
 *
 * Three install paths covered in the inline help:
 *
 *   - Desktop browsers: drag the link to the bookmarks bar.
 *   - iPhone Safari: long-press the link, choose "Add Bookmark."
 *   - Android Chrome: long-press, choose "Copy link," save as a bookmark.
 *
 * The Copy-source button is the universal fallback — it puts the
 * `javascript:...` URL on the clipboard so the user can paste it
 * wherever bookmarks are managed on their device.
 */

import { useState } from 'preact/hooks';

interface VariantConfig {
	variant: string;
	label: string;
	description: string;
}

const VARIANTS: VariantConfig[] = [
	{
		variant: 'reply',
		label: 'Reply',
		description: 'Post a reply that links back to the source page (in-reply-to).',
	},
	{
		variant: 'like',
		label: 'Like',
		description: 'Post a like with the source page as the like-of target.',
	},
	{
		variant: 'repost',
		label: 'Repost',
		description: 'Post a repost with the source page as the repost-of target.',
	},
	{
		variant: 'bookmark',
		label: 'Bookmark',
		description: 'Save the source page as a bookmark with optional commentary.',
	},
];

function build_bookmarklet(share_target_url: string, variant: string): string {
	const template = `${share_target_url}?variant=${encodeURIComponent(variant)}`;
	return (
		'javascript:(function(){' +
		'var u=encodeURIComponent(location.href),' +
		't=encodeURIComponent(document.title),' +
		"s=encodeURIComponent(window.getSelection?String(window.getSelection()):'');" +
		`var w=window.open('${template}&url='+u+'&title='+t+'&text='+s,'_blank');` +
		'if(w)w.focus();' +
		'})();'
	);
}

interface BookmarkletCardProps {
	config: VariantConfig;
	bookmarklet: string;
}

function BookmarkletCard({ config, bookmarklet }: BookmarkletCardProps): preact.JSX.Element {
	const [copied, setCopied] = useState(false);

	const copy = async (): Promise<void> => {
		try {
			if (navigator.clipboard?.writeText) {
				await navigator.clipboard.writeText(bookmarklet);
			} else {
				// Fallback for browsers without clipboard API in this context.
				const textarea = document.createElement('textarea');
				textarea.value = bookmarklet;
				textarea.setAttribute('readonly', '');
				textarea.style.position = 'absolute';
				textarea.style.left = '-9999px';
				document.body.appendChild(textarea);
				textarea.select();
				document.execCommand('copy');
				document.body.removeChild(textarea);
			}
			setCopied(true);
			setTimeout(() => setCopied(false), 1500);
		} catch (_err) {
			// no-op — Copy button shows no state change so the user
			// notices and falls back to long-press.
		}
	};

	return (
		<article class="outpost-bookmarklet" aria-labelledby={`outpost-bookmarklet-${config.variant}-title`}>
			<h4 id={`outpost-bookmarklet-${config.variant}-title`} class="outpost-bookmarklet__title">
				Outpost: {config.label}
			</h4>
			<p class="outpost-bookmarklet__desc">{config.description}</p>
			<div class="outpost-bookmarklet__actions">
				<a
					href={bookmarklet}
					class="outpost-button outpost-bookmarklet__link"
					draggable
					onClick={(event) => event.preventDefault()}
				>
					Drag or long-press: {config.label}
				</a>
				<button
					type="button"
					class="outpost-button outpost-button--secondary"
					onClick={copy}
					aria-live="polite"
				>
					{copied ? 'Copied!' : 'Copy source'}
				</button>
			</div>
		</article>
	);
}

export function BookmarkletList(): preact.JSX.Element {
	const share_target_url = `${location.origin}/post/share-target`;

	return (
		<>
			<h3>Bookmarklets — post from any page</h3>
			<p>
				A bookmarklet is a small script saved as a browser bookmark. Tap one
				while reading any web page and Outpost opens with that page&apos;s URL,
				title, and any text you had selected pre-filled — ready to reply, like,
				repost, or bookmark.
			</p>
			<p>
				Each variant below builds the same h-entry post you&apos;d get by typing
				the URL into the Reply tab — but in one tap from wherever you&apos;re
				reading.
			</p>

			<div class="outpost-bookmarklets" role="list">
				{VARIANTS.map((config) => (
					<BookmarkletCard
						key={config.variant}
						config={config}
						bookmarklet={build_bookmarklet(share_target_url, config.variant)}
					/>
				))}
			</div>

			<h4>How to install on your device</h4>
			<dl class="outpost-spec-list">
				<dt>iPhone Safari</dt>
				<dd>
					Long-press the colored button and choose <strong>Add Bookmark</strong>.
					Later, when you&apos;re reading a page, tap the bookmarks icon at the
					bottom of Safari, find the saved bookmark, and tap it. The composer
					opens with that page&apos;s details.
				</dd>
				<dt>Android Chrome</dt>
				<dd>
					Long-press the button, tap <strong>Copy link address</strong>, then
					open a new tab, paste, and bookmark the resulting page. Tapping the
					saved bookmark on any page runs the bookmarklet.
				</dd>
				<dt>Desktop browsers</dt>
				<dd>
					Drag the colored button straight to your bookmarks bar. Click it from
					any page. (If your browser hides the bookmarks bar, enable it under
					View → Bookmarks.)
				</dd>
				<dt>Any device</dt>
				<dd>
					Use the <strong>Copy source</strong> button to put the bookmarklet on
					your clipboard, then paste it wherever your device manages bookmarks.
				</dd>
			</dl>

			<p>
				<strong>Even simpler on mobile:</strong> install Outpost as a Home
				Screen app (Share → Add to Home Screen on iPhone, ⋮ → Install app on
				Android). Once installed, Outpost shows up in the system Share sheet —
				tap Share on any page, choose Outpost, done. No bookmarklets needed.
			</p>
		</>
	);
}
