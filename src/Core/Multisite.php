<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Core;

use Samsiani\CodeonMultilingual\Query\PostsClauses;
use Samsiani\CodeonMultilingual\Query\TermsClauses;
use Samsiani\CodeonMultilingual\Strings\StringTranslator;

/**
 * Request-state isolation for multisite context switches.
 *
 * CodeOn v1 keeps multisite certification explicit and conservative, but
 * request-static caches still must not bleed across switch_to_blog() calls.
 */
final class Multisite {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'switch_blog', array( self::class, 'reset_request_state' ), 10, 0 );
	}

	public static function reset_request_state(): void {
		CurrentLanguage::reset();
		Languages::reset_request_cache();
		TranslationGroups::flush();
		PostsClauses::reset_cache();
		TermsClauses::reset_cache();
		StringTranslator::reset_request_cache();
	}
}
