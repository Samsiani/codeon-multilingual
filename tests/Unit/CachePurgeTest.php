<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Compat\CachePurge;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Strings\StringTranslator;

final class CachePurgeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->reset_cache_purge_registration();

		Functions\when( 'wp_cache_get' )->justReturn( array() );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
	}

	protected function tearDown(): void {
		Languages::flush_cache();
		StringTranslator::flush_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_registers_language_string_and_migration_purge_hooks(): void {
		Actions\expectAdded( 'cml_language_changed' )
			->once()
			->with( array( CachePurge::class, 'language_changed' ), 20, 2 );
		Actions\expectAdded( 'cml_string_catalog_changed' )
			->once()
			->with( array( CachePurge::class, 'string_catalog_changed' ), 20, 2 );
		Actions\expectAdded( 'cml_migration_imported' )
			->once()
			->with( array( CachePurge::class, 'migration_imported' ), 20, 2 );

		CachePurge::register();

		$this->assertTrue( Actions\has( 'cml_language_changed', array( CachePurge::class, 'language_changed' ), 20 ) );
	}

	public function test_language_changes_purge_full_page_caches(): void {
		Actions\expectDone( 'litespeed_purge_all' )->once();

		CachePurge::language_changed( 'updated', 'ka' );

		$this->assertSame( 1, did_action( 'litespeed_purge_all' ) );
	}

	public function test_string_catalog_changes_purge_full_page_caches(): void {
		Actions\expectDone( 'litespeed_purge_all' )->once();

		CachePurge::string_catalog_changed( 'translation_saved', array( 'language' => 'ka' ) );

		$this->assertSame( 1, did_action( 'litespeed_purge_all' ) );
	}

	public function test_successful_migrations_purge_full_page_caches(): void {
		Actions\expectDone( 'litespeed_purge_all' )->once();

		CachePurge::migration_imported( 'wpml', array( 'errors' => array() ) );

		$this->assertSame( 1, did_action( 'litespeed_purge_all' ) );
	}

	public function test_language_and_string_notifiers_emit_mutation_hooks(): void {
		Actions\expectDone( 'cml_language_changed' )
			->once()
			->with( 'default_set', 'ka' );
		Actions\expectDone( 'cml_string_catalog_changed' )
			->once()
			->with( 'import', array( 'language' => 'ka' ) );

		Languages::notify_changed( 'default_set', 'ka' );
		StringTranslator::notify_catalog_changed( 'import', array( 'language' => 'ka' ) );

		$this->assertSame( 1, did_action( 'cml_language_changed' ) );
		$this->assertSame( 1, did_action( 'cml_string_catalog_changed' ) );
	}

	private function reset_cache_purge_registration(): void {
		$registered = new \ReflectionProperty( CachePurge::class, 'registered' );
		$registered->setValue( null, false );
	}
}
