<?php
declare( strict_types = 1 );

// The front controller php-fpm runs for make load. The X-Bench-Router header
// picks what handles the request, and nginx passes the directory with the
// route caches in BENCH_DIR.
//
// Nothing here names $_SERVER, since PHP would build it from everything
// nginx sent, on every request, about 4 µs, which a site that doesn't name
// it never pays. php-fpm's getenv() has the same values. Each row loads the
// autoloader that load.php writes, which, like a site's after composer
// install --no-dev, has the classmap and only the files its router's
// package needs, not the development packages' files, which build $_SERVER.
//
// - PHP alone: no router, the floor for every other row.
// - Tillkite App: everything a Tillkite site runs, from new App() to the
//   handler: the route cache, buffering and the handler file. With Composer's
//   autoloader, or with dist/tillkite.php and no Composer.
// - Each router by name: its route cache and one match, as in make bench's
//   per-request numbers.
// - Tillkite, dist file: the Tillkite row with dist/tillkite.php and no
//   Composer.

namespace Bench;

// A Tillkite site's index.php, with the server_timing option on when the
// request asks for it.
function run_app( string $dir ) : void {
	$tillkite = new \Tillkite\App( [
		'routes' => $dir . '/routes.php',
		'handlers' => $dir,
		'cache' => $dir . '/cache',
		'server_timing' => \getenv( 'HTTP_X_BENCH_TIMING' ) !== false,
	] );
	$tillkite->run();
}

$name = (string) \getenv( 'HTTP_X_BENCH_ROUTER' );
$dir = (string) \getenv( 'BENCH_DIR' );

// Only make load's nginx sets BENCH_DIR, which a visitor can't, so a checkout
// under another web server's document root doesn't run this for one.
if ( $dir === '' ) {
	\http_response_code( 404 );
	return;
}

if ( $name === 'PHP alone' ) {
	echo "ok\n";
	return;
}

if ( $name === 'Tillkite App, dist file' ) {
	require __DIR__ . '/../dist/tillkite.php';
	run_app( $dir );
	return;
}

// The dist file row is the Tillkite router, with dist/tillkite.php and no
// Composer, and uses the Tillkite row's cache file. Every other row has the
// autoloader.
$router_name = $name;
if ( $name === 'Tillkite, dist file' ) {
	$router_name = 'Tillkite';
	require __DIR__ . '/../dist/tillkite.php';
} else {
	require $dir . '/autoload.php';
}

if ( $name === 'Tillkite App' ) {
	run_app( $dir );
	return;
}

require __DIR__ . '/routers.php';

$routers = routers( $dir );
if ( ! isset( $routers[$router_name] ) ) {
	\http_response_code( 400 );
	echo "Unknown X-Bench-Router\n";
	return;
}

// The files Composer would load on every request for each router's package.
$files = [
	'FastRoute GroupCountBased' => [ 'nikic/fast-route/src/functions.php' ],
	'FastRoute MarkBased' => [ 'nikic/fast-route/src/functions.php' ],
	'Symfony CompiledUrlMatcher' => [ 'symfony/deprecation-contracts/function.php' ],
	'RadixRouter' => [ 'wilaak/radix-router/src/RadixRouter.php' ],
];
foreach ( $files[$router_name] ?? [] as $file ) {
	require_once __DIR__ . '/../vendor/' . $file;
}

$router = $routers[$router_name];
$path = \explode( '?', (string) \getenv( 'REQUEST_URI' ), 2 )[0];
if ( $router->match( $router->load( $dir . '/' . \md5( $router_name ) . '.php' ), (string) \getenv( 'REQUEST_METHOD' ), $path ) === -1 ) {
	\http_response_code( 404 );
	echo "404 Not Found\n";
	return;
}

echo "ok\n";
