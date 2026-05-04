<?php
/**
 * Post Kinds for IndieWeb adapter.
 *
 * Surfaces capability slugs for each Post Kind taxonomy term. The
 * composer's variant pickers (Reply tab's 6 variants, Doing tab's 5)
 * already expose these surfaces; the adapter just declares them so
 * code that asks "is post-kinds.listen supported?" gets the right
 * answer.
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
			'post-kinds.listen',
			'post-kinds.watch',
			'post-kinds.read',
			'post-kinds.play',
			'post-kinds.checkin',
			'post-kinds.like',
			'post-kinds.repost',
			'post-kinds.bookmark',
			'post-kinds.rsvp',
			'post-kinds.follow',
			'post-kinds.quotation',
		);
	}
}
