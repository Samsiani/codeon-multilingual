<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Integration;

use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use Samsiani\CodeonMultilingual\Migration\PolylangImporter;

/**
 * @group integration
 */
final class PolylangImporterIntegrationTest extends IntegrationTestCase {

	public function test_import_all_imports_polylang_languages_posts_terms_and_reports_string_warning(): void {
		global $wpdb;

		$this->register_polylang_taxonomies();
		$language_taxonomies = $this->seed_polylang_languages();
		update_option( 'polylang', array( 'default_lang' => 'en' ) );

		$source_post = self::factory()->post->create( array( 'post_title' => 'Source', 'post_status' => 'publish' ) );
		$ka_post     = self::factory()->post->create( array( 'post_title' => 'Georgian', 'post_status' => 'publish' ) );
		$post_group  = $this->create_taxonomy_marker( 'post_translations', 'pll-post-group' );

		$this->assign_polylang_taxonomies( $source_post, $language_taxonomies['en'], $post_group );
		$this->assign_polylang_taxonomies( $ka_post, $language_taxonomies['ka'], $post_group );

		$source_term = self::factory()->term->create( array( 'taxonomy' => 'category', 'name' => 'Source cat' ) );
		$ka_term     = self::factory()->term->create( array( 'taxonomy' => 'category', 'name' => 'KA cat' ) );
		$term_group  = $this->create_taxonomy_marker( 'term_translations', 'pll-term-group' );

		$this->assign_polylang_taxonomies( $source_term, $language_taxonomies['term_en'], $term_group );
		$this->assign_polylang_taxonomies( $ka_term, $language_taxonomies['term_ka'], $term_group );

		self::factory()->post->create( array( 'post_type' => 'polylang_mo', 'post_status' => 'publish' ) );
		$this->clear_codeon_language_rows( array( $source_post, $ka_post ), array( $source_term, $ka_term ) );

		$summary = PolylangImporter::summary();
		$this->assertSame( 2, $summary['languages'] );
		$this->assertSame( 2, $summary['posts'] );
		$this->assertSame( 2, $summary['terms'] );
		$this->assertSame( 1, $summary['strings'] );
		$this->assertNotEmpty( $summary['warnings'] );

		$result = PolylangImporter::import_all();

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 1, $result['languages'] );
		$this->assertSame( 2, $result['posts'] );
		$this->assertSame( 2, $result['terms'] );
		$this->assertSame( 'en', TranslationGroups::get_language( $source_post ) );
		$this->assertSame( 'ka', TranslationGroups::get_language( $ka_post ) );
		$this->assertSame( 'en', TranslationGroups::get_term_language( $source_term ) );
		$this->assertSame( 'ka', TranslationGroups::get_term_language( $ka_term ) );

		$this->assertSame(
			'ka',
			(string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT code FROM {$wpdb->prefix}cml_languages WHERE code = %s",
					'ka'
				)
			)
		);
	}

	public function test_import_all_blocks_conflicting_existing_codeon_rows_without_allow_conflicts(): void {
		$this->register_polylang_taxonomies();
		$language_taxonomies = $this->seed_polylang_languages();
		update_option( 'polylang', array( 'default_lang' => 'en' ) );

		$post_id    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$post_group = $this->create_taxonomy_marker( 'post_translations', 'pll-post-conflict' );
		$this->assign_polylang_taxonomies( $post_id, $language_taxonomies['ka'], $post_group );
		$this->tag_post_language( $post_id, 999, 'en' );

		$this->assertSame( 1, PolylangImporter::conflict_summary()['post_mappings'] );

		$result = PolylangImporter::import_all();

		$this->assertNotEmpty( $result['errors'] );
		$this->assertSame( 'en', TranslationGroups::get_language( $post_id ) );
	}

	/**
	 * @return array{en:int,ka:int,term_en:int,term_ka:int}
	 */
	private function seed_polylang_languages(): array {
		return array(
			'en'      => $this->create_language_taxonomy( 'language', 'en', 'English', 'en_US', 'us' ),
			'ka'      => $this->create_language_taxonomy( 'language', 'ka', 'Georgian', 'ka_GE', 'ge' ),
			'term_en' => $this->create_language_taxonomy( 'term_language', 'en', 'English', 'en_US', 'us' ),
			'term_ka' => $this->create_language_taxonomy( 'term_language', 'ka', 'Georgian', 'ka_GE', 'ge' ),
		);
	}

	private function register_polylang_taxonomies(): void {
		foreach ( array( 'language', 'term_language', 'post_translations', 'term_translations' ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				register_taxonomy( $taxonomy, array( 'post', 'page' ) );
			}
		}
	}

	private function create_language_taxonomy( string $taxonomy, string $slug, string $name, string $locale, string $flag ): int {
		global $wpdb;

		$term_id = (int) self::factory()->term->create(
			array(
				'taxonomy'    => $taxonomy,
				'name'        => $name,
				'slug'        => $slug,
				'description' => serialize(
					array(
						'locale'    => $locale,
						'flag_code' => $flag,
						'rtl'       => 0,
					)
				),
			)
		);

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s",
				$term_id,
				$taxonomy
			)
		);
	}

	private function create_taxonomy_marker( string $taxonomy, string $slug ): int {
		global $wpdb;

		$term_id = (int) self::factory()->term->create(
			array(
				'taxonomy' => $taxonomy,
				'name'     => $slug,
				'slug'     => $slug,
			)
		);

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s",
				$term_id,
				$taxonomy
			)
		);
	}

	private function assign_polylang_taxonomies( int $object_id, int $language_tt_id, int $group_tt_id ): void {
		global $wpdb;

		foreach ( array( $language_tt_id, $group_tt_id ) as $term_taxonomy_id ) {
			$wpdb->replace(
				$wpdb->term_relationships,
				array(
					'object_id'        => $object_id,
					'term_taxonomy_id' => $term_taxonomy_id,
					'term_order'       => 0,
				),
				array( '%d', '%d', '%d' )
			);
		}
	}

	/**
	 * @param array<int,int> $post_ids
	 * @param array<int,int> $term_ids
	 */
	private function clear_codeon_language_rows( array $post_ids, array $term_ids ): void {
		global $wpdb;

		if ( array() !== $post_ids ) {
			$post_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder list is generated from the number of integer ids.
			$wpdb->query(
				$wpdb->prepare( "DELETE FROM {$wpdb->prefix}cml_post_language WHERE post_id IN ({$post_placeholders})", ...$post_ids )
			);
		}

		if ( array() !== $term_ids ) {
			$term_placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder list is generated from the number of integer ids.
			$wpdb->query(
				$wpdb->prepare( "DELETE FROM {$wpdb->prefix}cml_term_language WHERE term_id IN ({$term_placeholders})", ...$term_ids )
			);
		}

		TranslationGroups::flush();
	}
}
