<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Integration;

use Samsiani\CodeonMultilingual\Core\Schema;
use Samsiani\CodeonMultilingual\Core\Settings;

/**
 * @group integration
 */
final class UninstallPolicyTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();

		Schema::install();
	}

	protected function tearDown(): void {
		delete_option( 'cml_settings' );
		Schema::install();

		parent::tearDown();
	}

	public function test_uninstall_preserves_data_by_default(): void {
		$this->define_uninstall_constant();

		Settings::save( array( 'delete_data_on_uninstall' => false ) );
		require dirname( CML_TEST_PLUGIN_FILE ) . '/uninstall.php';

		self::assertTrue( $this->cml_table_exists( 'cml_languages' ) );
		self::assertTrue( $this->cml_table_exists( 'cml_post_language' ) );
	}

	public function test_uninstall_drops_data_when_opted_in(): void {
		$this->define_uninstall_constant();

		Settings::save( array( 'delete_data_on_uninstall' => true ) );
		require dirname( CML_TEST_PLUGIN_FILE ) . '/uninstall.php';

		self::assertFalse( $this->cml_table_exists( 'cml_languages' ) );
		self::assertFalse( $this->cml_table_exists( 'cml_post_language' ) );
	}

	private function define_uninstall_constant(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
	}

	private function cml_table_exists( string $suffix ): bool {
		global $wpdb;

		$table = $wpdb->prefix . $suffix;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
