<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Migration;

use RuntimeException;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use Samsiani\CodeonMultilingual\Strings\StringTranslator;
use Throwable;

/**
 * Portable rollback snapshot for CodeOn tables touched by the WPML migration.
 *
 * The artifact intentionally contains only CodeOn's migration-related tables.
 * WPML's icl_* source tables are never modified by the importer, so rollback is
 * a restore of our side of the migration boundary.
 */
final class WpmlMigrationSnapshot {

	private const FORMAT         = 'codeon-multilingual.wpml-migration-snapshot';
	private const FORMAT_VERSION = 1;

	/**
	 * @var array<string,array{suffix:string,columns:array<int,string>,int_columns:array<int,string>,hex_columns:array<int,string>,order_by:string}>
	 */
	private const TABLES = array(
		'languages'           => array(
			'suffix'      => 'cml_languages',
			'columns'     => array( 'code', 'locale', 'name', 'native', 'flag', 'rtl', 'active', 'is_default', 'position' ),
			'int_columns' => array( 'rtl', 'active', 'is_default', 'position' ),
			'hex_columns' => array(),
			'order_by'    => 'code ASC',
		),
		'post_language'       => array(
			'suffix'      => 'cml_post_language',
			'columns'     => array( 'post_id', 'group_id', 'language' ),
			'int_columns' => array( 'post_id', 'group_id' ),
			'hex_columns' => array(),
			'order_by'    => 'post_id ASC',
		),
		'term_language'       => array(
			'suffix'      => 'cml_term_language',
			'columns'     => array( 'term_id', 'group_id', 'language' ),
			'int_columns' => array( 'term_id', 'group_id' ),
			'hex_columns' => array(),
			'order_by'    => 'term_id ASC',
		),
		'strings'             => array(
			'suffix'      => 'cml_strings',
			'columns'     => array( 'id', 'hash', 'domain', 'context', 'source', 'source_language', 'created_at' ),
			'int_columns' => array( 'id', 'created_at' ),
			'hex_columns' => array( 'hash' ),
			'order_by'    => 'id ASC',
		),
		'string_translations' => array(
			'suffix'      => 'cml_string_translations',
			'columns'     => array( 'string_id', 'language', 'translation', 'updated_at' ),
			'int_columns' => array( 'string_id', 'updated_at' ),
			'hex_columns' => array(),
			'order_by'    => 'string_id ASC, language ASC',
		),
	);

	/**
	 * @var array<int,string>
	 */
	private const DELETE_ORDER = array(
		'string_translations',
		'strings',
		'term_language',
		'post_language',
		'languages',
	);

	/**
	 * @var array<int,string>
	 */
	private const RESTORE_ORDER = array(
		'languages',
		'post_language',
		'term_language',
		'strings',
		'string_translations',
	);

	/**
	 * @return array<string,mixed>
	 */
	public static function create( string $migration_source = 'manual' ): array {
		global $wpdb;

		$tables = array();
		foreach ( self::TABLES as $key => $def ) {
			$table = $wpdb->prefix . $def['suffix'];
			$rows  = self::table_exists( $table )
				? self::fetch_rows( $table, $def )
				: array();

			$tables[ $key ] = array(
				'table'       => $table,
				'suffix'      => $def['suffix'],
				'columns'     => $def['columns'],
				'hex_columns' => $def['hex_columns'],
				'count'       => count( $rows ),
				'rows'        => $rows,
			);
		}

		return array(
			'format'         => self::FORMAT,
			'format_version' => self::FORMAT_VERSION,
			'plugin_version' => defined( 'CML_VERSION' ) ? CML_VERSION : '',
			'migration_source' => self::normalize_source( $migration_source ),
			'generated_at'   => gmdate( 'c' ),
			'site_url'       => self::current_site_url(),
			'table_prefix'   => (string) $wpdb->prefix,
			'report'         => self::report_from_tables( $tables ),
			'preflight'      => array(
				'wpml_available' => WpmlImporter::is_available(),
				'conflicts'      => self::safe_conflicts(),
			),
			'tables'         => $tables,
		);
	}

