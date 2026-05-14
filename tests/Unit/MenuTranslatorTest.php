<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Content\MenuTranslator;

/**
 * Pure-helper tests for MenuTranslator.
 *
 * The cloning path and DB writes need wp-phpunit (planned for v0.9.0), but
 * the sibling-or-fallback decision (`pick_sibling_or_fallback`) is the
 * function that decides "does this menu item get rewired to the translation,
 * or stay pointed at the source?". Pure data in, integer out — lockable.
 */
final class MenuTranslatorTest extends TestCase {

	public function test_returns_sibling_id_when_target_language_exists(): void {
		$this->assertSame(
			11,
			MenuTranslator::pick_sibling_or_fallback(
				array( 10 => 'ka', 11 => 'ru', 12 => 'en' ),
				'ru',
				10
			)
		);
	}

	public function test_returns_source_when_target_language_missing(): void {
		$this->assertSame(
			20,
			MenuTranslator::pick_sibling_or_fallback(
				array( 20 => 'ka' ),
				'ru',
				20
			)
		);
	}

	public function test_returns_source_when_siblings_map_is_empty(): void {
		$this->assertSame(
			30,
			MenuTranslator::pick_sibling_or_fallback(
				array(),
				'ru',
				30
			)
		);
	}

	public function test_target_language_matching_source_returns_source(): void {
		// Edge case: the "current" item in the siblings map IS the source,
		// so the picked id equals the fallback — but the contract holds.
		$this->assertSame(
			40,
			MenuTranslator::pick_sibling_or_fallback(
				array( 40 => 'ka', 41 => 'ru' ),
				'ka',
				40
			)
		);
	}

	public function test_string_keys_from_wpdb_are_coerced_to_int(): void {
		// $wpdb->get_results sometimes returns numeric keys as strings;
		// the helper must still produce an int.
		$result = MenuTranslator::pick_sibling_or_fallback(
			array( '15' => 'ka', '16' => 'ru' ),
			'ru',
			15
		);
		$this->assertSame( 16, $result );
	}

	public function test_zero_or_negative_keys_fall_back_to_source(): void {
		// Defensive: a malformed siblings map shouldn't return an invalid id.
		$this->assertSame(
			55,
			MenuTranslator::pick_sibling_or_fallback(
				array( 0 => 'ru' ),
				'ru',
				55
			)
		);
	}

	public function test_custom_item_object_id_passes_through_translate_object_id(): void {
		// Direct, no-mock check: 'custom' type never consults TranslationGroups
		// (the `$type` branch short-circuits in translate_object_id).
		$this->assertSame(
			123,
			MenuTranslator::translate_object_id( 'custom', 'custom', 123, 'ru' )
		);
		$this->assertSame(
			0,
			MenuTranslator::translate_object_id( 'custom', 'custom', 0, 'ru' )
		);
	}

	public function test_unknown_type_passes_through_translate_object_id(): void {
		$this->assertSame(
			42,
			MenuTranslator::translate_object_id( 'block', 'whatever', 42, 'ru' )
		);
	}
}
