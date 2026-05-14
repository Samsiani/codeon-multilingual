<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Strings\PoExporter;
use Samsiani\CodeonMultilingual\Strings\PoImporter;

/**
 * Round-trip tests for PO/JSON export and import.
 *
 * If export(import(data)) != data, every CLI import-after-export workflow
 * silently corrupts data. These tests assert the round-trip is lossless
 * across the entry shapes the catalog actually produces (multi-domain,
 * msgctxt, embedded quotes/newlines, untranslated entries).
 */
final class PoRoundTripTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias(
			static fn( $data, $flags = 0 ) => json_encode( $data, (int) $flags )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_po_round_trip_single_domain(): void {
		$entries = array(
			array( 'domain' => 'woocommerce', 'source' => 'Cart', 'context' => '', 'translation' => 'კალათა' ),
			array( 'domain' => 'woocommerce', 'source' => 'Checkout', 'context' => '', 'translation' => 'შეკვეთა' ),
		);
		$po      = PoExporter::to_po( $entries, 'ka' );
		$parsed  = PoImporter::from_po( $po );

		$this->assertSame( 'ka', $parsed['language'] );
		$this->assertSame( $entries, $parsed['entries'] );
	}

	public function test_po_round_trip_preserves_msgctxt(): void {
		$entries = array(
			array( 'domain' => 'default', 'source' => 'Post', 'context' => 'noun', 'translation' => 'პოსტი' ),
			array( 'domain' => 'default', 'source' => 'Post', 'context' => 'verb', 'translation' => 'გაგზავნა' ),
		);
		$po      = PoExporter::to_po( $entries, 'ka' );
		$parsed  = PoImporter::from_po( $po );

		$this->assertCount( 2, $parsed['entries'] );
		$this->assertSame( 'noun', $parsed['entries'][0]['context'] );
		$this->assertSame( 'verb', $parsed['entries'][1]['context'] );
		$this->assertNotSame( $parsed['entries'][0]['translation'], $parsed['entries'][1]['translation'] );
	}

	public function test_po_round_trip_handles_quotes_and_newlines(): void {
		$entries = array(
			array(
				'domain'      => 'd',
				'source'      => "She said \"hi\"\nthen left",
				'context'     => '',
				'translation' => "ის თქვა \"გამარჯობა\"\nმერე წავიდა",
			),
		);
		$po      = PoExporter::to_po( $entries, 'ka' );
		$parsed  = PoImporter::from_po( $po );

		$this->assertSame( $entries, $parsed['entries'] );
	}

	public function test_po_carries_domain_via_comment(): void {
		$entries = array(
			array( 'domain' => 'woocommerce', 'source' => 'Cart', 'context' => '', 'translation' => 'კალათა' ),
			array( 'domain' => 'twentytwentyfour', 'source' => 'Search', 'context' => '', 'translation' => 'ძებნა' ),
		);
		$po      = PoExporter::to_po( $entries, 'ka' );
		$parsed  = PoImporter::from_po( $po );

		$this->assertCount( 2, $parsed['entries'] );
		$this->assertSame( 'woocommerce', $parsed['entries'][0]['domain'] );
		$this->assertSame( 'twentytwentyfour', $parsed['entries'][1]['domain'] );
	}

	public function test_json_round_trip(): void {
		$entries = array(
			array( 'domain' => 'd', 'source' => 'Hello', 'context' => '', 'translation' => 'Salām' ),
			array( 'domain' => 'd', 'source' => 'Bye', 'context' => 'farewell', 'translation' => 'Adieu' ),
		);
		$json    = PoExporter::to_json( $entries, 'fr' );
		$parsed  = PoImporter::from_json( $json );

		$this->assertSame( 'fr', $parsed['language'] );
		$this->assertSame( $entries, $parsed['entries'] );
	}

	public function test_plural_entries_are_skipped_with_warning(): void {
		$po = <<<PO
msgid ""
msgstr ""
"Language: ka\\n"

msgid "%d item"
msgid_plural "%d items"
msgstr[0] "%d ნივთი"
msgstr[1] "%d ნივთი"
PO;
		$parsed = PoImporter::from_po( $po );

		$this->assertSame( array(), $parsed['entries'] );
		$this->assertNotEmpty( $parsed['warnings'] );
	}

	public function test_po_picks_up_language_from_header_when_not_provided(): void {
		$po = "msgid \"\"\nmsgstr \"\"\n\"Language: pt-br\\n\"\n\nmsgid \"Hi\"\nmsgstr \"Oi\"\n";

		$parsed = PoImporter::from_po( $po );

		$this->assertSame( 'pt-br', $parsed['language'] );
		$this->assertCount( 1, $parsed['entries'] );
		$this->assertSame( 'Oi', $parsed['entries'][0]['translation'] );
	}

	public function test_untranslated_entries_kept_with_empty_msgstr(): void {
		$entries = array(
			array( 'domain' => 'd', 'source' => 'Untranslated', 'context' => '', 'translation' => '' ),
		);
		$po      = PoExporter::to_po( $entries, 'ka' );
		$parsed  = PoImporter::from_po( $po );

		$this->assertSame( $entries, $parsed['entries'] );
	}
}