	public static function to_json( string $migration_source = 'manual' ): string {
		$json = json_encode( self::create( $migration_source ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( ! is_string( $json ) ) {
			throw new RuntimeException( 'Could not encode migration snapshot JSON.' );
		}

		return $json . "\n";
	}

	/**
	 * Lightweight current-state report for UI/CLI previews.
	 *
	 * @return array{tables:array<string,int>,total_rows:int,conflicts:array{language_settings:int,post_mappings:int,term_mappings:int,string_translations:int}}
	 */
	public static function report(): array {
		global $wpdb;

		$counts = array();
		$total  = 0;
		foreach ( self::TABLES as $key => $def ) {
			$table          = $wpdb->prefix . $def['suffix'];
			$counts[ $key ] = self::table_exists( $table )
				? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" )
				: 0;
			$total         += $counts[ $key ];
		}

		return array(
			'tables'     => $counts,
			'total_rows' => $total,
			'conflicts'  => self::safe_conflicts(),
		);
	}

	/**
	 * @return array{valid:bool,errors:array<int,string>,snapshot:?array<string,mixed>,report:array<string,mixed>}
	 */
	public static function validate_json( string $json ): array {
		$parsed = self::parse_json( $json );

		return array(
			'valid'    => empty( $parsed['errors'] ),
			'errors'   => $parsed['errors'],
			'snapshot' => $parsed['snapshot'],
			'report'   => null === $parsed['snapshot'] ? array() : self::report_from_snapshot( $parsed['snapshot'] ),
		);
	}

	/**
	 * Restore CodeOn migration tables from an exported snapshot.
	 *
	 * Options:
	 * - confirm_destructive: required for writes; dry_run ignores it.
	 * - dry_run: validate/report only; never writes.
	 * - allow_site_mismatch: permit restoring an artifact from another site URL.
	 * - allow_prefix_mismatch: permit restoring an artifact from another table prefix.
	 *
	 * @param array<string,mixed> $options
	 * @return array{restored:bool,dry_run:bool,counts:array<string,int>,errors:array<int,string>,warnings:array<int,string>}
	 */
	public static function restore_from_json( string $json, array $options = array() ): array {
		global $wpdb;

		$dry_run = ! empty( $options['dry_run'] );
		$parsed  = self::parse_json( $json );
		$counts  = null === $parsed['snapshot'] ? array() : self::counts_from_snapshot( $parsed['snapshot'] );
		$result  = array(
			'restored' => false,
			'dry_run'  => $dry_run,
			'counts'   => $counts,
			'errors'   => $parsed['errors'],
			'warnings' => array(),
		);

		if ( null === $parsed['snapshot'] || ! empty( $result['errors'] ) ) {
			return $result;
		}

		$snapshot = $parsed['snapshot'];
		self::append_environment_guards( $snapshot, $options, $result['errors'], $result['warnings'] );

		if ( $dry_run ) {
			return $result;
		}

		if ( empty( $options['confirm_destructive'] ) ) {
			$result['errors'][] = 'Restore refused: confirm_destructive is required because current CodeOn migration tables will be replaced.';
		}

		if ( ! empty( $result['errors'] ) ) {
			return $result;
		}

		self::begin_transaction();
		try {
			foreach ( self::DELETE_ORDER as $key ) {
				self::query_or_throw( 'DELETE FROM ' . $wpdb->prefix . self::TABLES[ $key ]['suffix'], 'delete ' . $key );
			}

			foreach ( self::RESTORE_ORDER as $key ) {
				$table_rows = $snapshot['tables'][ $key ]['rows'];
				foreach ( $table_rows as $row ) {
					self::insert_row( $key, $row );
				}
			}

			self::commit_transaction();
		} catch ( Throwable $e ) {
			self::rollback_transaction();
			$result['errors'][] = $e->getMessage();
			return $result;
		}

		self::flush_runtime_caches();
		$result['restored'] = true;

		return $result;
	}

	/**
	 * @param array<string,mixed> $snapshot
	 * @return array<string,mixed>
	 */
	public static function report_from_snapshot( array $snapshot ): array {
		$counts = self::counts_from_snapshot( $snapshot );

		return array(
			'generated_at' => (string) ( $snapshot['generated_at'] ?? '' ),
			'site_url'     => (string) ( $snapshot['site_url'] ?? '' ),
			'table_prefix' => (string) ( $snapshot['table_prefix'] ?? '' ),
			'tables'       => $counts,
			'total_rows'   => array_sum( $counts ),
		);
	}

	/**
	 * @param array<string,mixed> $snapshot
	 * @return array<string,int>
	 */
	private static function counts_from_snapshot( array $snapshot ): array {
		$counts = array();
		foreach ( self::TABLES as $key => $_def ) {
			$rows           = $snapshot['tables'][ $key ]['rows'] ?? array();
			$counts[ $key ] = is_array( $rows ) ? count( $rows ) : 0;
		}
		return $counts;
	}

	/**
	 * @param array<string,array<string,mixed>> $tables
	 * @return array{tables:array<string,int>,total_rows:int}
	 */
	private static function report_from_tables( array $tables ): array {
		$counts = array();
		foreach ( self::TABLES as $key => $_def ) {
			$counts[ $key ] = isset( $tables[ $key ]['rows'] ) && is_array( $tables[ $key ]['rows'] )
				? count( $tables[ $key ]['rows'] )
				: 0;
		}

		return array(
			'tables'     => $counts,
			'total_rows' => array_sum( $counts ),
		);
	}

	/**
	 * @param array{suffix:string,columns:array<int,string>,int_columns:array<int,string>,hex_columns:array<int,string>,order_by:string} $def
	 * @return array<int,array<string,int|string>>
	 */
	private static function fetch_rows( string $table, array $def ): array {
		global $wpdb;

		$select = array();
		foreach ( $def['columns'] as $column ) {
			$select[] = in_array( $column, $def['hex_columns'], true )
				? 'LOWER(HEX(' . $column . ')) AS ' . $column
				: $column;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Columns, table, and order clauses come from the fixed TABLES map.
		$rows = $wpdb->get_results( 'SELECT ' . implode( ', ', $select ) . " FROM {$table} ORDER BY " . $def['order_by'] );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$row_array = is_array( $row ) ? $row : get_object_vars( $row );
			$out[]     = self::normalise_export_row( $row_array, $def );
		}

		return $out;
	}

	/**
	 * @param array<string,mixed>                                            $row
	 * @param array{columns:array<int,string>,int_columns:array<int,string>} $def
	 * @return array<string,int|string>
	 */
	private static function normalise_export_row( array $row, array $def ): array {
		$out = array();
		foreach ( $def['columns'] as $column ) {
			$value          = $row[ $column ] ?? '';
			$out[ $column ] = in_array( $column, $def['int_columns'], true )
				? (int) $value
				: (string) $value;
		}
		return $out;
	}

	/**
	 * @return array{snapshot:?array<string,mixed>,errors:array<int,string>}
	 */
	private static function parse_json( string $json ): array {
		$errors = array();
		if ( '' === trim( $json ) ) {
			return array(
				'snapshot' => null,
				'errors'   => array( 'Snapshot JSON is empty.' ),
			);
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return array(
				'snapshot' => null,
				'errors'   => array( 'Snapshot JSON is invalid: ' . json_last_error_msg() ),
			);
		}

		if ( ( $decoded['format'] ?? '' ) !== self::FORMAT ) {
			$errors[] = 'Snapshot format is not recognized.';
		}
		if ( (int) ( $decoded['format_version'] ?? 0 ) !== self::FORMAT_VERSION ) {
			$errors[] = 'Snapshot format version is not supported.';
		}
		if ( ! isset( $decoded['tables'] ) || ! is_array( $decoded['tables'] ) ) {
			$errors[] = 'Snapshot is missing tables.';
		}

		if ( isset( $decoded['tables'] ) && is_array( $decoded['tables'] ) ) {
			foreach ( self::TABLES as $key => $def ) {
				if ( ! isset( $decoded['tables'][ $key ] ) || ! is_array( $decoded['tables'][ $key ] ) ) {
					$errors[] = "Snapshot is missing table '{$key}'.";
					continue;
				}

				$table = $decoded['tables'][ $key ];
				if ( ! isset( $table['rows'] ) || ! is_array( $table['rows'] ) ) {
					$errors[] = "Snapshot table '{$key}' is missing rows.";
					continue;
				}

				$rows = array();
				foreach ( $table['rows'] as $index => $row ) {
					if ( ! is_array( $row ) ) {
						$errors[] = "Snapshot table '{$key}' row {$index} is not an object.";
						continue;
					}
					$normalised = self::normalise_snapshot_row( $key, $row, $errors, (int) $index );
					if ( null !== $normalised ) {
						$rows[] = $normalised;
					}
				}

				if ( isset( $table['count'] ) && (int) $table['count'] !== count( $rows ) ) {
					$errors[] = "Snapshot table '{$key}' count does not match row data.";
				}

				$decoded['tables'][ $key ]['rows']  = $rows;
				$decoded['tables'][ $key ]['count'] = count( $rows );
			}
		}

		return array(
			'snapshot' => $decoded,
			'errors'   => $errors,
		);
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<int,string>   $errors
	 * @return array<string,int|string>|null
	 */
	private static function normalise_snapshot_row( string $key, array $row, array &$errors, int $index ): ?array {
		$def       = self::TABLES[ $key ];
		$columns   = $def['columns'];
		$allowed   = array_flip( $columns );
		$output    = array();
		$has_error = false;

		foreach ( array_keys( $row ) as $column ) {
			if ( ! isset( $allowed[ $column ] ) ) {
				$errors[]  = "Snapshot table '{$key}' row {$index} has unexpected column '{$column}'.";
				$has_error = true;
			}
		}

		foreach ( $columns as $column ) {
			if ( ! array_key_exists( $column, $row ) ) {
				$errors[]  = "Snapshot table '{$key}' row {$index} is missing column '{$column}'.";
				$has_error = true;
				continue;
			}

			$value = $row[ $column ];
			if ( in_array( $column, $def['int_columns'], true ) ) {
				if ( ! is_int( $value ) && ! ( is_string( $value ) && ctype_digit( $value ) ) ) {
					$errors[]  = "Snapshot table '{$key}' row {$index} column '{$column}' must be an unsigned integer.";
					$has_error = true;
					continue;
				}
				if ( (int) $value < 0 ) {
					$errors[]  = "Snapshot table '{$key}' row {$index} column '{$column}' must be an unsigned integer.";
					$has_error = true;
					continue;
				}
				$output[ $column ] = (int) $value;
				continue;
			}

			if ( in_array( $column, $def['hex_columns'], true ) ) {
				if ( ! is_string( $value ) || ! preg_match( '/^[0-9a-f]{32}$/', strtolower( $value ) ) ) {
					$errors[]  = "Snapshot table '{$key}' row {$index} column '{$column}' must be 32 lowercase hex characters.";
					$has_error = true;
					continue;
				}
				$output[ $column ] = strtolower( $value );
				continue;
			}

			if ( ! is_scalar( $value ) ) {
				$errors[]  = "Snapshot table '{$key}' row {$index} column '{$column}' must be scalar.";
				$has_error = true;
				continue;
			}
			$output[ $column ] = (string) $value;
		}

		return $has_error ? null : $output;
	}

	/**
	 * @param array<string,mixed> $snapshot
	 * @param array<string,mixed> $options
	 * @param array<int,string>   $errors
	 * @param array<int,string>   $warnings
	 */
	private static function append_environment_guards( array $snapshot, array $options, array &$errors, array &$warnings ): void {
		global $wpdb;

		$snapshot_site = (string) ( $snapshot['site_url'] ?? '' );
		$current_site  = self::current_site_url();
		if ( '' !== $snapshot_site && '' !== $current_site && $snapshot_site !== $current_site ) {
			$message = "Snapshot site URL '{$snapshot_site}' does not match current site '{$current_site}'.";
			if ( empty( $options['allow_site_mismatch'] ) ) {
				$errors[] = $message . ' Pass allow_site_mismatch only after verifying this is intentional.';
			} else {
				$warnings[] = $message;
			}
		}

		$snapshot_prefix = (string) ( $snapshot['table_prefix'] ?? '' );
		$current_prefix  = (string) $wpdb->prefix;
		if ( '' !== $snapshot_prefix && $snapshot_prefix !== $current_prefix ) {
			$message = "Snapshot table prefix '{$snapshot_prefix}' does not match current prefix '{$current_prefix}'.";
			if ( empty( $options['allow_prefix_mismatch'] ) ) {
				$errors[] = $message . ' Pass allow_prefix_mismatch only after verifying this is intentional.';
			} else {
				$warnings[] = $message;
			}
		}
	}

	/**
	 * @param array<string,int|string> $row
	 */
	private static function insert_row( string $key, array $row ): void {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLES[ $key ]['suffix'];

		switch ( $key ) {
			case 'languages':
				$sql = $wpdb->prepare(
					"INSERT INTO {$table} (code, locale, name, native, flag, rtl, active, is_default, position) VALUES (%s, %s, %s, %s, %s, %d, %d, %d, %d)",
					$row['code'],
					$row['locale'],
					$row['name'],
					$row['native'],
					$row['flag'],
					$row['rtl'],
					$row['active'],
					$row['is_default'],
					$row['position']
				);
				break;

			case 'post_language':
				$sql = $wpdb->prepare(
					"INSERT INTO {$table} (post_id, group_id, language) VALUES (%d, %d, %s)",
					$row['post_id'],
					$row['group_id'],
					$row['language']
				);
				break;

			case 'term_language':
				$sql = $wpdb->prepare(
					"INSERT INTO {$table} (term_id, group_id, language) VALUES (%d, %d, %s)",
					$row['term_id'],
					$row['group_id'],
					$row['language']
				);
				break;

			case 'strings':
				$sql = $wpdb->prepare(
					"INSERT INTO {$table} (id, hash, domain, context, source, source_language, created_at) VALUES (%d, UNHEX(%s), %s, %s, %s, %s, %d)",
					$row['id'],
					$row['hash'],
					$row['domain'],
					$row['context'],
					$row['source'],
					$row['source_language'],
					$row['created_at']
				);
				break;

			case 'string_translations':
				$sql = $wpdb->prepare(
					"INSERT INTO {$table} (string_id, language, translation, updated_at) VALUES (%d, %s, %s, %d)",
					$row['string_id'],
					$row['language'],
					$row['translation'],
					$row['updated_at']
				);
				break;

			default:
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered directly; callers escape admin output.
				throw new RuntimeException( "Unknown migration snapshot table '{$key}'." );
		}

		self::query_or_throw( $sql, 'restore ' . $key );
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);
	}

	private static function current_site_url(): string {
		if ( function_exists( 'get_site_url' ) ) {
			return (string) get_site_url();
		}
		if ( function_exists( 'home_url' ) ) {
			return (string) home_url();
		}
		return '';
	}

	private static function normalize_source( string $source ): string {
		$source = strtolower( trim( $source ) );
		$source = (string) preg_replace( '/[^a-z0-9_-]/', '', $source );
		return '' !== $source ? $source : 'manual';
	}

	/**
	 * @return array{language_settings:int,post_mappings:int,term_mappings:int,string_translations:int}
	 */
	private static function safe_conflicts(): array {
		try {
			return WpmlImporter::conflict_summary();
		} catch ( Throwable $_e ) {
			return array(
				'language_settings'   => 0,
				'post_mappings'       => 0,
				'term_mappings'       => 0,
				'string_translations' => 0,
			);
		}
	}

	private static function begin_transaction(): void {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
	}

	private static function commit_transaction(): void {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
	}

	private static function rollback_transaction(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
	}

	private static function query_or_throw( string $sql, string $label ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared by the caller before reaching this guard.
		$result = $wpdb->query( $sql );
		if ( false === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered directly; callers escape admin output.
			throw new RuntimeException( $label . ': ' . ( $wpdb->last_error ?: 'database query failed' ) );
		}
	}

	private static function flush_runtime_caches(): void {
		Languages::flush_cache();
		StringTranslator::flush_cache();
		TranslationGroups::flush();
	}
}
