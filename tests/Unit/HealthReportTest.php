<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Core\HealthReport;
use Samsiani\CodeonMultilingual\Core\HealthRepair;

/**
 * Pure health-report inspectors. Full report generation needs a real wpdb.
 */
final class HealthReportTest extends TestCase {

	public function test_inspect_language_rows_flags_missing_default_duplicates_and_invalid_rows(): void {
		$rows = array(
			(object) array(
				'code'       => 'en',
				'locale'     => 'en_US',
				'name'       => 'English',
				'native'     => 'English',
				'active'     => 1,
				'is_default' => 0,
			),
			(object) array(
				'code'       => 'ka',
				'locale'     => 'en_US',
				'name'       => '',
				'native'     => 'Georgian',
				'active'     => 1,
				'is_default' => 0,
			),
			(object) array(
				'code'       => 'ka',
				'locale'     => 'bad/locale',
				'name'       => 'Duplicate',
				'native'     => 'Duplicate',
				'active'     => 2,
				'is_default' => 0,
			),
		);

		$inspection = HealthReport::inspect_language_rows( $rows );

		$this->assertSame( 3, $inspection['total'] );
		$this->assertTrue( $inspection['missing_default'] );
		$this->assertSame( array( 'ka' => 2 ), $inspection['duplicate_codes'] );
		$this->assertSame( array( 'en_us' => 2 ), $inspection['duplicate_locales'] );
		$this->assertCount( 2, $inspection['invalid_rows'] );
		$this->assertContains( 'missing_name', $inspection['invalid_rows'][0]['reasons'] );
		$this->assertContains( 'invalid_locale', $inspection['invalid_rows'][1]['reasons'] );
		$this->assertContains( 'invalid_active_flag', $inspection['invalid_rows'][1]['reasons'] );
	}

	public function test_inspect_language_rows_detects_multiple_and_inactive_defaults(): void {
		$rows = array(
			array(
				'code'       => 'en',
				'locale'     => 'en_US',
				'name'       => 'English',
				'native'     => 'English',
				'active'     => '1',
				'is_default' => '1',
			),
			array(
				'code'       => 'ka',
				'locale'     => 'ka_GE',
				'name'       => 'Georgian',
				'native'     => 'Georgian',
				'active'     => '0',
				'is_default' => '1',
			),
		);

		$inspection = HealthReport::inspect_language_rows( $rows );

		$this->assertFalse( $inspection['missing_default'] );
		$this->assertTrue( $inspection['multiple_defaults'] );
		$this->assertSame( array( 'en', 'ka' ), $inspection['default_rows'] );
		$this->assertSame( array( 'ka' ), $inspection['inactive_defaults'] );
		$this->assertSame( array(), $inspection['invalid_rows'] );
	}

	public function test_status_from_counts_prioritizes_critical_then_warning(): void {
		$this->assertSame( HealthReport::STATUS_CRITICAL, HealthReport::status_from_counts( 1, 0 ) );
		$this->assertSame( HealthReport::STATUS_WARNING, HealthReport::status_from_counts( 0, 1 ) );
		$this->assertSame( HealthReport::STATUS_OK, HealthReport::status_from_counts( 0, 0 ) );
	}

	public function test_health_repair_classifies_internal_term_taxonomies(): void {
		$this->assertTrue( HealthRepair::is_system_term_taxonomy( 'product_type' ) );
		$this->assertTrue( HealthRepair::is_system_term_taxonomy( 'product_visibility' ) );
		$this->assertTrue( HealthRepair::is_system_term_taxonomy( 'language' ) );
		$this->assertTrue( HealthRepair::is_system_term_taxonomy( 'post_translations' ) );
		$this->assertFalse( HealthRepair::is_system_term_taxonomy( 'product_cat' ) );
		$this->assertFalse( HealthRepair::is_system_term_taxonomy( 'pa_color' ) );
	}

	public function test_health_repair_normalizes_unknown_scopes_to_all(): void {
		$this->assertSame( 'all', HealthRepair::normalize_scope( 'unknown' ) );
		$this->assertSame( 'missing-term-rows', HealthRepair::normalize_scope( 'missing-term-rows' ) );
	}
}
