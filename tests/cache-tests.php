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

it( 'uses another file when the routes file or a setting changes', function() {
	[ $var, $routes ] = cache_setup();
	( new Cache( $var, $routes, 'a' ) )->write( [] );

	expect( ( new Cache( $var, $routes, 'a' ) )->read() )->toBe( [] );
	expect( ( new Cache( $var, $routes, 'b' ) )->read() )->toBeNull();
	touch( $routes, time() - 30 );
	expect( ( new Cache( $var, $routes, 'a' ) )->read() )->toBeNull();
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
