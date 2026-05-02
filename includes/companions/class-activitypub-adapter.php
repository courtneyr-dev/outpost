<?php
/**
 * ActivityPub adapter.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_ActivityPub_Adapter extends Outpost_Companion_Base {

	public function file(): string {
		return OUTPOST_ACTIVITYPUB_PLUGIN_FILE;
	}

	public function label(): string {
		return 'ActivityPub';
	}

	/** @return string[] */
	public function capabilities(): array {
		return array( 'activitypub.federate' );
	}
}
