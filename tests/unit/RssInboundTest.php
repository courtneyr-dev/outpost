<?php
/**
 * Outpost_Rss_Inbound unit tests (F5 #6).
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Rss_Inbound;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Lightweight SimplePie_Item stand-in. Only implements the methods
 * Outpost_Rss_Inbound calls; missing methods produce defaults via the
 * extractor's call_if_exists guard.
 */
class F56FakeItem {
	public string $title       = '';
	public string $content     = '';
	public string $description = '';
	public string $permalink   = '';
	public string $id          = '';
	/** @var int|string|null */
	public $date = null;
	/** @var int|string|null */
	public $updated = null;
	public ?object $author     = null;
	/** @var array<int,object|string> */
	public array $categories = array();
	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tags     = array();
	public ?object $enclosure = null;

	public function get_title(): string {
		return $this->title;
	}
	public function get_content(): string {
		return $this->content;
	}
	public function get_description(): string {
		return $this->description;
	}
	public function get_permalink(): string {
		return $this->permalink;
	}
	public function get_id(): string {
		return $this->id;
	}
	public function get_date() {
		return $this->date;
	}
	public function get_updated_date() {
		return $this->updated;
	}
	public function get_author() {
		return $this->author;
	}
	public function get_categories() {
		return $this->categories;
	}
	public function get_enclosure() {
		return $this->enclosure;
	}
	public function get_item_tags( string $namespace, string $tag ) {
		return $this->tags[ $namespace . '|' . $tag ] ?? null;
	}
}

class F56FakeFeed {
	public string $title          = '';
	/** @var array<int,F56FakeItem> */
	public array $items = array();

	public function get_title(): string {
		return $this->title;
	}
	/**
	 * @return array<int,F56FakeItem>
	 */
	public function get_items(): array {
		return $this->items;
	}
}

class F56FakeAuthor {
	public string $name = '';
	public string $link = '';

	public function __construct( string $name = '', string $link = '' ) {
		$this->name = $name;
		$this->link = $link;
	}
	public function get_name(): string {
		return $this->name;
	}
	public function get_link(): string {
		return $this->link;
	}
}

class F56FakeCategory {
	public string $label = '';
	public function __construct( string $label ) {
		$this->label = $label;
	}
	public function get_label(): string {
		return $this->label;
	}
}

class F56FakeEnclosure {
	public string $type = '';
	public string $link = '';
	public function __construct( string $type, string $link ) {
		$this->type = $type;
		$this->link = $link;
	}
	public function get_type(): string {
		return $this->type;
	}
	public function get_link(): string {
		return $this->link;
	}
}

