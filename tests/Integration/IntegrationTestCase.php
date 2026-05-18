<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Integration;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\Schema;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use Samsiani\CodeonMultilingual\Query\PostsClauses;

abstract class IntegrationTestCase extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		Schema::install();
		$this->reset_cml_tables();
		$this->insert_language( 'en', 'en_US', 'English', 'English', true, 0 );
		$this->reset_cml_runtime();

		update_option( 'permalink_structure', '/%postname%/' );
		$GLOBALS['wp_rewrite']->init();
	}

	protected function tearDown(): void {
		$this->reset_cml_runtime();

		parent::tearDown();
	}

	protected function add_language( string $code, string $locale, string $name, string $native, bool $default = false, int $position = 10 ): void {
		$this->insert_language( $code, $locale, $name, $native, $default, $position );
		$this->reset_cml_runtime();
	}

	protected function set_default_language( string $code ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'cml_languages';
		$wpdb->update( $table, array( 'is_default' => 0 ), array( 'is_default' => 1 ), array( '%d' ), array( '%d' ) );
		$wpdb->update( $table, array( 'is_default' => 1 ), array( 'code' => $code ), array( '%d' ), array( '%s' ) );
		$this->reset_cml_runtime();
	}

	protected function tag_post_language( int $post_id, int $group_id, string $language ): void {
		global $wpdb;

		$wpdb->replace(
			$wpdb->prefix . 'cml_post_language',
			array(
				'post_id'  => $post_id,
				'group_id' => $group_id,
				'language' => $language,
			),
			array( '%d', '%d', '%s' )
		);
		TranslationGroups::flush();
		PostsClauses::reset_cache();
	}

	protected function cml_table_exists( string $suffix ): bool {
		global $wpdb;

		$table = $wpdb->prefix . $suffix;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	protected function skip_without_woocommerce(): void {
		if (
			! defined( 'CML_TEST_HAS_WOOCOMMERCE' )
			|| ! CML_TEST_HAS_WOOCOMMERCE
			|| ! function_exists( 'wc_get_product' )
			|| ! class_exists( '\WC_Product_Variable' )
		) {
			$this->markTestSkipped( 'WooCommerce is not available in this integration test environment.' );
		}
	}

	private function reset_cml_tables(): void {
		global $wpdb;

		foreach (
			array(
				'cml_string_translations',
				'cml_strings',
				'cml_term_language',
				'cml_post_language',
				'cml_languages',
			) as $suffix
		) {
			$table = $wpdb->prefix . $suffix;
			if ( $this->cml_table_exists( $suffix ) ) {
				$wpdb->query( "DELETE FROM {$table}" );
			}
		}
	}

	private function insert_language( string $code, string $locale, string $name, string $native, bool $default, int $position ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'cml_languages';
		if ( $default ) {
			$wpdb->update( $table, array( 'is_default' => 0 ), array( 'is_default' => 1 ), array( '%d' ), array( '%d' ) );
		}

		$wpdb->replace(
			$table,
			array(
				'code'       => $code,
				'locale'     => $locale,
				'name'       => $name,
				'native'     => $native,
				'flag'       => $code,
				'rtl'        => 0,
				'active'     => 1,
				'is_default' => $default ? 1 : 0,
				'position'   => $position,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' )
		);
	}

	private function reset_cml_runtime(): void {
		CurrentLanguage::reset();
		Languages::flush_cache();
		TranslationGroups::flush();
		PostsClauses::reset_cache();
	}
}
