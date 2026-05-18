<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Compat\ValueLocalizer;

final class ValueLocalizerTest extends TestCase {

	public function test_walk_localizes_nested_ids_and_urls_by_policy(): void {
		$value = array(
			'post_id' => 10,
			'items'   => array(
				array(
					'term_id' => 5,
					'url'     => 'https://example.test/shop/product/',
				),
			),
		);

		$result = ValueLocalizer::walk(
			$value,
			'ka',
			array(
				'post_keys' => array(),
				'term_keys' => array(),
				'url_keys'  => array(),
			)
		);

		$this->assertSame( $value, $result );
	}

	public function test_walk_preserves_scalar_values_without_policy_match(): void {
		$value = array(
			'title' => 'Hello',
			'ids'   => array( 1, 2, 3 ),
		);

		$this->assertSame( $value, ValueLocalizer::walk( $value, 'ka' ) );
	}
}
