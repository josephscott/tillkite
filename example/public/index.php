<?php
declare( strict_types = 1 );

// The example site's front controller. Run it with: make serve

// Anywhere but php -S, this is production, whatever php.ini says. It comes
// first, to cover what goes wrong before run(). See Deploying in the README.
if ( PHP_SAPI !== 'cli-server' ) {
	if ( function_exists( 'ini_set' ) ) {
		ini_set( 'display_errors', '0' );
		ini_set( 'html_errors', '0' );
		ini_set( 'log_errors', '1' );
		ini_set( 'zend.exception_ignore_args', '1' );
		ini_set( 'session.cookie_httponly', '1' );
		ini_set( 'session.cookie_samesite', 'Lax' );
		ini_set( 'session.cookie_secure', '1' );
		ini_set( 'session.use_strict_mode', '1' );
	}

	header_remove( 'X-Powered-By' );
}

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../functions.php';

$tillkite = new Tillkite\App( [
	'routes' => __DIR__ . '/../routes.php',
	'handlers' => __DIR__ . '/../handlers',
	'templates' => __DIR__ . '/../templates',
	'cache' => __DIR__ . '/../var',
	'before' => 'before.php',
	'errors' => [ 404 => 'errors/404.php', 405 => 'errors/405.php', 500 => 'errors/500.php' ],
	// Only in development, because timings that every visitor can see can
	// give away information, like a login check that's slower for an
	// account that exists.
	'server_timing' => development(),

	// URLs never end in a slash, which is the default. Every route is
	// written without one, and /users/42/ redirects to /users/42. With
	// true, every route would need one, like /users/42/.
	'trailing_slash' => false,
] );

// The blog's posts, which blog.php gets with $tillkite->get( 'posts' ). A
// real site might register a database connection this way.
$tillkite->register( 'posts', [
	'/blog/2026/10/this-is-the-slug' => 'This is the slug',
	'/blog/2026/10/optional-parts' => 'Optional parts',
	'/blog/2026/03/hello-world' => 'Hello, world',
	'/blog/2025/12/a-year-in-review' => 'A year in review',
] );

return $tillkite->run();
