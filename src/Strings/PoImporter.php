<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Strings;

/**
 * Parse PO or JSON catalog text into a normalized entry list.
 *
 * Pure parser — string in, array out. CLI commands handle DB writes.
 * Returns the same entry shape PoExporter consumes so a round-trip is
 * lossless (round-tripping is part of the test suite).
 *
 * The PO parser supports:
 *   - msgid / msgstr pairs (single-line and multi-line continuation)
 *   - msgctxt for translation context
 *   - Our `# Domain: <name>` comment convention
 *
 * Out of scope: plural forms (msgid_plural / msgstr[n]). Our string table
 * doesn't model plurals — WordPress core handles those natively via .mo
 * loading. Plural entries are skipped with a non-fatal warning collected
 * in the result array.
 */
final class PoImporter {

	/**
	 * @return array{entries:array<int,array{domain:string,source:string,context:string,translation:string}>, language:string, warnings:array<int,string>}
	 */
	public static function from_po( string $content, string $default_language = '' ): array {
		$language = $default_language;
		$entries  = array();
		$warnings = array();

		$current        = self::empty_entry();
		$state          = 'idle';   // idle | msgctxt | msgid | msgstr
		$pending_domain = '';
		$skip_entry     = false;

		$lines = preg_split( '/\r\n|\n|\r/', $content );
		if ( false === $lines ) {
			$lines = array();
		}

		foreach ( $lines as $raw_line ) {
			$trimmed = trim( $raw_line );
			if ( '' === $trimmed ) {
				continue;
			}

			if ( str_starts_with( $trimmed, '# Domain:' ) ) {
				$pending_domain = trim( substr( $trimmed, strlen( '# Domain:' ) ) );
				continue;
			}
			if ( str_starts_with( $trimmed, '#' ) ) {
				continue;
			}

			if ( str_starts_with( $trimmed, 'msgctxt ' ) ) {
				if ( 'msgstr' === $state ) {
					self::commit( $entries, $current, $skip_entry );
				}
				if ( '' !== $pending_domain ) {
					$current['domain'] = $pending_domain;
					$pending_domain    = '';
				}
				$current['context'] = self::unquote( substr( $trimmed, strlen( 'msgctxt ' ) ) );
				$state              = 'msgctxt';
				continue;
			}
			if ( str_starts_with( $trimmed, 'msgid_plural' ) ) {
				$warnings[] = 'Skipping plural entry near msgid: ' . $current['source'];
				$skip_entry = true;
				continue;
			}
			if ( str_starts_with( $trimmed, 'msgid ' ) ) {
				if ( 'msgstr' === $state || ( 'idle' === $state && '' !== $current['source'] ) ) {
					self::commit( $entries, $current, $skip_entry );
				}
				if ( '' !== $pending_domain ) {
					$current['domain'] = $pending_domain;
					$pending_domain    = '';
				}
				$current['source'] = self::unquote( substr( $trimmed, strlen( 'msgid ' ) ) );
				$state             = 'msgid';
				continue;
			}
			if ( str_starts_with( $trimmed, 'msgstr ' ) ) {
				$current['translation'] = self::unquote( substr( $trimmed, strlen( 'msgstr ' ) ) );
				$state                  = 'msgstr';
				continue;
			}
			if ( str_starts_with( $trimmed, 'msgstr[' ) ) {
				$skip_entry = true;
				continue;
			}

			if ( str_starts_with( $trimmed, '"' ) && str_ends_with( $trimmed, '"' ) ) {
				$piece = self::unquote( $trimmed );
				if ( 'msgctxt' === $state ) {
					$current['context'] .= $piece;
				} elseif ( 'msgid' === $state ) {
					$current['source'] .= $piece;
				} elseif ( 'msgstr' === $state ) {
					if ( '' === $current['source'] ) {
						if ( '' === $language && preg_match( '/Language:\s*([a-z]{2,3}(-[a-z0-9]+)?)/i', $piece, $m ) ) {
							$language = strtolower( $m[1] );
						}
					} else {
						$current['translation'] .= $piece;
					}
				}
			}
		}

		self::commit( $entries, $current, $skip_entry );

		return array(
			'entries'  => $entries,
			'language' => $language,
			'warnings' => $warnings,
		);
	}

	/**
	 * Append the in-flight entry to $entries if it has a source and is not flagged
	 * as a skipped plural. Resets $current and $skip_entry in place.
	 *
	 * @param array<int,array{domain:string,source:string,context:string,translation:string}> $entries
	 * @param array{domain:string,source:string,context:string,translation:string}            $current
	 */
	private static function commit( array &$entries, array &$current, bool &$skip_entry ): void {
		if ( ! $skip_entry && '' !== $current['source'] ) {
			$entries[] = $current;
		}
		$current    = self::empty_entry();
		$skip_entry = false;
	}

	/**
	 * @return array{entries:array<int,array{domain:string,source:string,context:string,translation:string}>, language:string, warnings:array<int,string>}
	 */
	public static function from_json( string $content, string $default_language = '' ): array {
		$decoded = json_decode( $content, true );
		if ( ! is_array( $decoded ) ) {
			return array(
				'entries'  => array(),
				'language' => $default_language,
				'warnings' => array( 'JSON parse failed; input was not an object.' ),
			);
		}

		$language = $default_language;
		if ( isset( $decoded['language'] ) && is_string( $decoded['language'] ) ) {
			$language = $decoded['language'];
		}

		$entries = array();
		$domains = isset( $decoded['domains'] ) && is_array( $decoded['domains'] ) ? $decoded['domains'] : array();
		foreach ( $domains as $domain => $rows ) {
			if ( ! is_array( $rows ) ) {
				continue;
			}
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$entries[] = array(
					'domain'      => (string) $domain,
					'source'      => (string) ( $row['source'] ?? '' ),
					'context'     => (string) ( $row['context'] ?? '' ),
					'translation' => (string) ( $row['translation'] ?? '' ),
				);
			}
		}

		return array(
			'entries'  => $entries,
			'language' => $language,
			'warnings' => array(),
		);
	}

	public static function unquote( string $s ): string {
		$s = trim( $s );
		if ( strlen( $s ) < 2 || '"' !== $s[0] || '"' !== $s[ strlen( $s ) - 1 ] ) {
			return $s;
		}
		$inner = substr( $s, 1, -1 );
		return str_replace(
			array( '\\n', '\\t', '\\r', '\\"', '\\\\' ),
			array( "\n", "\t", "\r", '"', '\\' ),
			$inner
		);
	}

	/**
	 * @return array{domain:string,source:string,context:string,translation:string}
	 */
	private static function empty_entry(): array {
		return array(
			'domain'      => '',
			'source'      => '',
			'context'     => '',
			'translation' => '',
		);
	}
}
