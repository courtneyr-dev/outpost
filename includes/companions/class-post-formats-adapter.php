<?php
/**
 * Post Formats for Block Themes adapter.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Post_Formats_Adapter extends Outpost_Companion_Base {

	public function file(): string {
		return OUTPOST_POST_FORMATS_PLUGIN_FILE;
	}

	public function label(): string {
		return 'Post Formats for Block Themes';
	}

	/** @return string[] */
	public function capabilities(): array {
		return array(
			'post-formats.format',
			'post-formats.inference',
		);
	}
}
