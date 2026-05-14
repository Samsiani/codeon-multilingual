<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Cli\LanguageCommand;
use Samsiani\CodeonMultilingual\Cli\StringsCommand;

/**
 * Pure-helper tests for CLI command classes.
 *
 * The command runners themselves require WP_CLI runtime, but the shaping
 * helpers (row formatting, format auto-detection) are pure and tested here.
 */
final class CliHelpersTest extends TestCase {

	public function test_language_row_for_display_normalizes_booleans_to_yes_no(): void {
		$lang = (object) array(
			'code'       => 'ka',
			'locale'     => 'ka_GE',
			'name'       => 'Georgian',
			'native'     => 'ქართული',
			'flag'       => 'ge',
			'rtl'        => 0,
			'active'     => 1,
			'is_default' => 1,
			'position'   => 0,
		);

		$row = LanguageCommand::row_for_display( $lang );

		$this->assertSame( 'ka', $row['code'] );
		$this->assertSame( 'yes', $row['active'] );
		$this->assertSame( 'yes', $row['is_default'] );
		$this->assertSame( 'no', $row['rtl'] );
		$this->assertSame( '0', $row['position'] );
	}

	public function test_language_row_includes_every_expected_column(): void {
		$lang = (object) array(
			'code'       => 'en',
			'locale'     => 'en_US',
			'name'       => 'English',
			'native'     => 'English',
			'flag'       => 'us',
			'rtl'        => 0,
			'active'     => 1,
			'is_default' => 0,
			'position'   => 10,
		);

		$row = LanguageCommand::row_for_display( $lang );

		$expected = array( 'code', 'locale', 'name', 'native', 'active', 'is_default', 'rtl', 'position' );
		$this->assertSame( $expected, array_keys( $row ) );
	}

	public function test_strings_detect_format_uses_extension_first(): void {
		$this->assertSame( 'json', StringsCommand::detect_format( 'cat.json', '{"x":1}' ) );
		$this->assertSame( 'po', StringsCommand::detect_format( 'cat.po', 'msgid ""' ) );
	}

	public function test_strings_detect_format_falls_back_to_content_for_stdin(): void {
		$this->assertSame( 'json', StringsCommand::detect_format( '-', '   {"language":"ka"}' ) );
		$this->assertSame( 'po', StringsCommand::detect_format( '-', "msgid \"\"\nmsgstr \"\"\n" ) );
	}

	public function test_strings_detect_format_handles_unknown_extension(): void {
		$this->assertSame( 'json', StringsCommand::detect_format( 'cat.txt', '{"language":"en"}' ) );
		$this->assertSame( 'po', StringsCommand::detect_format( 'cat.txt', 'msgid ""' ) );
	}
}
