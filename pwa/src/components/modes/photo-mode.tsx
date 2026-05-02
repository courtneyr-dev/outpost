/**
 * Photo mode — placeholder for Phase C2.
 *
 * Will handle photo posts: the Micropub media endpoint upload flow with
 * client-side EXIF stripping (privacy: prevents location leak from camera
 * roll) and required alt text per the Hard Contract's "structural, not
 * configurable" rule. A "decorative" toggle submits `alt=""` for purely
 * decorative images.
 *
 * Phase C2 lands the upload pipeline. Pairs with the security checklist
 * items: MIME validation against an allowlist (JPEG, PNG, WebP, GIF,
 * AVIF; SVG excluded until a separate sanitizer is wired), 10 MB size
 * cap (configurable via filter), Canvas-based downscale before upload.
 */

export function PhotoMode() {
	return (
		<section class="outpost-card" aria-labelledby="outpost-photo-mode-title">
			<h2 id="outpost-photo-mode-title" class="outpost-card__title">
				Photo
			</h2>
			<p class="outpost-card__lede">
				Photo posting with EXIF stripping and required alt text lands in Phase C2. Uses the
				Micropub media endpoint; MIME allowlist + 10 MB size cap.
			</p>
		</section>
	);
}
