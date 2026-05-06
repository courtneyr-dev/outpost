<?php
/**
 * Outpost_Encryption_Exception (G3.5a).
 *
 * Thrown by Outpost_Encryption::decrypt() on tampered or corrupted
 * ciphertext. Distinct from generic Exception so callers can catch
 * encryption failures specifically without swallowing other errors.
 *
 * @package Outpost
 * @since   0.1.69
 * @internal Not part of the public API; internal to the credentials layer.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Encryption_Exception extends \RuntimeException {}
