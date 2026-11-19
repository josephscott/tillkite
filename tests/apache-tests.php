<?php
declare( strict_types = 1 );

// The php-fpm tests' site under Apache with mod_php, as many hosts run it,
// behind the README's .htaccess. Tillkite reads the request with getenv(),
// which mod_php answers from Apache's own table, not from FastCGI's, as
// php-fpm does. make apache runs these in Docker, and without
// TILLKITE_APACHE=1, which it sets, they're skipped.
$apache = getenv( 'TILLKITE_APACHE' ) === '1';

describe( 'under Apache with mod_php', function() use ( $apache ) {
	// The site's .htaccess has to be the README's, so what's tested is what
	// the README tells people to use.
	it( "reads the request without building \$_SERVER, through the README's .htaccess", function() {
		preg_match( '/```apache\n(.*?)```/s', (string) file_get_contents( dirname( __DIR__ ) . '/README.md' ), $readme );

		expect( file_get_contents( __DIR__ . '/fixtures/fpm/public/.htaccess' ) )->toBe( $readme[1] ?? '' );
		expect( json_decode( http( apache(), 'GET', '/users/5?a=1' )['body'], true ) )->toBe( [ 'method' => 'GET', 'uri' => '/users/5?a=1', 'params' => [ 'id' => '5' ], 'server' => false ] );
		expect( json_decode( http( apache(), 'DELETE', '/anything' )['body'], true ) )->toBe( [ 'method' => 'DELETE', 'uri' => '/anything', 'params' => [], 'server' => false ] );
	} )->skip( ! $apache, 'needs Apache with mod_php, which make apache runs in Docker' );

	it( 'answers a miss with a 404, a 405 or a 308', function() {
		$missing = http( apache(), 'GET', '/nope' );
		$method = http( apache(), 'POST', '/users/5' );
		$slash = http( apache(), 'GET', '/users/5/?q=1' );

		expect( [ $missing['status'], json_decode( $missing['body'], true )['uri'] ] )->toBe( [ 404, '/nope' ] );
		expect( [ $method['status'], $method['headers']['allow'], json_decode( $method['body'], true )['method'] ] )->toBe( [ 405, 'GET, HEAD', 'POST' ] );
		expect( [ $slash['status'], $slash['headers']['location'] ] )->toBe( [ 308, '/users/5?q=1' ] );
	} )->skip( ! $apache, 'needs Apache with mod_php, which make apache runs in Docker' );

	// mod_php would run the file itself, without the before-files, as it runs
	// any PHP file in the document root.
	it( 'sends a request for another PHP file in the document root to Tillkite, not the file', function() {
		$other = http( apache(), 'GET', '/other.php?a=1' );

		expect( [ $other['status'], json_decode( $other['body'], true )['uri'] ?? $other['body'] ] )->toBe( [ 404, '/other.php?a=1' ] );
	} )->skip( ! $apache, 'needs Apache with mod_php, which make apache runs in Docker' );

	// The document root comes from getenv() too.
	it( 'describes a handlers directory inside the document root on the 500 page', function() {
		$unsafe = http( apache(), 'GET', '/users/5', [ 'X-Handlers' => __DIR__ . '/fixtures/fpm/public' ] );

		expect( [ $unsafe['status'], $unsafe['body'] ] )->toBe( [ 500, "500 Internal Server Error\n\nThis site isn't set up safely, so Tillkite won't run it:\n\n"
			. "- The handlers directory is inside the document root, where a request can get or run its files directly, without the before-files. Move it outside the document root, and point the handlers option at it.\n\n"
			. "The error log has the details.\n" ] );
	} )->skip( ! $apache, 'needs Apache with mod_php, which make apache runs in Docker' );
} );
