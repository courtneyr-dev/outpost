<?php
/**
 * Outpost_Post_Kinds_Adapter comics support, with and without Post Kinds'
 * \PKIW\Comic_Strip marker class (outpost#243).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Post_Kinds_Adapter;
use PHPUnit\Framework\TestCase;

final class PostKindsAdapterComicsTest extends TestCase {

	public function test_comics_feature_slug_is_declared(): void {
		$this->assertContains( 'post-kinds.comics', ( new Outpost_Post_Kinds_Adapter() )->feature_slugs() );
	}

	public function test_without_the_marker_class_comics_are_not_supported(): void {
		$this->assertFalse( class_exists( '\PKIW\Comic_Strip' ), 'Precondition: older Post Kinds, no comic card.' );

		$this->assertFalse( Outpost_Post_Kinds_Adapter::supports_comics() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_with_the_marker_class_comics_are_supported(): void {
		require_once __DIR__ . '/../fixtures/pkiw/comic-strip-stub.php';

		$this->assertTrue( Outpost_Post_Kinds_Adapter::supports_comics() );
	}
}
