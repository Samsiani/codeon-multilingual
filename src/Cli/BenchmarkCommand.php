<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Cli;

use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use Samsiani\CodeonMultilingual\Strings\StringTranslator;
use WP_CLI;
use WP_CLI\Utils;
use WP_CLI_Command;

/**
 * Production performance probes for real WordPress installs.
 *
 * Examples
 *
 *     wp cml benchmark report --limit=250
 *     wp cml benchmark report --language=ka --format=json
 *     wp cml benchmark seed --strings=1000 --products=50 --variations=5 --language=ka --yes
 */
final class BenchmarkCommand extends WP_CLI_Command {

	private const DEFAULT_LIMIT      = 250;
	private const DEFAULT_STRINGS    = 1000;
	private const DEFAULT_PRODUCTS   = 20;
	private const DEFAULT_VARIATIONS = 3;

	/**
	 * Reports query count, memory delta, peak memory delta, and elapsed time.
	 *
	 * ## OPTIONS
	 *
	 * [--language=<code>]
	 * : Language used for string translation joins. Defaults to the first active
	 *   non-default language, falling back to the default language.
	 *
	 * [--limit=<n>]
	 * : Number of rows sampled per workload.
	 * ---
	 * default: 250
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function report( array $args, array $assoc_args ): void {
		$language = self::benchmark_language( $assoc_args );
		$limit    = self::bounded_int( $assoc_args['limit'] ?? null, self::DEFAULT_LIMIT, 1, 10000 );

		$metrics = array(
			self::measure(
				'strings',
				static fn(): int => self::benchmark_strings( $language, $limit )
			),
			self::measure(
				'products',
				static fn(): int => self::benchmark_post_type( 'product', $limit )
			),
			self::measure(
				'variations',
				static fn(): int => self::benchmark_post_type( 'product_variation', $limit )
			),
		);

		$rows = array_map( array( self::class, 'row_for_display' ), $metrics );
		Utils\format_items(
			(string) ( $assoc_args['format'] ?? 'table' ),
			$rows,
			array( 'workload', 'rows', 'queries', 'memory_mb', 'peak_mb', 'time_ms' )
		);
	}

	/**
	 * Seeds benchmark fixtures. Writes only additive rows/posts and requires --yes.
	 *
	 * ## OPTIONS
	 *
	 * [--strings=<n>]
	 * : Number of source strings and translations to insert.
	 * ---
	 * default: 1000
	 * ---
	 *
	 * [--products=<n>]
	 * : Number of product translation groups to insert.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--variations=<n>]
	 * : Variations to insert per product.
	 * ---
	 * default: 3
	 * ---
	 *
	 * [--language=<code>]
	 * : Target translation language. Defaults to the first active non-default language.
	 *
	 * [--yes]
	 * : Confirm that benchmark fixture rows/posts may be inserted.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function seed( array $args, array $assoc_args ): void {
		if ( ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::error( 'Seeding writes benchmark fixture data. Re-run with --yes to confirm.' );
		}

		$language   = self::benchmark_language( $assoc_args );
		$strings    = self::bounded_int( $assoc_args['strings'] ?? null, self::DEFAULT_STRINGS, 0, 100000 );
		$products   = self::bounded_int( $assoc_args['products'] ?? null, self::DEFAULT_PRODUCTS, 0, 10000 );
		$variations = self::bounded_int( $assoc_args['variations'] ?? null, self::DEFAULT_VARIATIONS, 0, 1000 );

		$string_count = $strings > 0 ? self::seed_strings( $strings, $language ) : 0;
		$post_counts  = $products > 0 ? self::seed_products( $products, $variations, $language ) : array(
			'products'   => 0,
			'variations' => 0,
		);

		StringTranslator::flush_cache();
		TranslationGroups::flush();

		WP_CLI::success(
			sprintf(
				'Seeded benchmark fixtures: strings=%d products=%d variations=%d language=%s.',
				$string_count,
				$post_counts['products'],
				$post_counts['variations'],
				$language
			)
		);
	}

	/**
	 * @param array<string,mixed> $metric
	 * @return array<string,string>
	 */
	public static function row_for_display( array $metric ): array {
		return array(
			'workload'  => (string) $metric['workload'],
			'rows'      => (string) (int) $metric['rows'],
			'queries'   => (string) (int) $metric['queries'],
			'memory_mb' => self::format_mb( (int) $metric['memory_bytes'] ),
			'peak_mb'   => self::format_mb( (int) $metric['peak_bytes'] ),
			'time_ms'   => (string) (int) $metric['time_ms'],
		);
	}

