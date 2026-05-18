<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Integration;

use Samsiani\CodeonMultilingual\Core\Settings;

/**
 * @group integration
 */
final class UninstallPolicyTest extends IntegrationTestCase {

	public function test_uninstall_preserves_data_by_default(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		Settings::save( array( 'delete_data_on_uninstall' => false ) );
		require dirname( CML_TEST_PLUGIN_FILE ) . '/uninstall.php';

		$this->assertTrue( $this->cml_table_exists( 'cml_languages' ) );
		$this->assertTrue( $this->cml_table_exists( 'cml_post_language' ) );
	}

	public function test_uninstall_drops_data_when_opted_in(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		Settings::save( array( 'delete_data_on_uninstall' => true ) );
		require dirname( CML_TEST_PLUGIN_FILE ) . '/uninstall.php';

		$this->assertFalse( $this->cml_table_exists( 'cml_languages' ) );
		$this->assertFalse( $this->cml_table_exists( 'cml_post_language' ) );
	}
}
