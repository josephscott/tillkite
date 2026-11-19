<?php
declare( strict_types = 1 );

// Runs before every handler, except on routes that turn it off, and before
// the 404 and 405 pages when no route matches. The handler shares its scope,
// so it gets $visitor.
header( 'X-Content-Type-Options: nosniff' );
$visitor = 'guest';
if ( isset( $_COOKIE['name'] ) && is_string( $_COOKIE['name'] ) ) {
	$visitor = $_COOKIE['name'];
}
