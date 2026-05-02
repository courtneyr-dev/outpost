<?php
/**
 * Accessibility Checker adapter (Equalize Digital).
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Accessibility_Checker_Adapter extends Outpost_Companion_Base {

	public function file(): string {
		return OUTPOST_ACCESSIBILITY_CHECKER_PLUGIN_FILE;
	}

	public function label(): string {
		return 'Accessibility Checker';
	}

	/** @return string[] */
	public function capabilities(): array {
		return array( 'accessibility-checker.report' );
	}
}
