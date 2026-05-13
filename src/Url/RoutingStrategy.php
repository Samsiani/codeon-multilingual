<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Url;

/**
 * Strategy abstraction for parsing and emitting language-tagged URLs.
 *
 * Implementations: SubdirectoryStrategy (v0.1), QueryParamStrategy (v0.2),
 * SubdomainStrategy + DomainStrategy (v0.3+). Router consumes only this interface.
 */
interface RoutingStrategy {

	/**
	 * Detect the language requested by the current HTTP request.
	 * Returns null when no explicit language is encoded (default-language root, etc.).
	 */
	public function detect(): ?string;

	/**
	 * Mutate $_SERVER['REQUEST_URI'] so WordPress routes the remaining path as if
	 * the language marker were never present. Idempotent — safe to call once.
	 */
	public function strip_from_request(): void;

	/**
	 * Inject the language marker into an outbound URL. Returns the URL unchanged
	 * when the language is the default.
	 */
	public function build_url( string $url, string $lang ): string;

	/**
	 * Remove any active-language prefix from a URL. Untouched prefixes (anything
	 * that isn't a registered active language) stay where they are.
	 */
	public function strip_lang_prefix( string $url ): string;
}
