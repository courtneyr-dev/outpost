<?php
/**
 * Outpost_Source_Extractor_Not_Implemented_Exception
 *
 * Thrown by extractor stubs whose parser body lands in a later
 * Phase F session. Lets F5 ship the dispatch architecture without
 * the parser implementations — F7 (Spotify, oEmbed) needs only
 * Extractor_Oembed; later sessions add og_tags (F16), mf2, rss,
 * api_json, api_xml, composite.
 *
 * Endpoint code catches this exception and surfaces a 501 Not
 * Implemented response, OR (per the F5 contract for Source_Unknown)
 * falls through to the legacy B2 generic preview behavior so Reply
 * mode keeps working until F16 lands the og_tags parser.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Extractor_Not_Implemented_Exception extends \RuntimeException {

	/**
	 * Extractor type ID that isn't implemented yet.
	 *
	 * @var string
	 */
	public string $extractor_id;

	public function __construct( string $extractor_id, string $message = '' ) {
		$this->extractor_id = $extractor_id;
		if ( '' === $message ) {
			$message = sprintf(
				'Outpost extractor "%s" has no parser implementation in this build. ' .
				'See CLAUDE.md Phase F session log for which session lands it.',
				$extractor_id
			);
		}
		parent::__construct( esc_html( $message ) );
	}
}
