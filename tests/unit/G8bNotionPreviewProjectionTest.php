<?php
/**
 * G8b — Notion preview projection unit tests.
 *
 * Exercises Outpost_Preview_Endpoint's project_notion_result_for_preview
 * via reflection — a pure function over Notion's fetch_page payload
 * shape that doesn't need wp-env to test. The end-to-end share-target
 * dispatch lives in tests/integration/NotionShareTargetPreviewTest.php
 * (skipped pending wp-env).
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Preview_Endpoint;
use ReflectionClass;
use WP_Mock\Tools\TestCase;

final class G8bNotionPreviewProjectionTest extends TestCase {

	/**
	 * Invoke the private projection method via reflection.
	 *
	 * @param array<string,mixed> $result Notion fetch_page output.
	 * @return array<string,mixed>
	 */
	private function project( array $result ): array {
		$ref    = new ReflectionClass( Outpost_Preview_Endpoint::class );
		$method = $ref->getMethod( 'project_notion_result_for_preview' );
		$method->setAccessible( true );
		$out = $method->invoke( null, $result );
		return is_array( $out ) ? $out : array();
	}

	public function test_projects_title_from_database_title_property(): void {
		$result = array(
			'id'     => 'page-abc',
			'page'   => array(
				'properties' => array(
					'title' => array(
						'title' => array(
							array( 'plain_text' => 'My Notion Page' ),
						),
					),
				),
			),
			'blocks' => array(),
		);
		$out    = $this->project( $result );
		$this->assertSame( 'My Notion Page', $out['p-name'] );
		$this->assertSame( 'page-abc', $out['notion-page-id'] );
	}

	public function test_projects_title_from_workspace_name_property(): void {
		$result = array(
			'id'     => 'page-xyz',
			'page'   => array(
				'properties' => array(
					'Name' => array(
						'title' => array(
							array( 'plain_text' => 'Alice' ),
							array( 'plain_text' => "'s notes" ),
						),
					),
				),
			),
			'blocks' => array(),
		);
		$out    = $this->project( $result );
		$this->assertSame( "Alice's notes", $out['p-name'] );
	}

	public function test_projects_emoji_icon(): void {
		$result = array(
			'page'   => array(
				'icon' => array( 'type' => 'emoji', 'emoji' => '📓' ),
			),
			'blocks' => array(),
		);
		$out    = $this->project( $result );
		$this->assertSame( '📓', $out['notion-icon'] );
	}

	public function test_projects_external_icon_url(): void {
		$result = array(
			'page'   => array(
				'icon' => array(
					'type'     => 'external',
					'external' => array( 'url' => 'https://example.com/icon.png' ),
				),
			),
			'blocks' => array(),
		);
		$out    = $this->project( $result );
		$this->assertSame( 'https://example.com/icon.png', $out['notion-icon'] );
	}

	public function test_projects_file_icon_url(): void {
		$result = array(
			'page'   => array(
				'icon' => array(
					'type' => 'file',
					'file' => array( 'url' => 'https://prod-files-secure.s3.amazonaws.com/icon.png' ),
				),
			),
			'blocks' => array(),
		);
		$out    = $this->project( $result );
		$this->assertSame( 'https://prod-files-secure.s3.amazonaws.com/icon.png', $out['notion-icon'] );
	}

	public function test_projects_cover_external_url(): void {
		$result = array(
			'page'   => array(
				'cover' => array(
					'external' => array( 'url' => 'https://example.com/cover.jpg' ),
				),
			),
			'blocks' => array(),
		);
		$out    = $this->project( $result );
		$this->assertSame( 'https://example.com/cover.jpg', $out['u-photo'] );
	}

	public function test_projects_summary_from_first_paragraph_blocks(): void {
		$result = array(
			'page'   => array(),
			'blocks' => array(
				array(
					'type'      => 'paragraph',
					'paragraph' => array(
						'rich_text' => array(
							array( 'plain_text' => 'First paragraph.' ),
						),
					),
				),
				array(
					'type'      => 'heading_2',
					'heading_2' => array(
						'rich_text' => array(
							array( 'plain_text' => 'A subhead' ),
						),
					),
				),
				array(
					'type'      => 'paragraph',
					'paragraph' => array(
						'rich_text' => array(
							array( 'plain_text' => 'More text.' ),
						),
					),
				),
			),
		);
		$out    = $this->project( $result );
		$this->assertSame( "First paragraph.\nA subhead\nMore text.", $out['p-summary'] );
		$this->assertSame( 3, $out['notion-block-count'] );
	}

	public function test_summary_caps_at_five_blocks(): void {
		$blocks = array();
		for ( $i = 1; $i <= 8; $i++ ) {
			$blocks[] = array(
				'type'      => 'paragraph',
				'paragraph' => array(
					'rich_text' => array( array( 'plain_text' => "Line {$i}" ) ),
				),
			);
		}
		$out = $this->project( array( 'page' => array(), 'blocks' => $blocks ) );
		// Five collected, but block_count records the full input length.
		$this->assertSame( "Line 1\nLine 2\nLine 3\nLine 4\nLine 5", $out['p-summary'] );
		$this->assertSame( 8, $out['notion-block-count'] );
	}

	public function test_summary_skips_unsupported_block_types(): void {
		$result = array(
			'page'   => array(),
			'blocks' => array(
				array(
					'type'  => 'image',
					'image' => array(
						'external' => array( 'url' => 'https://example.com/x.jpg' ),
					),
				),
				array(
					'type'      => 'paragraph',
					'paragraph' => array(
						'rich_text' => array(
							array( 'plain_text' => 'After the image.' ),
						),
					),
				),
			),
		);
		$out    = $this->project( $result );
		$this->assertSame( 'After the image.', $out['p-summary'] );
	}

	public function test_handles_empty_page_and_blocks_gracefully(): void {
		$out = $this->project( array() );
		$this->assertSame( '', $out['p-name'] );
		$this->assertSame( '', $out['u-photo'] );
		$this->assertSame( '', $out['p-summary'] );
		$this->assertSame( '', $out['notion-icon'] );
		$this->assertSame( '', $out['notion-page-id'] );
		$this->assertSame( 0, $out['notion-block-count'] );
	}
}