	/**
	 * @param mixed $value
	 */
	public static function bounded_int( $value, int $default, int $min, int $max ): int {
		if ( null === $value || '' === $value ) {
			return $default;
		}

		$int = (int) $value;
		if ( $int < $min ) {
			return $min;
		}
		if ( $int > $max ) {
			return $max;
		}
		return $int;
	}

	/**
	 * @param callable(): int $callback
	 * @return array{workload:string,rows:int,queries:int,memory_bytes:int,peak_bytes:int,time_ms:int}
	 */
	private static function measure( string $workload, callable $callback ): array {
		global $wpdb;

		TranslationGroups::flush();
		$queries_before = isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : 0;
		$memory_before  = memory_get_usage( true );
		$peak_before    = memory_get_peak_usage( true );
		$start          = microtime( true );

		$rows = $callback();

		return array(
			'workload'     => $workload,
			'rows'         => $rows,
			'queries'      => ( isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : 0 ) - $queries_before,
			'memory_bytes' => max( 0, memory_get_usage( true ) - $memory_before ),
			'peak_bytes'   => max( 0, memory_get_peak_usage( true ) - $peak_before ),
			'time_ms'      => (int) round( ( microtime( true ) - $start ) * 1000 ),
		);
	}

	private static function benchmark_strings( string $language, int $limit ): int {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.domain, s.context, s.source, COALESCE(st.translation, '') AS translation
				 FROM {$wpdb->prefix}cml_strings s
				 LEFT JOIN {$wpdb->prefix}cml_string_translations st
				   ON st.string_id = s.id AND st.language = %s
				 ORDER BY s.id DESC
				 LIMIT %d",
				$language,
				$limit
			)
		);

		$hashes = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$hashes[] = StringTranslator::hash( (string) $row->domain, (string) $row->context, (string) $row->source );
			}
		}

		return count( $hashes );
	}

	private static function benchmark_post_type( string $post_type, int $limit ): int {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID
				 FROM {$wpdb->posts}
				 WHERE post_type = %s
				   AND post_status NOT IN ('trash', 'auto-draft')
				 ORDER BY ID DESC
				 LIMIT %d",
				$post_type,
				$limit
			)
		);

		if ( ! is_array( $ids ) || array() === $ids ) {
			return 0;
		}

		$post_ids = array_map( 'intval', $ids );
		TranslationGroups::preload( $post_ids );

		$groups = array();
		foreach ( $post_ids as $post_id ) {
			$group_id = TranslationGroups::get_group_id( $post_id );
			if ( null !== $group_id ) {
				$groups[ $group_id ] = true;
			}
		}

		foreach ( array_slice( array_keys( $groups ), 0, 25 ) as $group_id ) {
			TranslationGroups::get_siblings( (int) $group_id );
		}

		return count( $post_ids );
	}

	private static function seed_strings( int $count, string $language ): int {
		global $wpdb;

		$inserted = 0;
		$now      = time();
		for ( $i = 1; $i <= $count; ++$i ) {
			$source = "CML benchmark string {$i}";
			$hash   = StringTranslator::hash( 'cml-benchmark', '', $source );

			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->prefix}cml_strings (hash, domain, context, source, source_language, created_at)
					 VALUES (UNHEX(%s), %s, %s, %s, %s, %d)",
					$hash,
					'cml-benchmark',
					'',
					$source,
					Languages::default_code(),
					$now
				)
			);

			$string_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}cml_strings WHERE hash = UNHEX(%s) LIMIT 1",
					$hash
				)
			);
			if ( $string_id <= 0 ) {
				continue;
			}

			$wpdb->query(
				$wpdb->prepare(
					"REPLACE INTO {$wpdb->prefix}cml_string_translations (string_id, language, translation, updated_at)
					 VALUES (%d, %s, %s, %d)",
					$string_id,
					$language,
					"Translated benchmark string {$i}",
					$now
				)
			);
			++$inserted;
		}

		return $inserted;
	}

	/**
	 * @return array{products:int,variations:int}
	 */
	private static function seed_products( int $products, int $variations, string $language ): array {
		$default_language = Languages::default_code();
		$product_count    = 0;
		$variation_count  = 0;

		for ( $i = 1; $i <= $products; ++$i ) {
			$source_product = self::insert_benchmark_post( 'product', "CML Benchmark Product {$i}", 0 );
			if ( $source_product <= 0 ) {
				continue;
			}

			self::upsert_post_language( $source_product, $source_product, $default_language );
			++$product_count;

			$target_product = 0;
			if ( $language !== $default_language ) {
				$target_product = self::insert_benchmark_post( 'product', "CML Benchmark Product {$i} ({$language})", 0 );
				if ( $target_product > 0 ) {
					self::upsert_post_language( $target_product, $source_product, $language );
					++$product_count;
				}
			}

			for ( $v = 1; $v <= $variations; ++$v ) {
				$source_variation = self::insert_benchmark_post( 'product_variation', "CML Benchmark Product {$i} Variation {$v}", $source_product );
				if ( $source_variation <= 0 ) {
					continue;
				}
				self::upsert_post_language( $source_variation, $source_variation, $default_language );
				++$variation_count;

				if ( $target_product > 0 ) {
					$target_variation = self::insert_benchmark_post( 'product_variation', "CML Benchmark Product {$i} Variation {$v} ({$language})", $target_product );
					if ( $target_variation > 0 ) {
						self::upsert_post_language( $target_variation, $source_variation, $language );
						++$variation_count;
					}
				}
			}
		}

		return array(
			'products'   => $product_count,
			'variations' => $variation_count,
		);
	}

	private static function insert_benchmark_post( string $post_type, string $title, int $parent ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'    => $post_type,
				'post_status'  => 'publish',
				'post_parent'  => $parent,
				'post_title'   => $title,
				'post_content' => 'CodeOn Multilingual benchmark fixture.',
				'post_excerpt' => 'Benchmark fixture.',
				'meta_input'   => array(
					'_cml_benchmark' => '1',
				),
			),
			true
		);

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $post_id ) ) {
			WP_CLI::warning( $post_id->get_error_message() );
			return 0;
		}

		return (int) $post_id;
	}

	private static function upsert_post_language( int $post_id, int $group_id, string $language ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"REPLACE INTO {$wpdb->prefix}cml_post_language (post_id, group_id, language) VALUES (%d, %d, %s)",
				$post_id,
				$group_id,
				$language
			)
		);
	}

	/**
	 * @param array<string,string> $assoc_args
	 */
	private static function benchmark_language( array $assoc_args ): string {
		$requested = (string) ( $assoc_args['language'] ?? '' );
		if ( '' !== $requested ) {
			if ( ! Languages::exists( $requested ) ) {
				WP_CLI::error( "Unknown language '{$requested}'." );
			}
			return $requested;
		}

		$default = Languages::default_code();
		foreach ( Languages::active_codes() as $code ) {
			if ( $code !== $default ) {
				return $code;
			}
		}
		return $default;
	}

	private static function format_mb( int $bytes ): string {
		return number_format( $bytes / 1048576, 2, '.', '' );
	}
}
