<?php
/**
 * G4b — concrete schema extractor unit tests.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Article_Extractor;
use Outpost_Book_Extractor;
use Outpost_Event_Extractor;
use Outpost_Recipe_Extractor;
use Outpost_Restaurant_Extractor;
use WP_Mock\Tools\TestCase;

final class G4bExtractorsTest extends TestCase {

	private const FIXTURE_DIR = __DIR__ . '/../fixtures/og-inbound/';

	/**
	 * Pull the JSON-LD block from a fixture file.
	 *
	 * @param string $filename File name under fixtures/og-inbound/.
	 * @return array<string,mixed>
	 */
	private function load_jsonld( string $filename ): array {
		$html = file_get_contents( self::FIXTURE_DIR . $filename );
		if ( false === $html || ! preg_match( '~<script type="application/ld\+json">(.*?)</script>~s', $html, $m ) ) {
			$this->fail( "Couldn't extract JSON-LD from fixture {$filename}" );
		}
		$decoded = json_decode( $m[1], true );
		$this->assertIsArray( $decoded, "JSON-LD in {$filename} is malformed" );
		return $decoded;
	}

	// --- Article ----------------------------------------------------------

	public function test_article_extractor_supports_article_newsarticle_blogposting(): void {
		$e = new Outpost_Article_Extractor();
		$this->assertContains( 'Article', $e->supported_types() );
		$this->assertContains( 'NewsArticle', $e->supported_types() );
		$this->assertContains( 'BlogPosting', $e->supported_types() );
	}

	public function test_article_extracts_news_article_fixture(): void {
		$block  = $this->load_jsonld( 'article.html' );
		$result = ( new Outpost_Article_Extractor() )->extract( $block, 'https://example.com/article' );

		$this->assertSame( 'NewsArticle', $result['type'] );
		$this->assertSame( 'Example article headline', $result['headline'] );
		$this->assertSame( 'A paragraph-length summary of the article.', $result['description'] );
		$this->assertSame( array( 'Alice Smith', 'Bob Jones' ), $result['author'] );
		$this->assertSame( 'Example Press', $result['publisher'] );
		$this->assertSame( '2026-04-15T08:30:00-04:00', $result['date_published'] );
		$this->assertSame( '2026-04-15T14:22:00-04:00', $result['date_modified'] );
		$this->assertSame( 'https://example.com/article-cover.jpg', $result['image'] );
		$this->assertSame( 'Technology', $result['article_section'] );
		$this->assertSame( array( 'wordpress', 'indieweb', 'posse', 'federation' ), $result['keywords'] );
		$this->assertSame( 1842, $result['word_count'] );
	}

	public function test_article_handles_missing_optionals(): void {
		$result = ( new Outpost_Article_Extractor() )->extract(
			array( '@type' => 'Article', 'headline' => 'Just a headline' ),
			'https://example.com/'
		);
		$this->assertSame( 'Article', $result['type'] );
		$this->assertSame( 'Just a headline', $result['headline'] );
		$this->assertSame( '', $result['description'] );
		$this->assertSame( array(), $result['author'] );
		$this->assertNull( $result['word_count'] );
	}

	// --- Recipe -----------------------------------------------------------

	public function test_recipe_extractor_supports_recipe(): void {
		$this->assertSame( array( 'Recipe' ), ( new Outpost_Recipe_Extractor() )->supported_types() );
	}

	public function test_recipe_extracts_full_fixture(): void {
		$block  = $this->load_jsonld( 'recipe.html' );
		$result = ( new Outpost_Recipe_Extractor() )->extract( $block, 'https://example.com/recipe' );

		$this->assertSame( 'Skillet Roast Chicken', $result['name'] );
		$this->assertSame( array( 'Alice Smith' ), $result['author'] );
		$this->assertSame( 15, $result['prep_time'] );
		$this->assertSame( 45, $result['cook_time'] );
		$this->assertSame( 60, $result['total_time'] );
		$this->assertSame( '4 servings', $result['recipe_yield'] );
		$this->assertSame( 'Main Course', $result['recipe_category'] );
		$this->assertSame( 'American', $result['recipe_cuisine'] );
		$this->assertCount( 6, $result['ingredients'] );
		$this->assertSame( '1 whole chicken (3-4 lbs)', $result['ingredients'][0] );
		$this->assertCount( 7, $result['instructions'] );
		$this->assertSame( 'Preheat oven to 425F.', $result['instructions'][0] );
		$this->assertSame( '385 kcal', $result['nutrition']['calories'] );
		$this->assertSame( '22 g', $result['nutrition']['fat_content'] );
		$this->assertSame( 4.7, $result['aggregate_rating']['rating'] );
		$this->assertSame( 248, $result['aggregate_rating']['count'] );
	}

	public function test_recipe_handles_string_instructions(): void {
		$result = ( new Outpost_Recipe_Extractor() )->extract(
			array( 'name' => 'X', 'recipeInstructions' => "Step 1.\nStep 2.\nStep 3." ),
			'https://example.com/'
		);
		$this->assertSame( array( 'Step 1.', 'Step 2.', 'Step 3.' ), $result['instructions'] );
	}

	public function test_recipe_handles_iso_duration_with_hours_only(): void {
		$result = ( new Outpost_Recipe_Extractor() )->extract(
			array( 'name' => 'X', 'cookTime' => 'PT2H' ),
			'https://example.com/'
		);
		$this->assertSame( 120, $result['cook_time'] );
	}

	public function test_recipe_returns_null_nutrition_when_missing(): void {
		$result = ( new Outpost_Recipe_Extractor() )->extract(
			array( 'name' => 'X' ),
			'https://example.com/'
		);
		$this->assertNull( $result['nutrition'] );
	}

	// --- Event ------------------------------------------------------------

	public function test_event_extractor_supports_event_subtypes(): void {
		$types = ( new Outpost_Event_Extractor() )->supported_types();
		$this->assertContains( 'Event', $types );
		$this->assertContains( 'MusicEvent', $types );
		$this->assertContains( 'Festival', $types );
	}

	public function test_event_extracts_full_fixture(): void {
		$block  = $this->load_jsonld( 'event.html' );
		$result = ( new Outpost_Event_Extractor() )->extract( $block, 'https://example.com/event' );

		$this->assertSame( 'Event', $result['type'] );
		$this->assertSame( 'WordCamp Example 2026', $result['name'] );
		$this->assertSame( '2026-09-12T09:00:00-04:00', $result['start_date'] );
		$this->assertSame( '2026-09-13T17:00:00-04:00', $result['end_date'] );
		$this->assertSame( 'Example Convention Center', $result['location_name'] );
		$this->assertSame( '100 Main Street, Springfield, IL, 62701, US', $result['location_address'] );
		$this->assertSame( array( 'WordCamp Example Organizers' ), $result['organizer'] );
		$this->assertSame( array( 'Alice Smith', 'Bob Jones' ), $result['performer'] );
		$this->assertSame( 'EventScheduled', $result['event_status'] );
		$this->assertSame( 'MixedEventAttendanceMode', $result['event_attendance_mode'] );
		$this->assertCount( 1, $result['offers'] );
		$this->assertSame( 50.0, $result['offers'][0]['price'] );
		$this->assertSame( 'USD', $result['offers'][0]['currency'] );
		$this->assertSame( 'InStock', $result['offers'][0]['availability'] );
	}

	public function test_event_handles_virtual_location(): void {
		$result = ( new Outpost_Event_Extractor() )->extract(
			array(
				'name'     => 'Online Event',
				'location' => array(
					'@type' => 'VirtualLocation',
					'url'   => 'https://example.com/livestream',
				),
			),
			'https://example.com/'
		);
		$this->assertSame( 'https://example.com/livestream', $result['location_address'] );
	}

	// --- Book -------------------------------------------------------------

	public function test_book_extractor_supports_book_audiobook(): void {
		$types = ( new Outpost_Book_Extractor() )->supported_types();
		$this->assertContains( 'Book', $types );
		$this->assertContains( 'Audiobook', $types );
	}

	public function test_book_extracts_full_fixture(): void {
		$block  = $this->load_jsonld( 'book.html' );
		$result = ( new Outpost_Book_Extractor() )->extract( $block, 'https://example.com/book' );

		$this->assertSame( 'The Example Handbook', $result['name'] );
		$this->assertSame( array( 'Alice Smith' ), $result['author'] );
		$this->assertSame( '9780123456789', $result['isbn'] );
		$this->assertSame( 'Hardcover', $result['book_format'] );
		$this->assertSame( 384, $result['number_of_pages'] );
		$this->assertSame( 'Example Press', $result['publisher'] );
		$this->assertSame( '2025-11-04', $result['date_published'] );
		$this->assertSame( 'en', $result['in_language'] );
		$this->assertSame( 4.3, $result['aggregate_rating']['rating'] );
	}

	public function test_book_isbn_normalisation_strips_hyphens(): void {
		$result = ( new Outpost_Book_Extractor() )->extract(
			array( 'isbn' => '978-0-123-45678-9' ),
			'https://example.com/'
		);
		$this->assertSame( '9780123456789', $result['isbn'] );
	}

	public function test_book_isbn_rejects_non_isbn_length(): void {
		$result = ( new Outpost_Book_Extractor() )->extract(
			array( 'isbn' => '12345' ),
			'https://example.com/'
		);
		$this->assertSame( '', $result['isbn'] );
	}

	// --- Restaurant -------------------------------------------------------

	public function test_restaurant_extractor_supports_food_subtypes(): void {
		$types = ( new Outpost_Restaurant_Extractor() )->supported_types();
		$this->assertContains( 'Restaurant', $types );
		$this->assertContains( 'CafeOrCoffeeShop', $types );
		$this->assertContains( 'Bakery', $types );
	}

	public function test_restaurant_extracts_full_fixture(): void {
		$block  = $this->load_jsonld( 'restaurant.html' );
		$result = ( new Outpost_Restaurant_Extractor() )->extract( $block, 'https://example.com/cafe' );

		$this->assertSame( 'The Example Cafe', $result['name'] );
		$this->assertSame( '200 Oak Avenue, Springfield, IL, 62702, US', $result['address'] );
		$this->assertSame( '+1-555-0100', $result['telephone'] );
		$this->assertSame( array( 'American', 'Vegetarian' ), $result['serves_cuisine'] );
		$this->assertSame( '$$', $result['price_range'] );
		$this->assertSame( 39.7817, $result['geo']['lat'] );
		$this->assertSame( -89.6501, $result['geo']['lng'] );
		$this->assertCount( 2, $result['opening_hours'] );
		$this->assertStringContainsString( 'Monday', $result['opening_hours'][0] );
		$this->assertStringContainsString( '07:00-19:00', $result['opening_hours'][0] );
		$this->assertSame( 4.5, $result['aggregate_rating']['rating'] );
	}

	public function test_restaurant_returns_null_geo_when_missing(): void {
		$result = ( new Outpost_Restaurant_Extractor() )->extract(
			array( 'name' => 'X' ),
			'https://example.com/'
		);
		$this->assertNull( $result['geo'] );
	}

	// --- Trait helpers ----------------------------------------------------

	public function test_iso_duration_rejects_malformed_input(): void {
		// Use the Recipe extractor as the trait carrier.
		$result = ( new Outpost_Recipe_Extractor() )->extract(
			array( 'name' => 'X', 'cookTime' => 'not-a-duration' ),
			'https://example.com/'
		);
		$this->assertNull( $result['cook_time'] );
	}

	public function test_iso_duration_accepts_combined_h_m(): void {
		$result = ( new Outpost_Recipe_Extractor() )->extract(
			array( 'name' => 'X', 'totalTime' => 'PT1H30M' ),
			'https://example.com/'
		);
		$this->assertSame( 90, $result['total_time'] );
	}

	public function test_postal_address_handles_string(): void {
		$result = ( new Outpost_Restaurant_Extractor() )->extract(
			array( 'name' => 'X', 'address' => '500 Elm Street, Anytown, USA' ),
			'https://example.com/'
		);
		$this->assertSame( '500 Elm Street, Anytown, USA', $result['address'] );
	}
}
