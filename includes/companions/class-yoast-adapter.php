<?php
/**
 * Yoast SEO adapter.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Yoast_Adapter extends Outpost_Companion_Base {

	public function file(): string {
		return OUTPOST_YOAST_PLUGIN_FILE;
	}

	public function label(): string {
		return 'Yoast SEO';
	}

	/** @return string[] */
	public function feature_slugs(): array {
		return array( 'yoast.focus-keyphrase' );
	}
}
