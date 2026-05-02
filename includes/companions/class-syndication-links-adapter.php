<?php
/**
 * Syndication Links adapter.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Syndication_Links_Adapter extends Outpost_Companion_Base {

	public function file(): string {
		return OUTPOST_SYNDICATION_LINKS_PLUGIN_FILE;
	}

	public function label(): string {
		return 'Syndication Links';
	}

	/** @return string[] */
	public function capabilities(): array {
		return array( 'syndication.chips' );
	}
}
