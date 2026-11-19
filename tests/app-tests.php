<?php
declare( strict_types = 1 );

// These tests make real requests to tests/fixtures/site under php -S. See
// site() and request() in Pest.php.

const SITE = __DIR__ . '/fixtures/site';

describe( 'configuration problems', function() {
	it( 'lists every problem in the response under php -S', function() {
		$response = request(
			'GET',
			'/',
			[ 'views' => '/views', 'trailing_slash' => 'no', 'routes' => SITE . '/problem-routes.php' ],
			[ 'colour' => 'teal' ]
		);

		expect( $response['status'] )->toBe( 500 );
		expect( $response['headers']['content-type'] )->toBe( 'text/plain; charset=utf-8' );
		expect( $response['body'] )->toBe( "Tillkite found problems with its configuration:\n\n"
			. "- Unknown option: views\n"
			. "- The trailing_slash option must be a bool, not string\n"
			. "- Unknown option: colour\n"
			. "- Route 1 [ 'GET', '/', 'missing.php' ]: the file doesn't exist: " . SITE . "/handlers/missing.php\n" );
	} );

	it( 'reports a problem with the routes or handlers option', function( array $options, string $message ) {
		expect( request( 'GET', '/', $options )['body'] )->toContain( '- ' . $message . "\n" );
	} )->with( [
		'no routes' => [ [ 'routes' => '' ], 'The routes option is required.' ],
		'relative routes' => [ [ 'routes' => 'routes.php' ], 'The routes option must be an absolute path, built from __DIR__: routes.php' ],
		'relative handlers' => [ [ 'handlers' => 'handlers' ], 'The handlers option must be an absolute path, built from __DIR__: handlers' ],
		'missing routes file' => [ [ 'routes' => '/nowhere/routes.php' ], "The routes file doesn't exist: /nowhere/routes.php" ],
		'not an array' => [ [ 'routes' => __DIR__ . '/fixtures/handlers/page.php' ], 'The routes file must return an array of routes, not int' ],
	] );

	// Outside php -S, the request runs as a plain PHP script, the way
	// php-fpm would run it. A problem with a newline can't add a line of its
	// own to the log.
	it( 'logs the problems outside php -S, and sends a plain 500', function() {
		$environment = [ 'REQUEST_URI' => '/', 'REQUEST_METHOD' => 'GET', 'HTTP_X_OPTIONS' => json_encode( [ 'trailing_slash' => 1, 'routes' => "/no\nwhere" ] ) ];
		$command = [ PHP_BINARY, '-d', 'display_errors=0', '-d', 'error_log=/dev/stderr', SITE . '/public/index.php' ];
		$process = proc_open( $command, [ [ 'file', '/dev/null', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes, null, $environment );
		$response = stream_get_contents( $pipes[1] );
		$log = stream_get_contents( $pipes[2] );
		proc_close( $process );

		expect( $response )->toBe( "500 Internal Server Error\n" );
		expect( $log )->toContain( 'Tillkite: The trailing_slash option must be a bool, not int' );
		expect( $log )->toContain( "Tillkite: The routes file doesn't exist: /no\\nwhere\n" );
	} );
} );

describe( 'routing', function() {
	it( 'runs the handler with the decoded parameters', function() {
		expect( request( 'GET', '/users/5' )['body'] )->toBe( '{"id":"5"}' );
		expect( request( 'GET', '/files/a%2Fb/c%20d?x=1' )['body'] )->toBe( '{"path":"a\\/b\\/c d"}' );
	} );

	it( 'runs the handler in a scope with only $tillkite and $params, and no access to App internals', function() {
		expect( request( 'GET', '/scope' )['body'] )->toBe( '{"vars":["tillkite","params"],"private":false}' );
	} );

	it( 'sends a plain-text 404 and 405 when there are no error files', function() {
		$missing = request( 'GET', '/nope' );
		$wrong_method = request( 'POST', '/users/5' );

		expect( [ $missing['status'], $missing['body'] ] )->toBe( [ 404, "404 Not Found\n" ] );
		expect( [ $wrong_method['status'], $wrong_method['body'] ] )->toBe( [ 405, "405 Method Not Allowed\n" ] );
		expect( $wrong_method['headers']['allow'] )->toBe( 'GET, HEAD' );
	} );

	it( 'runs the before-file, then the 404 or 405 file or the 308 redirect', function() {
		$options = [ 'errors' => [ 404 => '404.php', 405 => '405.php' ], 'before' => 'before.php' ];
		$missing = request( 'GET', '/nope', $options );
		$wrong_method = request( 'POST', '/users/5', $options );
		$redirect = request( 'GET', '/users/5/', $options );

		expect( [ $missing['status'], $missing['body'] ] )->toBe( [ 404, 'the 404 page' ] );
		expect( [ $wrong_method['status'], $wrong_method['body'] ] )->toBe( [ 405, 'the 405 page' ] );
		expect( $wrong_method['headers']['allow'] )->toBe( 'GET, HEAD' );
		expect( $redirect['status'] )->toBe( 308 );
		expect( [ $missing['headers']['x-before'], $wrong_method['headers']['x-before'], $redirect['headers']['x-before'] ] )->toBe( [ 'ran', 'ran', 'ran' ] );
	} );

	it( 'redirects to the other trailing slash form, keeping the query string', function() {
		$response = request( 'GET', '/users/5/?tab=posts' );

		expect( $response['status'] )->toBe( 308 );
		expect( $response['headers']['location'] )->toBe( '/users/5?tab=posts' );
	} );

	// With a catch-all, //example.com/ would redirect to //example.com, which
	// a browser reads as a link to another site, and so would /\example.com/.
	it( "doesn't redirect to a path that would leave the site", function() {
		$options = [ 'routes' => SITE . '/catch-all-routes.php' ];

		expect( request( 'GET', '/docs/', $options )['headers']['location'] )->toBe( '/docs' );
		expect( request( 'GET', '//example.com/', $options )['status'] )->toBe( 404 );
		expect( request( 'GET', '/\\example.com/', $options )['status'] )->toBe( 404 );
	} );

	it( "runs the before-file in the handler's scope", function() {
		expect( request( 'GET', '/scope', [ 'before' => 'before.php' ] )['body'] )->toBe( '{"vars":["tillkite","params","from_before"],"private":false}' );
	} );

	it( 'stops at a before-file that returns false, before the handler or the 404', function( string $target ) {
		$response = request( 'GET', $target, [ 'before' => 'deny.php' ] );

		expect( $response['status'] )->toBe( 401 );
		expect( $response['body'] )->toBe( 'denied' );
	} )->with( [ 'a route' => [ '/scope' ], 'a miss' => [ '/nowhere' ] ] );

	it( "stops at a before-file that redirects or sets an error status, even when it doesn't return false", function( string $target, string $before, int $status, string $body, ?string $location ) {
		$response = request( 'GET', $target, [ 'before' => $before ] );

		expect( [ $response['status'], $response['body'], $response['headers']['location'] ?? null ] )->toBe( [ $status, $body, $location ] );
	} )->with( [
		'a redirect, before the handler' => [ '/', 'redirect-before.php', 302, 'signing in', '/sign-in' ],
		'an error status' => [ '/', 'forbid-before.php', 403, 'forbidden', null ],
		'a redirect, in place of a 404' => [ '/nope', 'redirect-before.php', 302, 'signing in', '/sign-in' ],
		'a status under 300' => [ '/', 'created-before.php', 201, 'text', '/new' ],
	] );

	it( 'buffers output, so a header can come after it, unless buffering is off', function() {
		expect( request( 'GET', '/late-header' )['headers'] )->toHaveKey( 'x-late' );
		expect( request( 'GET', '/late-header', [ 'buffer' => false ] )['headers'] )->not->toHaveKey( 'x-late' );
	} );

	it( 'copes with a handler that ends the buffer itself', function() {
		expect( request( 'GET', '/ends-buffer' )['body'] )->toBe( 'ab' );
	} );

	it( "throws away a miss's before-file output, and keeps a route's", function() {
		expect( request( 'GET', '/nope', [ 'before' => 'prints-before.php' ] )['body'] )->toBe( "404 Not Found\n" );
		expect( request( 'GET', '/', [ 'before' => 'prints-before.php' ] )['body'] )->toBe( 'before text' );
	} );
} );

describe( 'error()', function() {
	// error.php writes, calls error() without returning its false, and
	// writes again. before.php sets a variable that the 403 file can't see.
	it( "throws away the handler's output, then runs the error file in its own scope with the route's \$params, or sends plain text", function( string $path, array $options, int $status, string $body ) {
		$response = request( 'GET', $path, $options );

		expect( [ $response['status'], $response['body'] ] )->toBe( [ $status, $body ] );
	} )->with( [
		'the 404 file' => [ '/error/404', [ 'errors' => [ 404 => '404.php' ] ], 404, 'the 404 page' ],
		'a 403 file' => [ '/error/403', [ 'before' => 'before.php', 'errors' => [ 403 => '403.php' ] ], 403, 'the 403 page [["tillkite","params"],{"code":"403"}]' ],
		'plain text' => [ '/error/410', [], 410, "410 Gone\n" ],
	] );

	// error-before.php calls it without returning its false.
	it( "stops at a before-file that calls it, even when it doesn't return false", function( string $method, string $target ) {
		$response = request( $method, $target, [ 'before' => 'error-before.php' ] );

		expect( [ $response['status'], $response['body'] ] )->toBe( [ 403, "403 Forbidden\n" ] );
		expect( $response['headers'] )->not->toHaveKey( 'allow' );
	} )->with( [
		'a route' => [ 'GET', '/' ],
		'a miss' => [ 'POST', '/' ],
	] );

	it( "sends a 500 for a code it doesn't take, and logs it", function() {
		$response = request( 'GET', '/error/405' );

		expect( [ $response['status'], $response['body'] ] )->toBe( [ 500, "500 Internal Server Error\n" ] );
		expect( file_get_contents( site()['log'] ) )->toContain( 'Tillkite: error() takes a 4xx code other than 405, like 404 or 403, not 405' );
	} );

	it( 'does nothing in an error file, and logs it', function() {
		$response = request( 'GET', '/nope', [ 'errors' => [ 404 => 'calls-error.php' ] ] );

		expect( [ $response['status'], $response['body'] ] )->toBe( [ 404, 'still the 404 page' ] );
		expect( file_get_contents( site()['log'] ) )->toContain( 'Tillkite: error() works only in a before-file or handler, so error( 403 ) did nothing' );
	} );

	it( 'adds nothing to output already sent, and logs it', function() {
		$response = request( 'GET', '/error/404', [ 'buffer' => false, 'errors' => [ 404 => '404.php' ] ] );

		expect( [ $response['status'], $response['body'] ] )->toBe( [ 200, 'partial after' ] );
		expect( file_get_contents( site()['log'] ) )->toContain( 'Tillkite: error( 404 ) came after the output had started, so nothing was added to it' );
	} );
} );
