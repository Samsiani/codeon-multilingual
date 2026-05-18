<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Integration;

use Samsiani\CodeonMultilingual\Core\Schema;

/**
 * @group integration
 */
final class ActivationTest extends \WP_UnitTestCase {

	public function test_schema_install_creates_core_tables(): void {
		Schema::install();

		global $wpdb;
		$tables = array(
			$wpdb->prefix . 'cml_languages',
			$wpdb->prefix . 'cml_post_language',
			$wpdb->prefix . 'cml_term_language',
			$wpdb->prefix . 'cml_strings',
			$wpdb->prefix . 'cml_string_translations',
		);

		foreach ( $tables as $table ) {
			$this->assertSame(
				$table,
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) )
			);
		}
	}
}