final class RssInboundTest extends TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		Outpost_Rss_Inbound::set_feed_resolver_for_tests( null );
		Outpost_Rss_Inbound::set_page_resolver_for_tests( null );
		WP_Mock::userFunction( 'wp_kses_post' )->andReturnUsing(
			static function ( $html ) {
				return preg_replace( '#<(script|style|iframe)\b[^>]*>.*?</\1>#is', '', (string) $html ) ?? $html;
			}
		);
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing(
			static function ( $url ) { return parse_url( $url ); }
		);
	}

	public function tearDown(): void {
		Outpost_Rss_Inbound::set_feed_resolver_for_tests( null );
		Outpost_Rss_Inbound::set_page_resolver_for_tests( null );
		WP_Mock::tearDown();
	}

	private function make_feed_with_item( F56FakeItem $item ): F56FakeFeed {
		$feed         = new F56FakeFeed();
		$feed->title  = 'Test Feed';
		$feed->items  = array( $item );
		return $feed;
	}

	// ---------- Mode A — discovery ----------

	public function test_mode_a_discovers_feed_via_html_link(): void {
		$item            = new F56FakeItem();
		$item->title     = 'Hello World';
		$item->content   = '<p>Body</p>';
		$item->permalink = 'https://example.com/2026/05/post-one';
		$item->id        = 'tag:example.com,2026:p/1';

		$feed = $this->make_feed_with_item( $item );

		Outpost_Rss_Inbound::set_page_resolver_for_tests( static function ( $url ) {
			return '<html><head><link rel="alternate" type="application/rss+xml" href="https://example.com/feed.xml"></head></html>';
		} );
		Outpost_Rss_Inbound::set_feed_resolver_for_tests( static function ( $url ) use ( $feed ) {
			return 'https://example.com/feed.xml' === $url ? $feed : null;
		} );

		$result = Outpost_Rss_Inbound::extract_from_url( 'https://example.com/2026/05/post-one' );

		$this->assertTrue( $result['extracted'] );
		$this->assertSame( 'Hello World', $result['title'] );
		$this->assertSame( '<p>Body</p>', $result['content'] );
		$this->assertSame( 'https://example.com/feed.xml', $result['feed_url'] );
		$this->assertSame( 'Test Feed', $result['feed_title'] );
	}

	public function test_mode_a_no_feed_link_returns_no_feed_link(): void {
		Outpost_Rss_Inbound::set_page_resolver_for_tests( static function () {
			return '<html><head><title>No feed link here</title></head></html>';
		} );

		$result = Outpost_Rss_Inbound::extract_from_url( 'https://example.com/post' );

		$this->assertFalse( $result['extracted'] );
		$this->assertSame( 'no_feed_link', $result['reason'] );
	}

	public function test_mode_a_feed_has_no_matching_entry(): void {
		$item            = new F56FakeItem();
		$item->permalink = 'https://example.com/different-post';

		$feed = $this->make_feed_with_item( $item );

		Outpost_Rss_Inbound::set_page_resolver_for_tests( static function () {
			return '<link rel="alternate" type="application/rss+xml" href="https://example.com/feed.xml">';
		} );
		Outpost_Rss_Inbound::set_feed_resolver_for_tests( static function () use ( $feed ) {
			return $feed;
		} );

		$result = Outpost_Rss_Inbound::extract_from_url( 'https://example.com/2026/05/post-one' );

		$this->assertFalse( $result['extracted'] );
		$this->assertSame( 'no_matching_feed_entry', $result['reason'] );
	}

	public function test_mode_a_transport_failed_when_page_unavailable(): void {
		Outpost_Rss_Inbound::set_page_resolver_for_tests( static function () {
			return null;
		} );

		$result = Outpost_Rss_Inbound::extract_from_url( 'https://example.com/post' );

		$this->assertFalse( $result['extracted'] );
		$this->assertSame( 'transport_failed', $result['reason'] );
	}

	public function test_mode_a_atom_feed_link_also_discovered(): void {
		$item            = new F56FakeItem();
		$item->title     = 'Atom entry';
		$item->permalink = 'https://example.com/atom-post';

		$feed = $this->make_feed_with_item( $item );

		Outpost_Rss_Inbound::set_page_resolver_for_tests( static function () {
			return '<link rel="alternate" type="application/atom+xml" href="https://example.com/feed.atom">';
		} );
		Outpost_Rss_Inbound::set_feed_resolver_for_tests( static function () use ( $feed ) {
			return $feed;
		} );

		$result = Outpost_Rss_Inbound::extract_from_url( 'https://example.com/atom-post' );

		$this->assertTrue( $result['extracted'] );
		$this->assertSame( 'https://example.com/feed.atom', $result['feed_url'] );
	}

	// ---------- Mode B — direct feed ----------

	public function test_mode_b_finds_entry_by_guid(): void {
		$item            = new F56FakeItem();
		$item->title     = 'Direct';
		$item->id        = 'tag:example.com,2026:direct/1';
		$item->permalink = 'https://example.com/direct';

		$feed = $this->make_feed_with_item( $item );

		Outpost_Rss_Inbound::set_feed_resolver_for_tests( static function () use ( $feed ) {
			return $feed;
		} );

		$result = Outpost_Rss_Inbound::extract_from_feed( 'https://example.com/feed.xml', 'tag:example.com,2026:direct/1' );

		$this->assertTrue( $result['extracted'] );
		$this->assertSame( 'Direct', $result['title'] );
	}

	public function test_mode_b_unknown_guid_returns_entry_not_in_feed(): void {
		$item        = new F56FakeItem();
		$item->id    = 'tag:example.com,2026:item/1';

		$feed = $this->make_feed_with_item( $item );

		Outpost_Rss_Inbound::set_feed_resolver_for_tests( static function () use ( $feed ) {
			return $feed;
		} );

		$result = Outpost_Rss_Inbound::extract_from_feed( 'https://example.com/feed.xml', 'unknown-guid' );

		$this->assertFalse( $result['extracted'] );
		$this->assertSame( 'entry_not_in_feed', $result['reason'] );
	}

	public function test_malformed_feed_returns_transport_failed(): void {
		Outpost_Rss_Inbound::set_feed_resolver_for_tests( static function () {
			return null; // Simulates fetch_feed returning a WP_Error / non-object.
		} );

		$result = Outpost_Rss_Inbound::extract_from_feed( 'https://example.com/bad.xml', 'any-guid' );

		$this->assertFalse( $result['extracted'] );
		$this->assertSame( 'transport_failed', $result['reason'] );
	}

	// ---------- Field extraction edge cases ----------

	public function test_uses_content_when_present_summary_is_distinct_description(): void {
		$item              = new F56FakeItem();
		$item->id          = 'g';
		$item->permalink   = 'https://example.com/post';
		$item->content     = '<p>Full article body</p>';
		$item->description = 'Short summary blurb.';

		$feed = $this->make_feed_with_item( $item );
		Outpost_Rss_Inbound::set_feed_resolver_for_tests( static function () use ( $feed ) { return $feed; } );

		$result = Outpost_Rss_Inbound::extract_from_feed( 'https://example.com/feed.xml', 'g' );

		$this->assertSame( '<p>Full article body</p>', $result['content'] );
		$this->assertSame( 'Short summary blurb.', $result['summary'] );
	}

	public function test_summary_is_null_when_description_equals_content(): void {
		$item              = new F56FakeItem();
		$item->id          = 'g';
		$item->permalink   = 'https://example.com/post';
		$item->content     = '<p>Body</p>';
		$item->description = '<p>Body</p>';

		$feed = $this->make_feed_with_item( $item );
		Outpost_Rss_Inbound::set_feed_resolver_for_tests( static function () use ( $feed ) { return $feed; } );

		$result = Outpost_Rss_Inbound::extract_from_feed( 'https://example.com/feed.xml', 'g' );

		$this->assertNull( $result['summary'] );
	}

	public function test_falls_back_to_dc_creator_for_author(): void {
		$item            = new F56FakeItem();
		$item->id        = 'g';
		$item->permalink = 'https://example.com/post';
		$item->author    = null;
		$item->tags      = array(
			'http://purl.org/dc/elements/1.1/|creator' => array(
				array( 'data' => 'Jane Author' ),
			),
		);

		$feed = $this->make_feed_with_item( $item );
		Outpost_Rss_Inbound::set_feed_resolver_for_tests( static function () use ( $feed ) { return $feed; } );

		$result = Outpost_Rss_Inbound::extract_from_feed( 'https://example.com/feed.xml', 'g' );

		$this->assertSame( 'Jane Author', $result['author'] );
		$this->assertNull( $result['author_url'] );
	}

	public function test_extracts_media_thumbnail_as_icon_url(): void {
		$item            = new F56FakeItem();
		$item->id        = 'g';
		$item->permalink = 'https://example.com/post';
		$item->tags      = array(
			'http://search.yahoo.com/mrss/|thumbnail' => array(
				array(
					'data'    => '',
					'attribs' => array(
						'' => array( 'url' => 'https://cdn.example.com/thumb.jpg' ),
					),
				),
			),
		);

		$feed = $this->make_feed_with_item( $item );
		Outpost_Rss_Inbound::set_feed_resolver_for_tests( static function () use ( $feed ) { return $feed; } );

		$result = Outpost_Rss_Inbound::extract_from_feed( 'https://example.com/feed.xml', 'g' );

		$this->assertSame( 'https://cdn.example.com/thumb.jpg', $result['icon_url'] );
	}

	public function test_extracts_categories_as_array(): void {
		$item            = new F56FakeItem();
		$item->id        = 'g';
		$item->permalink = 'https://example.com/post';
		$item->categories = array(
			new F56FakeCategory( 'lectionary' ),
			new F56FakeCategory( 'easter-3-c' ),
			new F56FakeCategory( 'gospel' ),
		);

		$feed = $this->make_feed_with_item( $item );
		Outpost_Rss_Inbound::set_feed_resolver_for_tests( static function () use ( $feed ) { return $feed; } );

		$result = Outpost_Rss_Inbound::extract_from_feed( 'https://example.com/feed.xml', 'g' );

		$this->assertSame( array( 'lectionary', 'easter-3-c', 'gospel' ), $result['categories'] );
	}

	public function test_published_date_returned_as_iso8601(): void {
		$item            = new F56FakeItem();
		$item->id        = 'g';
		$item->permalink = 'https://example.com/post';
		$item->date      = 1714838400; // 2024-05-04T16:00:00Z

		$feed = $this->make_feed_with_item( $item );
		Outpost_Rss_Inbound::set_feed_resolver_for_tests( static function () use ( $feed ) { return $feed; } );

		$result = Outpost_Rss_Inbound::extract_from_feed( 'https://example.com/feed.xml', 'g' );

		$this->assertSame( '2024-05-04T16:00:00+00:00', $result['published'] );
	}

	public function test_content_sanitized_via_wp_kses_post(): void {
		$item            = new F56FakeItem();
		$item->id        = 'g';
		$item->permalink = 'https://example.com/post';
		$item->content   = '<p>Clean</p><script>alert("x")</script>';

		$feed = $this->make_feed_with_item( $item );
		Outpost_Rss_Inbound::set_feed_resolver_for_tests( static function () use ( $feed ) { return $feed; } );

		$result = Outpost_Rss_Inbound::extract_from_feed( 'https://example.com/feed.xml', 'g' );

		$this->assertStringContainsString( '<p>Clean</p>', $result['content'] );
		$this->assertStringNotContainsString( '<script>', $result['content'] );
	}

	public function test_author_url_passes_through_when_set(): void {
		$item            = new F56FakeItem();
		$item->id        = 'g';
		$item->permalink = 'https://example.com/post';
		$item->author    = new F56FakeAuthor( 'Sam Author', 'https://samauthor.example' );

		$feed = $this->make_feed_with_item( $item );
		Outpost_Rss_Inbound::set_feed_resolver_for_tests( static function () use ( $feed ) { return $feed; } );

		$result = Outpost_Rss_Inbound::extract_from_feed( 'https://example.com/feed.xml', 'g' );

		$this->assertSame( 'Sam Author', $result['author'] );
		$this->assertSame( 'https://samauthor.example', $result['author_url'] );
	}

	public function test_enclosure_image_used_as_icon_when_no_media_thumbnail(): void {
		$item            = new F56FakeItem();
		$item->id        = 'g';
		$item->permalink = 'https://example.com/post';
		$item->enclosure = new F56FakeEnclosure( 'image/jpeg', 'https://cdn.example.com/encl.jpg' );

		$feed = $this->make_feed_with_item( $item );
		Outpost_Rss_Inbound::set_feed_resolver_for_tests( static function () use ( $feed ) { return $feed; } );

		$result = Outpost_Rss_Inbound::extract_from_feed( 'https://example.com/feed.xml', 'g' );

		$this->assertSame( 'https://cdn.example.com/encl.jpg', $result['icon_url'] );
	}

	// ---------- discover_feed_link unit tests ----------

	public function test_discover_feed_link_finds_rss_first_when_both_present(): void {
		$html = '<head>'
			. '<link rel="alternate" type="application/atom+xml" href="https://example.com/feed.atom">'
			. '<link rel="alternate" type="application/rss+xml" href="https://example.com/feed.xml">'
			. '</head>';

		$this->assertSame(
			'https://example.com/feed.xml',
			Outpost_Rss_Inbound::discover_feed_link( $html )
		);
	}

	public function test_discover_feed_link_returns_null_when_no_match(): void {
		$html = '<head><link rel="canonical" href="https://example.com"></head>';

		$this->assertNull( Outpost_Rss_Inbound::discover_feed_link( $html ) );
	}

	public function test_discover_feed_link_handles_attribute_order_variation(): void {
		$html = '<link rel="alternate" href="https://example.com/feed.xml" type="application/rss+xml">';

		$this->assertSame(
			'https://example.com/feed.xml',
			Outpost_Rss_Inbound::discover_feed_link( $html )
		);
	}
}
