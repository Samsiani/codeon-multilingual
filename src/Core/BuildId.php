<?php
/**
 * Per-plugin build identifier.
 *
 * Build pipelines replace the placeholder below with a unique build ID.
 * In development, derives from the bootstrap mtime so code changes break cached bytecode keys.
 *
 * @package Samsiani\CodeonMultilingual
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'CML_BUILD_ID' ) ) {
	$cml_build_seed = defined( 'CML_FILE' ) && is_readable( CML_FILE ) ? filemtime( CML_FILE ) : 0;
	define( 'CML_BUILD_ID', 'dev-' . substr( md5( CML_VERSION . $cml_build_seed ), 0, 12 ) );
	unset( $cml_build_seed );
}
