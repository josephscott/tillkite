<?php
declare( strict_types = 1 );

// A site under nginx and php-fpm, as in production, where Tillkite reads the
// request with getenv() and never builds $_SERVER. php -S, which the other
// tests use, has no getenv() values, and drops a request target with raw
// bytes that nginx passes on. make fpm runs these in Docker, and without
// TILLKITE_FPM=1, which it sets, they're skipped.
$fpm = getenv( 'TILLKITE_FPM' ) === '1';

describe( 'under nginx and php-fpm', function() use ( $fpm ) {
	it( 'reads the request without building $_SERVER', function() {
		expect( json_decode( http( fpm(), 'GET', '/users/5?a=1' )['body'], true ) )->toBe( [ 'method' => 'GET', 'uri' => '/users/5?a=1', 'params' => [ 'id' => '5' ], 'server' => false ] );
		expect( json_decode( http( fpm(), 'DELETE', '/anything' )['body'], true ) )->toBe( [ 'method' => 'DELETE', 'uri' => '/anything', 'params' => [], 'server' => false ] );

		// A server set up without nginx's fastcgi.conf can pass no
		// DOCUMENT_ROOT, and looking for one mustn't build $_SERVER either.
		expect( json_decode( http( fpm(), 'GET', '/no-document-root' )['body'], true ) )->toBe( [ 'method' => 'GET', 'uri' => '/no-document-root', 'params' => [], 'server' => false ] );
	} )->skip( ! $fpm, 'needs nginx and php-fpm, which make fpm runs in Docker' );

	it( 'answers a miss with a 404, a 405 or a 308, and encodes raw bytes in Location', function() {
		$missing = http( fpm(), 'GET', '/nope' );
		$method = http( fpm(), 'POST', '/users/5' );
		$slash = http( fpm(), 'GET', "/users/5/?q=\xC3\xA9\xFF" );

		expect( [ $missing['status'], json_decode( $missing['body'], true )['uri'] ] )->toBe( [ 404, '/nope' ] );
		expect( [ $method['status'], $method['headers']['allow'], json_decode( $method['body'], true )['method'] ] )->toBe( [ 405, 'GET, HEAD', 'POST' ] );
		expect( [ $slash['status'], $slash['headers']['location'] ] )->toBe( [ 308, '/users/5?q=%C3%A9%FF' ] );
	} )->skip( ! $fpm, 'needs nginx and php-fpm, which make fpm runs in Docker' );

	// nginx would send the file itself, as it does any file in the document
	// root that php-fpm doesn't run.
	it( 'sends a request for another PHP file in the document root to Tillkite, not the file', function() {
		$other = http( fpm(), 'GET', '/other.php?a=1' );

		expect( [ $other['status'], json_decode( $other['body'], true )['uri'] ?? $other['body'] ] )->toBe( [ 404, '/other.php?a=1' ] );
	} )->skip( ! $fpm, 'needs nginx and php-fpm, which make fpm runs in Docker' );

	// Outside php -S, which lists every problem, with its paths.
	it( "describes a problem with the site's setup on the 500 page, without its paths", function() {
		$public = __DIR__ . '/fixtures/fpm/public';
		$unsafe = http( fpm(), 'GET', '/users/5', [ 'X-Handlers' => $public ] );

		expect( [ $unsafe['status'], $unsafe['body'] ] )->toBe( [ 500, "500 Internal Server Error\n\nThis site isn't set up safely, so Tillkite won't run it:\n\n"
			. "- The handlers directory is inside the document root, where a request can get or run its files directly, without the before-files. Move it outside the document root, and point the handlers option at it.\n\n"
			. "The error log has the details.\n" ] );
	} )->skip( ! $fpm, 'needs nginx and php-fpm, which make fpm runs in Docker' );

	it( "keeps PHP's messages off the page, with display_errors on in its configuration", function() {
		$warning = http( fpm(), 'GET', '/warning' );
		$thrown = http( fpm(), 'GET', '/throw' );

		expect( [ $warning['status'], $warning['body'] ] )->toBe( [ 200, "page\n" ] );
		expect( [ $thrown['status'], $thrown['body'] ] )->toBe( [ 500, "500 Internal Server Error\n" ] );
	} )->skip( ! $fpm, 'needs nginx and php-fpm, which make fpm runs in Docker' );

	// With OPcache on, it has to take the old copies of what changed out. Stamps
	// count in seconds, so each cached step waits for the next one.
	it( 'notices edits that keep a file\'s size and modification time, in the routes file and a file it loads', function() {
		$port = fpm( true );
		$site = fpm_site();
		$next_second = static function() : void {
			$now = time();
			while ( time() === $now ) {
				usleep( 10_000 );
			}
		};
		$write = static function( string $file, string $contents ) : void {
			$mtime = is_file( $file ) ? filemtime( $file ) : time() - 60;
			file_put_contents( $file, $contents );
			touch( $file, $mtime );
			clearstatcache();
		};
		$routes = static fn ( string $before ) : string => "<?php return [ [ 'GET', '/', 'home.php' ], [ 'GET', '/guarded', 'home.php', [ 'before' => '" . $before . "' ] ] ];\n";

		file_put_contents( $site . '/handlers/open.php', "<?php\n" );
		file_put_contents( $site . '/handlers/deny.php', "<?php http_response_code( 403 ); return false;\n" );
		$write( $site . '/one.php', $routes( 'open.php' ) );
		$write( $site . '/two.php', $routes( 'open.php' ) );
		$write( $site . '/routes.php', "<?php return require __DIR__ . '/one.php';\n" );

		$next_second();
		expect( http( $port, 'GET', '/guarded' )['status'] )->toBe( 200 );
		expect( http( $port, 'GET', '/guarded' )['status'] )->toBe( 200 );

		$write( $site . '/one.php', $routes( 'deny.php' ) );
		expect( http( $port, 'GET', '/guarded' )['status'] )->toBe( 403 );

		// Routes from a file that has only just changed aren't cached, so
		// another change right away is still noticed.
		$write( $site . '/one.php', $routes( 'open.php' ) );
		expect( http( $port, 'GET', '/guarded' )['status'] )->toBe( 200 );
		$write( $site . '/one.php', $routes( 'deny.php' ) );
		expect( http( $port, 'GET', '/guarded' )['status'] )->toBe( 403 );

		$next_second();
		expect( http( $port, 'GET', '/guarded' )['status'] )->toBe( 403 );

		$write( $site . '/routes.php', "<?php return require __DIR__ . '/two.php';\n" );
		expect( http( $port, 'GET', '/guarded' )['status'] )->toBe( 200 );
	} )->skip( ! $fpm, 'needs nginx and php-fpm, which make fpm runs in Docker' );

	it( 'keeps PHP out of the site that new makes, even when its init.php fails', function() {
		$page = http( fpm( true ), 'GET', '/' );
		$failed = http( fpm( true ), 'GET', '/', [ 'X-Fail' => '1' ] );

		expect( $page['status'] )->toBe( 200 );
		expect( $page['headers'] )->not->toHaveKey( 'x-powered-by' );
		expect( [ $failed['status'], $failed['body'] ] )->toBe( [ 500, '' ] );
	} )->skip( ! $fpm, 'needs nginx and php-fpm, which make fpm runs in Docker' );
} );
