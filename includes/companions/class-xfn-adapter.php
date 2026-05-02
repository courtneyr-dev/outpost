<?php
/**
 * Link Extension for XFN adapter.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_XFN_Adapter extends Outpost_Companion_Base {

	public function file(): string {
		return OUTPOST_LINK_EXTENSION_XFN_PLUGIN_FILE;
	}

	public function label(): string {
		return 'Link Extension for XFN';
	}

	/** @return string[] */
	public function capabilities(): array {
		return array( 'xfn.relationships' );
	}
}
