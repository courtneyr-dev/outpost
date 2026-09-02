/**
 * Share-sheet guide — how to send a link, quote, or photo into Outpost from
 * your device's system share sheet. Rendered in the About tab, next to the
 * bookmarklets, as a static setup helper (no state, no fetches).
 *
 * The setup differs by platform, and the difference is real, not cosmetic:
 *
 *   - Android + desktop Chromium register Outpost as a Web Share Target once
 *     it is installed as an app (the manifest declares `share_target` at
 *     /post/share-target). Sharing then routes into the composer, prefilled.
 *   - iOS Safari has no Web Share Target API, so a web app cannot join the
 *     share sheet on its own. iOS users add a Shortcut that opens the same
 *     /post/share-target route with the shared content, which is why this
 *     guide teaches building one by hand — the one-tap iCloud Shortcut on the
 *     wp-admin setup page is not published in every build.
 *
 * URLs are generated from `location.origin` so the examples point at the
 * reader's own site. Links open in a new tab (matching the About tab) so the
 * reader doesn't lose a composer they were mid-typing in.
 */

function origin(): string {
	return typeof window !== 'undefined' && window.location ? window.location.origin : '';
}

interface OutLinkProps {
	href: string;
	children: preact.ComponentChildren;
}

function OutLink({ href, children }: OutLinkProps): preact.JSX.Element {
	return (
		<a href={href} target="_blank" rel="noopener noreferrer">
			{children}
		</a>
	);
}

export function ShareSheetGuide(): preact.JSX.Element {
	const site        = origin();
	const shareRoute  = site + '/post/share-target';
	const shortcutAdmin = site + '/wp-admin/options-general.php?page=outpost-ios-shortcut';

	return (
		<section class="outpost-share-guide" aria-labelledby="outpost-share-guide-title">
			<h3 id="outpost-share-guide-title">Add Outpost to your share sheet</h3>
			<p>
				Send a link, a quote, or a photo straight into Outpost from any app using
				your device&apos;s share sheet. The setup is different on Android and iOS
				because the two platforms handle share targets differently.
			</p>

			<h4>Android &amp; desktop Chrome / Edge</h4>
			<ol>
				<li>
					Install Outpost as an app: open the browser menu and choose{' '}
					<strong>Install app</strong> (or <strong>Add to Home Screen</strong>).
				</li>
				<li>
					Once it&apos;s installed, Outpost appears in your device&apos;s{' '}
					<strong>share sheet</strong> automatically — there&apos;s nothing else to
					set up.
				</li>
				<li>
					Share a page or some text from any app, pick <strong>Outpost</strong>,
					and the composer opens already filled in.
				</li>
			</ol>
			<p>What each kind of share turns into:</p>
			<ul>
				<li>A link → a <strong>Reply</strong>, with the link as the target.</li>
				<li>A link plus selected text → a <strong>Reply</strong>, with the text as your reply.</li>
				<li>Plain text → a <strong>Note</strong>.</li>
				<li>A title and text (some apps send both) → an <strong>Article</strong>.</li>
			</ul>

			<h4>iPhone &amp; iPad (Safari)</h4>
			<p>
				iOS Safari doesn&apos;t support the Web Share Target API, so a web app
				can&apos;t join the share sheet by itself. You add a small Shortcut
				instead. Two ways:
			</p>
			<p>
				<strong>Guided setup.</strong> Your site has a setup page in wp-admin —{' '}
				<OutLink href={shortcutAdmin}>Settings → Outpost iOS Shortcut</OutLink> —
				that hands you a ready-made Shortcut and the token it needs. If the
				one-tap Shortcut link there isn&apos;t published in your version yet, use
				the manual steps below; they work today.
			</p>
			<p>
				<strong>Manual (works today, no token).</strong> Build a Shortcut that
				opens Outpost&apos;s share route:
			</p>
			<ol>
				<li>
					Open the <strong>Shortcuts</strong> app, tap <strong>+</strong> to make
					a new one.
				</li>
				<li>
					The first action reads <em>Receive … from Nowhere</em>. Tap{' '}
					<strong>Nowhere</strong> and choose <strong>Share Sheet</strong> —
					that&apos;s what makes the shortcut appear when you share. Then tap
					the types (it starts as <em>Images and 18 more</em>), clear them, and
					pick <strong>URLs</strong> and <strong>Text</strong>. On older iOS
					this is the <strong>Show in Share Sheet</strong> toggle under the ⓘ
					details instead.
				</li>
				<li>
					Search for the action named <strong>Open URLs</strong> — not{' '}
					<em>Share</em>, which only reopens the share sheet — and add it. Set
					its URL to your share route with the shared item as the input:
					<br />
					<code class="outpost-share-guide__url">{shareRoute}?url=</code>
					<code>[Shortcut Input]</code>
					<br />
					With the cursor at the very end, tap <em>Shortcut Input</em> in the
					variable bar above the keyboard to insert it after{' '}
					<code>url=</code>. (Sharing plain text instead of a link? Use{' '}
					<code>?text=</code> in place of <code>?url=</code>.)
				</li>
				<li>
					Tap the shortcut&apos;s name at the top, choose <strong>Rename</strong>,
					and call it <strong>Post to Outpost</strong>.
				</li>
			</ol>
			<p>
				Now <strong>Post to Outpost</strong> shows up in your share sheet.
				Choosing it opens the composer prefilled, where you review and post. It
				uses the sign-in you already have in the app, so there&apos;s no token to
				manage.
			</p>
			<aside class="outpost-bookmarklet-tip" role="note">
				<strong>Want a share-and-forget button?</strong> The guided setup&apos;s
				Shortcut posts straight to your site through a scoped token, publishing
				without opening the composer. Use that if you don&apos;t want to review
				first; use the manual URL Shortcut above if you&apos;d rather see the post
				before it goes out.
			</aside>
		</section>
	);
}
