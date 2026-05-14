<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Strings;

/**
 * Render an in-memory string catalog into PO or JSON for CLI export.
 *
 * Pure transform — takes plain arrays, returns a string. No DB, no globals.
 * Tests instantiate the input directly and assert the rendered output.
 *
 * Each entry shape:
 *   array{domain:string, source:string, context:string, translation:string}
 *
 * The PO output includes a non-standard `# Domain: <name>` comment per entry
 * so multi-domain exports survive a round-trip through PoImporter without
 * needing one file per domain. Standard PO tools ignore the comment.
 */
final class PoExporter {

	/**
	 * @param array<int, array{domain:string, source:string, context:string, translation:string}> $entries
	 */
	public static function to_po( array $entries, string $language ): string {
		$out = self::po_header( $language );

		foreach ( $entries as $entry ) {
			$domain      = (string) ( $entry['domain'] ?? '' );
			$source      = (string) ( $entry['source'] ?? '' );
			$context     = (string) ( $entry['context'] ?? '' );
			$translation = (string) ( $entry['translation'] ?? '' );

			if ( '' === $source ) {
				continue;
			}

			$out .= "\n";
			if ( '' !== $domain ) {
				$out .= '# Domain: ' . $domain . "\n";
			}
			if ( '' !== $context ) {
				$out .= 'msgctxt ' . self::quote( $context ) . "\n";
			}
			$out .= 'msgid ' . self::quote( $source ) . "\n";
			$out .= 'msgstr ' . self::quote( $translation ) . "\n";
		}

		return $out;
	}

	/**
	 * @param array<int, array{domain:string, source:string, context:string, translation:string}> $entries
	 */
	public static function to_json( array $entries, string $language ): string {
		$grouped = array();
		foreach ( $entries as $entry ) {
			$domain  = (string) ( $entry['domain'] ?? '' );
			$source  = (string) ( $entry['source'] ?? '' );
			$context = (string) ( $entry['context'] ?? '' );
			if ( '' === $source ) {
				continue;
			}
			$grouped[ $domain ][] = array(
				'source'      => $source,
				'context'     => $context,
				'translation' => (string) ( $entry['translation'] ?? '' ),
			);
		}

		return (string) wp_json_encode(
			array(
				'language' => $language,
				'domains'  => $grouped,
			),
			JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
		);
	}

	public static function quote( string $s ): string {
		$escaped = str_replace(
			array( '\\', '"', "\n", "\t", "\r" ),
			array( '\\\\', '\\"', '\\n', '\\t', '\\r' ),
			$s
		);
		return '"' . $escaped . '"';
	}

	private static function po_header( string $language ): string {
		return 'msgid ""' . "\n"
			. 'msgstr ""' . "\n"
			. self::quote( 'Content-Type: text/plain; charset=UTF-8\n' ) . "\n"
			. self::quote( 'Language: ' . $language . '\n' ) . "\n"
			. self::quote( 'X-Generator: codeon-multilingual\n' ) . "\n";
	}
}
