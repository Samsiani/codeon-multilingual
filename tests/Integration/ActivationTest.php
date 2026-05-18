<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Integration;

use Samsiani\CodeonMultilingual\Core\Activator;
use Samsiani\CodeonMultilingual\Core\Backfill;
use Samsiani\CodeonMultilingual\Core\Schema;

/**
 * @group integration
 */
final class ActivationTest extends IntegrationTestCase {

	public function test_schema_install_creates_core_tables(): void {
		Schema::install();

		$tables = array(
			'cml_languages',
			'cml_post_language',
			'cml_term_language',
			'cml_strings',
			'cml_string_translations',
		);

		foreach ( $tables as $table ) {
			$this->assertTrue( $this->cml_table_exists( $table ), "{$table} table should exist." );
		}
	}

	public function test_activation_sets_schema_version_and_backfill_state(): void {
		Activator::activate();

		$this->assertSame( Schema::VERSION, get_option( 'cml_db_version' ) );
		$this->assertGreaterThan( 0, (int) get_option( 'cml_activated_at' ) );
		$this->assertSame( 'pending', get_option( Backfill::OPTION_STATUS ) );
		$this->assertNotFalse( wp_next_scheduled( Backfill::HOOK ) );
	}
}
