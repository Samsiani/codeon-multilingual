<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Strings\StringTranslator;

/**
 * @covers \Samsiani\CodeonMultilingual\Strings\StringTranslator::hash
 */
final class StringTranslatorTest extends TestCase {

	public function test_hash_is_md5_of_domain_pipe_context_pipe_source(): void {
		$this->assertSame(
			md5( 'default||Submit' ),
			StringTranslator::hash( 'default', '', 'Submit' )
		);
	}

	public function test_hash_includes_context_in_key(): void {
		$without_context = StringTranslator::hash( 'default', '', 'Cart' );
		$with_context    = StringTranslator::hash( 'default', 'noun', 'Cart' );

		$this->assertNotSame( $without_context, $with_context );
	}

	public function test_hash_includes_domain_in_key(): void {
		$default = StringTranslator::hash( 'default', '', 'Cart' );
		$woo     = StringTranslator::hash( 'woocommerce', '', 'Cart' );

		$this->assertNotSame( $default, $woo );
	}

	public function test_hash_is_deterministic(): void {
		$this->assertSame(
			StringTranslator::hash( 'woocommerce', 'button', 'Add to cart' ),
			StringTranslator::hash( 'woocommerce', 'button', 'Add to cart' )
		);
	}

	public function test_hash_returns_32_char_hex(): void {
		$h = StringTranslator::hash( 'd', 'c', 's' );
		$this->assertSame( 32, strlen( $h ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $h );
	}
}
