<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Integration;

use Samsiani\CodeonMultilingual\Core\HealthRepair;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;

/**
 * @group integration
 */
final class HealthRepairIntegrationTest extends IntegrationTestCase {

	public function test_repair_deletes_orphans_backfills_public_rows_and_skips_internal_terms(): void {
		global $wpdb;

		if ( ! taxonomy_exists( 'product_type' ) ) {
			register_taxonomy( 'product_type', 'product' );
		}

		$this->add_language( 'ka', 'ka_GE', 'Georgian', 'Georgian' );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category', 'name' => 'Repairable' ) );
		$system_term_id = self::factory()->term->create( array( 'taxonomy' => 'product_type', 'name' => 'simple' ) );

		$wpdb->insert(
			$wpdb->prefix . 'cml_post_language',
			array(
				'post_id'  => 987654,
				'group_id' => 987654,
				'language' => 'ka',
			),
			array( '%d', '%d', '%s' )
		);
		$wpdb->insert(
			$wpdb->prefix . 'cml_term_language',
			array(
				'term_id'  => 987654,
				'group_id' => 987654,
				'language' => 'ka',
			),
			array( '%d', '%d', '%s' )
		);
		$wpdb->insert(
			$wpdb->prefix . 'cml_strings',
			array(
				'hash'            => md5( 'repair', true ),
				'domain'          => 'test',
				'context'         => '',
				'source'          => 'Repair me',
				'source_language' => 'zz',
				'created_at'      => time(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		$dry_run = HealthRepair::run( 'all', true );
		$this->assertGreaterThanOrEqual( 5, $dry_run['total'] );
		$this->assertFalse( $dry_run['actions']['missing-term-rows']['applied'] );

		$result = HealthRepair::run( 'all', false );

		$this->assertGreaterThanOrEqual( 5, $result['total'] );
		$this->assertSame( 'en', TranslationGroups::get_language( $post_id ) );
		$this->assertSame( 'en', TranslationGroups::get_term_language( $term_id ) );
		$this->assertNull( TranslationGroups::get_term_language( $system_term_id ) );
		$this->assertSame(
			0,
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cml_post_language WHERE post_id = 987654" )
		);
		$this->assertSame(
			0,
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cml_term_language WHERE term_id = 987654" )
		);
		$this->assertSame(
			'en',
			(string) $wpdb->get_var( "SELECT source_language FROM {$wpdb->prefix}cml_strings WHERE source = 'Repair me'" )
		);
	}
}
