<?php
/**
 * The pending-syndication editor payload can't shape the HTML around it
 * (T10-S4-07, L6-07).
 *
 * The payload is printed inside a <script> element. Labels can come from
 * the outpost_manual_share_platforms filter and the message from a
 * translation, so the JSON is emitted with <, >, &, ' and " hex-escaped:
 * no `<!--`, `<script`, or `</script>` sequence can appear in the element.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class PendingSyndicationInlinePayloadTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'Outpost_Pending_Syndication_Notice' ) || ! function_exists( 'wp_json_encode' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap.' );
		}
	}

	public function test_the_inline_payload_carries_no_markup_characters_and_round_trips(): void {
		$payload = array(
			'message'   => 'Pending <!--<script> and </script> & "quotes" \'apostrophes\'',
			'platforms' => array(
				array(
					'id'    => 'x-twitter',
					'label' => '<img src=x onerror=alert(1)>',
				),
			),
		);

		$script = \Outpost_Pending_Syndication_Notice::inline_payload( $payload );

		$this->assertStringStartsWith( 'window.' . \Outpost_Pending_Syndication_Notice::PAYLOAD_GLOBAL . ' = ', $script );
		foreach ( array( '<', '>', '&' ) as $char ) {
			$this->assertStringNotContainsString( $char, $script, "The inline script contains no raw $char." );
		}
		$json = substr( $script, strlen( 'window.' . \Outpost_Pending_Syndication_Notice::PAYLOAD_GLOBAL . ' = ' ), -1 );
		$this->assertSame( $payload, json_decode( $json, true ), 'The payload decodes back unchanged.' );
	}
}
