<?php
declare( strict_types = 1 );

// make bench: compares Tillkite with FastRoute, Symfony Routing and
// RadixRouter, each using its route cache.
//
// - Match only: one router, loaded once, matching the same request over and
//   over. This is what a long-running server pays per request.
// - Per request: include the cache file and create the router, then match.
//   This is what each php-fpm request pays. It needs OPcache on for the CLI,
//   which make bench turns on, so the cache comes from OPcache's shared memory
//   as it does under php-fpm.
//
// Every router has to agree on which route each request matches before
// anything is timed.
//
// The caches are built first, in a separate process, the way a deploy or an
// earlier request would build them. Building can leave things behind in the
// process, such as PHP's cache of compiled regexes keyed by strings that
// aren't the ones from the cache file, and that shouldn't change the timings.

namespace Bench;

use Composer\InstalledVersions;

// For the command line only, so a checkout under a web server's document
// root can't run it for a visitor.
if ( \PHP_SAPI !== 'cli' ) {
	\http_response_code( 404 );
	exit;
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/routers.php';

// Microseconds per call: the best of five timed runs, each long enough to
// take at least 5 ms.
function time_call( \Closure $call ) : float {
	$call();
	$iterations = 64;
	do {
		$iterations *= 2;
		$start = \hrtime( true );
		for ( $i = 0; $i < $iterations; $i++ ) {
			$call();
		}

		$elapsed = \hrtime( true ) - $start;
	} while ( $elapsed < 5_000_000 );

	$best = $elapsed / $iterations;
	for ( $run = 0; $run < 5; $run++ ) {
		$start = \hrtime( true );
		for ( $i = 0; $i < $iterations; $i++ ) {
			$call();
		}

		$best = \min( $best, ( \hrtime( true ) - $start ) / $iterations );
	}

	$microseconds = $best / 1000;
	return $microseconds;
}

function version( string $package ) : string {
	$version = InstalledVersions::getPrettyVersion( $package ) ?? '?';
	return $version;
}

function cache_file( string $dir, string $title, string $name ) : string {
	$file = $dir . '/' . \md5( $title . $name ) . '.php';
	return $file;
}

$opcache = \function_exists( 'opcache_get_status' ) && ( \opcache_get_status( false )['opcache_enabled'] ?? false );
if ( ! $opcache ) {
	\fwrite( \STDERR, "OPcache is off for the CLI, so the per-request numbers would include compiling\nevery cache file. Run: php -d opcache.enable_cli=1 bench/run.php\n" );
	exit( 1 );
}

// The cache files are PHP that this script runs, so their directory has to
// be this user's alone. Under a shared /tmp, another user could make it
// first, and put PHP in it, or symlinks that the cache files would write
// through.
$dir = \sys_get_temp_dir() . '/tillkite-bench';
if ( ! \is_dir( $dir ) ) {
	\mkdir( $dir, 0o700 );
}

$user = \function_exists( 'posix_geteuid' ) ? \posix_geteuid() : \getmyuid();
if ( \is_link( $dir ) || \fileowner( $dir ) !== $user || ( \fileperms( $dir ) & 0o022 ) !== 0 ) {
	\fwrite( \STDERR, $dir . " is a symlink, or another user owns it or can write to it, so it isn't used. Remove it, and run this again.\n" );
	exit( 1 );
}

\file_put_contents( $dir . '/handler.php', "<?php\n" );

$routers = routers( $dir );

// The route sets are committed in bench/routes, so every run uses the same routes.
$sets = [];
foreach ( [ 10, 100, 1000, 2500, 5000 ] as $count ) {
	$sets['Synthetic ' . \number_format( $count )] = require __DIR__ . '/routes/synthetic-' . $count . '.php';
}

$sets['GitHub API'] = require __DIR__ . '/routes/github.php';
$sets['Stripe API'] = require __DIR__ . '/routes/stripe.php';

// Tillkite's features that the other routers don't all have, timed on their
// own: each request, the status and route it must get, and a plain
// parameter to compare the others with.
$features = require __DIR__ . '/routes/features.php';
$feature_requests = [
	'a static route' => [ 'GET', '/users/new', 200, 1 ],
	'a plain parameter' => [ 'GET', '/admin/stats', 200, 6 ],
	'a constrained parameter' => [ 'GET', '/users/42', 200, 2 ],
	'a constrained parameter, failed' => [ 'GET', '/users/abc', 404, -1 ],
	'optional parts, all left out' => [ 'GET', '/blog', 200, 4 ],
	'optional parts, all there' => [ 'GET', '/blog/2026/10/this-is-the-slug', 200, 4 ],
	'a catch-all' => [ 'GET', '/docs/guide/install/macos', 200, 5 ],
	"a '*' route" => [ 'DELETE', '/webhook', 200, 8 ],
	'HEAD on a GET route' => [ 'HEAD', '/users/42', 200, 2 ],
	'two methods, the second' => [ 'POST', '/contact', 200, 3 ],
	'405, the wrong method' => [ 'DELETE', '/users/42', 405, -1 ],
	'308, a trailing slash' => [ 'GET', '/users/42/', 308, -1 ],
	'404' => [ 'GET', '/nope', 404, -1 ],
];

if ( ( $argv[1] ?? '' ) === '--build' ) {
	foreach ( $sets as $title => $routes ) {
		foreach ( $routers as $name => $router ) {
			$router->build( $routes, cache_file( $dir, $title, $name ) );
		}
	}

	$routers['Tillkite']->build( $features, cache_file( $dir, 'Features', 'Tillkite' ) );
	exit( 0 );
}

\passthru( \escapeshellarg( \PHP_BINARY ) . ' -d opcache.enable_cli=1 ' . \escapeshellarg( __FILE__ ) . ' --build', $code );
if ( $code !== 0 ) {
	exit( $code );
}

\printf(
	"PHP %s, PCRE JIT %s, OPcache JIT %s\nTillkite (this checkout), FastRoute %s, Symfony Routing %s, RadixRouter %s\n",
	\PHP_VERSION,
	\ini_get( 'pcre.jit' ) ? 'on' : 'off',
	\ini_get( 'opcache.jit' ) ?: 'off',
	version( 'nikic/fast-route' ),
	version( 'symfony/routing' ),
	version( 'wilaak/radix-router' )
);
echo "Microseconds per request, best of five runs. Lower is better.\n";

$failed = false;
foreach ( $sets as $title => $routes ) {
	$requests = requests_for( $routes );
	\printf( "\n%s (%s routes)\n%-27s %-31s %-31s %s\n", $title, \number_format( \count( $routes ) ), '', 'Match only', 'Per request', 'Cache' );
	\printf( "%-27s %7s %7s %7s %7s   %7s %7s %7s %7s\n", '', 'first', 'middle', 'last', 'miss', 'first', 'middle', 'last', 'miss' );

	foreach ( $routers as $name => $router ) {
		$file = cache_file( $dir, $title, $name );
		$loaded = $router->load( $file );

		foreach ( $requests as $label => [ [ $method, $path ], $expected ] ) {
			$got = $router->match( $loaded, $method, $path );
			if ( $got !== $expected ) {
				\fwrite( \STDERR, \sprintf( "%s: %s %s %s matched route %d, expected %d\n", $name, $label, $method, $path, $got, $expected ) );
				$failed = true;
			}
		}

		$match_only = [];
		$per_request = [];
		foreach ( $requests as [ [ $method, $path ] ] ) {
			$match_only[] = time_call( fn () => $router->match( $loaded, $method, $path ) );
			$per_request[] = time_call( fn () => $router->match( $router->load( $file ), $method, $path ) );
		}

		\vprintf(
			"%-27s %7.2f %7.2f %7.2f %7.2f   %7.2f %7.2f %7.2f %7.2f   %5d KB\n",
			[ $name, ...$match_only, ...$per_request, (int) \round( \filesize( $file ) / 1024 ) ]
		);
	}
}

$file = cache_file( $dir, 'Features', 'Tillkite' );
$matcher = $routers['Tillkite']->load( $file );
\printf( "\nTillkite's own features (%d routes, in bench/routes/features.php)\n%-33s %-8s %10s %12s\n", \count( $features ), '', 'Status', 'Match only', 'Per request' );
foreach ( $feature_requests as $label => [ $method, $path, $status, $route ] ) {
	$result = $matcher->match( $method, $path );
	if ( $result['status'] !== $status || ( $result['route'] ?? -1 ) !== $route ) {
		\fwrite( \STDERR, \sprintf( "Tillkite: %s, %s %s got %d and route %d, expected %d and route %d\n", $label, $method, $path, $result['status'], $result['route'] ?? -1, $status, $route ) );
		$failed = true;
	}

	\printf(
		"%-33s %-8d %10.2f %12.2f\n",
		$label,
		$status,
		time_call( fn () => $matcher->match( $method, $path ) ),
		time_call( fn () => $routers['Tillkite']->load( $file )->match( $method, $path ) )
	);
}

if ( $failed ) {
	exit( 1 );
}
