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
					aria-label={`${config.label} bookmarklet — drag to bookmarks bar on desktop, or tap Copy source on mobile`}
				>
					Outpost: {config.label}
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

			<aside class="outpost-bookmarklet-tip" role="note">
				<strong>On iPhone, the share sheet is the better path.</strong> iOS
				Safari intentionally blocks adding <code>javascript:</code> URLs as
				bookmarks via long-press — it&apos;s been that way since iOS 12. Install
				Outpost as a Home Screen app first (Share → <strong>Add to Home
				Screen</strong>), then the system Share sheet on any page lets you tap
				Share → Outpost. That&apos;s one tap from any app, not just Safari.
				Bookmarklets below are still useful on desktop and as a last resort
				on iOS via the multi-step workaround.
			</aside>

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
				<dt>Desktop browsers</dt>
				<dd>
					Drag the colored button straight to your bookmarks bar. Click it from
					any page. If your browser hides the bookmarks bar, enable it under
					View → Bookmarks.
				</dd>
				<dt>Android Chrome</dt>
				<dd>
					Long-press the button → <strong>Copy link address</strong>. Open the
					⋮ menu → <strong>Bookmarks</strong> → <strong>Add</strong>, then paste
					the copied URL into the bookmark&apos;s URL field. Save. Tap the
					bookmark from any page to run.
				</dd>
				<dt>iPhone Safari (workaround)</dt>
				<dd>
					iOS Safari won&apos;t let you bookmark a <code>javascript:</code> link
					directly, so you bookmark a normal page first, then edit its URL:
					<ol>
						<li>Tap <strong>Copy source</strong> on the variant you want.</li>
						<li>
							Bookmark <em>this</em> page: tap Share →
							<strong>Add Bookmark</strong> → Save.
						</li>
						<li>
							Tap the bookmarks icon at the bottom of Safari, find the bookmark
							you just saved, and long-press it → <strong>Edit</strong>.
						</li>
						<li>
							Replace the URL with what you copied (paste from clipboard).
							Replace the title with something memorable like
							&ldquo;Outpost: Reply.&rdquo;
						</li>
						<li>Tap Done. The bookmark now runs the bookmarklet on any page.</li>
					</ol>
					Repeat for each variant you want. <strong>The Home Screen app +
					Share sheet route is faster</strong> if you only need one or two of
					these.
				</dd>
				<dt>Any device — clipboard fallback</dt>
				<dd>
					The <strong>Copy source</strong> button puts the bookmarklet on your
					clipboard so you can paste it into whatever bookmark editor your
					browser provides.
				</dd>
			</dl>
		</>
	);
}
