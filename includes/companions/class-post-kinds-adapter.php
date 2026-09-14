<?php
/**
 * Post Kinds for IndieWeb adapter.
 *
 * Surfaces capability slugs for every Post Kind taxonomy term the
 * composer can produce — one slug per kind in Post Kinds' default
 * registry (class-taxonomy.php), spelled post-kinds.<kind-slug>. The
 * composer's variant pickers expose these surfaces across the Post,
 * Reply, Photo, Doing, Life, and Recipe tabs; the adapter declares
 * them so code that asks "is post-kinds.listen supported?" gets the
 * right answer.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Post_Kinds_Adapter extends Outpost_Companion_Base {

	public function file(): string {
		return OUTPOST_POST_KINDS_PLUGIN_FILE;
	}

	public function label(): string {
		return 'Post Kinds for IndieWeb';
	}

	/** @return string[] */
	public function feature_slugs(): array {
		return array(
			'post-kinds.note',
			'post-kinds.article',
			'post-kinds.reply',
			'post-kinds.like',
			'post-kinds.repost',
			'post-kinds.bookmark',
			'post-kinds.rsvp',
			'post-kinds.checkin',
			'post-kinds.listen',
			'post-kinds.watch',
			'post-kinds.read',
			'post-kinds.event',
			'post-kinds.photo',
			'post-kinds.video',
			'post-kinds.review',
			'post-kinds.favorite',
			'post-kinds.jam',
			'post-kinds.wish',
			'post-kinds.mood',
			'post-kinds.acquisition',
			'post-kinds.drink',
			'post-kinds.eat',
			'post-kinds.recipe',
			'post-kinds.play',
			'post-kinds.audio',
			// `quote` matches Post Kinds' taxonomy slug — the earlier
			// `post-kinds.quotation` spelling matched nothing real.
			'post-kinds.quote',
			'post-kinds.tag',
			'post-kinds.weather',
			'post-kinds.exercise',
			'post-kinds.trip',
			'post-kinds.itinerary',
			'post-kinds.follow',
			'post-kinds.issue',
			'post-kinds.question',
			'post-kinds.sleep',
			'post-kinds.craft',
		);
	}

	/**
	 * Mood vocabulary resolved by Post Kinds, for server-rendered surfaces.
	 *
	 * Post Kinds owns the mood labels and the site's spelling setting;
	 * `\PKIW\Mood_Vocabulary` (PKIW 1.8.2-rc.1+, PKIW #211) is the one
	 * source for PHP, the block editor and companions. Outpost keeps no mood
	 * list of its own, so on an older Post Kinds without that class this
	 * returns an empty list and the mood field stays free text.
	 *
	 * Entries that don't match the documented shape are dropped rather than
	 * passed on.
	 *
	 * @return list<array{key: string, label: string, variants: list<string>}>
	 */
	public function mood_vocabulary(): array {
		$callback = array( '\PKIW\Mood_Vocabulary', 'get_moods' );
		if ( ! class_exists( $callback[0] ) || ! is_callable( $callback ) ) {
			return array();
		}

		$moods = call_user_func( $callback );
		if ( ! is_array( $moods ) ) {
			return array();
		}

		$out = array();
		foreach ( $moods as $mood ) {
			if ( ! is_array( $mood ) || ! isset( $mood['key'], $mood['label'] ) || ! is_string( $mood['key'] ) || ! is_string( $mood['label'] ) ) {
				continue;
			}
			$variants = isset( $mood['variants'] ) && is_array( $mood['variants'] )
				? array_values( array_filter( $mood['variants'], 'is_string' ) )
				: array();
			$out[]    = array(
				'key'      => $mood['key'],
				'label'    => $mood['label'],
				'variants' => $variants,
			);
		}
		return $out;
	}
}
