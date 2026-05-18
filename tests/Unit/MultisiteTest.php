<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Multisite;

final class MultisiteTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->reset_registration();
	}

	protected function tearDown(): void {
		$this->reset_registration();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_registers_switch_blog_reset_hook(): void {
		Actions\expectAdded( 'switch_blog' )
			->once()
			->with( array( Multisite::class, 'reset_request_state' ), 10, 0 );

		Multisite::register();

		$this->assertTrue( Actions\has( 'switch_blog', array( Multisite::class, 'reset_request_state' ), 10 ) );
	}

	public function test_reset_request_state_clears_current_language(): void {
		CurrentLanguage::set( 'ka' );

		Multisite::reset_request_state();

		$property = new \ReflectionProperty( CurrentLanguage::class, 'code' );
		$this->assertNull( $property->getValue() );
	}

	private function reset_registration(): void {
		$registered = new \ReflectionProperty( Multisite::class, 'registered' );
		$registered->setValue( null, false );
	}
}
