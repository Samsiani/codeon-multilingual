<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Migration;

/**
 * Shared migration source report data for CLI and admin surfaces.
 */
final class MigrationSourceRegistry {

	/**
	 * @return array<int,array{source:string,detected:string,status:string,coverage:string,command:string,remaining:string}>
	 */
	public static function cli_rows(): array {
		return array_map(
			static function ( array $row ): array {
				return array(
					'source'    => $row['source'],
					'detected'  => $row['detected'],
					'status'    => $row['status'],
					'coverage'  => $row['coverage'],
					'command'   => $row['command'],
					'remaining' => $row['remaining'],
				);
			},
			self::report()
		);
	}

	/**
	 * @return array<int,array{source:string,detected:string,status:string,coverage:string,command:string,remaining:string,evidence:array<int,string>}>
	 */
	public static function report(): array {
		return array(
			self::row(
				'WPML',
				MigrationSourceDetector::wpml(),
				'importable',
				'languages, posts, terms, strings',
				'wp cml migrate wpml --dry-run',
				'packages, workflow metadata, multicurrency'
			),
			self::row(
				'Polylang',
				MigrationSourceDetector::polylang(),
				'importable',
				'languages, posts, terms',
				'wp cml migrate polylang --dry-run',
				'polylang_mo string adapter'
			),
			self::row(
				'TranslatePress',
				MigrationSourceDetector::translatepress(),
				'planned',
				'string-overlay model',
				'not available',
				'dictionary table adapter and content-split policy'
			),
			self::row(
				'Weglot',
				MigrationSourceDetector::weglot(),
				'planned',
				'remote/proxy model',
				'not available',
				'export/API/crawl adapter'
			),
			self::row(
				'GTranslate',
				MigrationSourceDetector::gtranslate(),
				'planned',
				'remote/proxy model',
				'not available',
				'export/API/crawl adapter'
			),
			self::row(
				'MultilingualPress',
				MigrationSourceDetector::multilingualpress(),
				'planned',
				'multisite model',
				'not available',
				'cross-site merge adapter'
			),
			self::row(
				'qTranslate-X / WPGlobus',
				MigrationSourceDetector::inline_multilingual_fields(),
				'planned',
				'inline multi-language fields',
				'not available',
				'field parser and object duplicator'
			),
		);
	}

	/**
	 * @param array{detected:string,evidence:array<int,string>} $detection
	 * @return array{source:string,detected:string,status:string,coverage:string,command:string,remaining:string,evidence:array<int,string>}
	 */
	private static function row( string $source, array $detection, string $status, string $coverage, string $command, string $remaining ): array {
		return array(
			'source'    => $source,
			'detected'  => $detection['detected'],
			'status'    => $status,
			'coverage'  => $coverage,
			'command'   => $command,
			'remaining' => $remaining,
			'evidence'  => $detection['evidence'],
		);
	}
}
