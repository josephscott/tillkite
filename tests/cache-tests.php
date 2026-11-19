<?php
declare( strict_types = 1 );

use Tillkite\Cache;

// A new cache directory and routes file. Returns their paths.
function cache_setup() : array {
	$dir = temp_dir();
	mkdir( $dir . '/var' );
	write_routes( $dir . '/routes.php', '/', time() - 60 );
	$setup = [ $dir . '/var', $dir . '/routes.php' ];
	return $setup;
}

function cache_files( string $dir ) : array {
	$files = array_values( array_diff( (array) scandir( $dir ), [ '.', '..' ] ) );
	return $files;
}

it( 'reads nothing until the cache is written, then the compiled routes', function() {
	[ $var, $routes ] = cache_setup();
	$cache = new Cache( $var, $routes, '' );
	$compiled = compile_routes( [ [ 'GET', '/users/{id}', 'page.php' ] ] );

	expect( $cache->read() )->toBeNull();
	expect( $cache->write( $compiled ) )->toBe( '' );
	expect( $cache->read() )->toBe( $compiled );
} );

it( 'sets the modification time in the past, so OPcache caches the file right away', function() {
	[ $var, $routes ] = cache_setup();
	( new Cache( $var, $routes, '' ) )->write( [] );

	expect( filemtime( $var . '/' . cache_files( $var )[0] ) )->toBeLessThan( time() - 30 );
} );

it( "doesn't run a cache file in a directory that every user can write to", function() {
	[ $var, $routes ] = cache_setup();
	$cache = new Cache( $var, $routes, '' );
	$cache->write( [] );
	file_put_contents( $var . '/' . cache_files( $var )[0], "<?php return [ 'planted' ];\n" );

	expect( $cache->read() )->toBe( [ 'planted' ] );
	chmod( $var, 0o1777 );
	expect( $cache->read() )->toBeNull();
} );

// Only root can give a directory to another user, so this runs where the
// tests run as root, which make tests, make fpm and make matrix don't.
it( "doesn't run or write a cache file in a directory that belongs to another user", function() {
	[ $var, $routes ] = cache_setup();
	$cache = new Cache( $var, $routes, '' );
	$cache->write( [] );

	expect( $cache->read() )->toBe( [] );
	chown( $var, 12345 );
	clearstatcache();
	expect( $cache->read() )->toBeNull();
	expect( $cache->write( [] ) )->toBe( "The cache directory belongs to another user, who could put PHP in it for the web server to run. Make its owner the user PHP runs as, or the routes file's owner: " . $var );
} )->skip( ! function_exists( 'posix_geteuid' ) || posix_geteuid() !== 0, 'needs root, to give the directory to another user' );

it( 'uses another file when the routes file or a setting changes', function() {
	[ $var, $routes ] = cache_setup();
	( new Cache( $var, $routes, 'a' ) )->write( [] );

	expect( ( new Cache( $var, $routes, 'a' ) )->read() )->toBe( [] );
	expect( ( new Cache( $var, $routes, 'b' ) )->read() )->toBeNull();
	touch( $routes, time() - 30 );
	expect( ( new Cache( $var, $routes, 'a' ) )->read() )->toBeNull();
} );

it( 'reads nothing once a file that the routes file loaded changes or goes away, without a warning', function() {
	[ $var, $routes ] = cache_setup();
	$included = dirname( $routes ) . '/included.php';
	$other = dirname( $routes ) . '/other.php';
	file_put_contents( $included, 'a' );
	file_put_contents( $other, 'a' );
	$cache = new Cache( $var, $routes, '' );
	$cache->write( [ 'includes' => $cache->stamps( [ $included, $other ], -1 ) ] );

	expect( $cache->read() )->not->toBeNull();
	file_put_contents( $included, 'ab' );
	clearstatcache();
	expect( $cache->read() )->toBeNull();

	file_put_contents( $included, 'a' );
	clearstatcache();
	$cache->write( [ 'includes' => $cache->stamps( [ $included, $other ], -1 ) ] );
	expect( $cache->read() )->not->toBeNull();
	unlink( $other );
	clearstatcache();
	$warned = false;
	set_error_handler( function() use ( &$warned ) : bool {
		$warned = true;
		return true;
	} );
	$read = $cache->read();
	restore_error_handler();
	expect( $read )->toBeNull();
	expect( $warned )->toBeFalse();
} );

it( "doesn't give stamps for a file that changed too recently for OPcache to have noticed", function() {
	[ $var, $routes ] = cache_setup();
	$included = dirname( $routes ) . '/included.php';
	file_put_contents( $included, 'a' );
	$cache = new Cache( $var, $routes, '' );

	expect( $cache->stamps( [], 2 ) )->toBe( [] );
	expect( $cache->stamps( [ $included ], 2 ) )->toBeNull();
	expect( array_keys( $cache->stamps( [ $included ], -1 ) ) )->toBe( [ $included ] );
} );

it( 'removes the older cache files of its own routes file only', function() {
	[ $var, $routes ] = cache_setup();
	$other = dirname( $routes ) . '/other-routes.php';
	write_routes( $other, '/', time() - 60 );
	( new Cache( $var, $routes, '' ) )->write( [] );
	( new Cache( $var, $other, '' ) )->write( [] );

	touch( $routes, time() - 30 );
	$cache = new Cache( $var, $routes, '' );
	$cache->write( [ 'new' ] );

	expect( cache_files( $var ) )->toHaveCount( 2 );
	expect( $cache->read() )->toBe( [ 'new' ] );
	expect( ( new Cache( $var, $other, '' ) )->read() )->toBe( [] );
} );

// Anyone who can put a file in the directory, or change the cache file, could
// run code as the web server.
it( 'writes nothing in a directory every user can write to, and writes the file as 0644 whatever the umask', function() {
	[ $var, $routes ] = cache_setup();
	chmod( $var, 0o1777 );
	expect( ( new Cache( $var, $routes, '' ) )->write( [] ) )->toBe( $var . ' is writable by every user, who could put PHP in it for the web server to run, so it is not used. Make it writable only by the web server' );
	expect( cache_files( $var ) )->toBe( [] );

	chmod( $var, 0o755 );
	$umask = umask( 0 );
	( new Cache( $var, $routes, '' ) )->write( [] );
	umask( $umask );
	expect( fileperms( $var . '/' . cache_files( $var )[0] ) & 0o777 )->toBe( 0o644 );
} );
