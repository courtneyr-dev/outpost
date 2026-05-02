/**
 * Listen group mode — placeholder for Phase C3.
 *
 * Will handle the life-tracking post kinds: Listen, Watch, Read, Checkin,
 * Play. These are gated on Post Kinds for IndieWeb (the WordPress
 * companion that ships these custom post types) — when Post Kinds isn't
 * active, the Listen group tab is hidden via the capability detector.
 *
 * Each kind maps to a different mf2 vocabulary:
 *   - Listen → u-listen-of (album/track URL)
 *   - Watch  → u-watch-of  (movie/show URL)
 *   - Read   → u-read-of   (book identifier)
 *   - Checkin → p-location  + h-card  (place + visit context)
 *   - Play   → u-play-of   (game URL)
 *
 * Phase C3 lands the sub-mode picker for these kinds. The composer-side
 * pattern matches Reply mode (target URL + optional context); the post
 * kind determines which mf2 properties get populated.
 */

export function ListenMode() {
	return (
		<section class="outpost-card" aria-labelledby="outpost-listen-mode-title">
			<h2 id="outpost-listen-mode-title" class="outpost-card__title">
				Listen group
			</h2>
			<p class="outpost-card__lede">
				Listen, Watch, Read, Checkin, and Play modes land in Phase C3. Gated on Post Kinds for
				IndieWeb being active.
			</p>
		</section>
	);
}
