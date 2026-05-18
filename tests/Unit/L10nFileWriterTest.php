<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Strings\L10nFileWriter;

/**
 * @covers \Samsiani\CodeonMultilingual\Strings\L10nFileWriter::file_path
 */
final class L10nFileWriterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_upload_dir' )->justReturn( array( 'basedir' => '/tmp/uploads' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_file_path_preserves_safe_locale(): void {
		$this->assertSame(
			'/tmp/uploads/cml-translations/woocommerce-ka_GE.l10n.php',
			L10nFileWriter::file_path( 'WooCommerce', 'ka_GE' )
		);
	}

	public function test_file_path_never_uses_path_separators_from_locale(): void {
		$path = L10nFileWriter::file_path( 'woocommerce', '../../evil' );

		$this->assertSame( '/tmp/uploads/cml-translations/woocommerce-unknown.l10n.php', $path );
		$this->assertStringNotContainsString( '..', basename( $path ) );
		$this->assertStringNotContainsString( '/', basename( $path ) );
	}
}
