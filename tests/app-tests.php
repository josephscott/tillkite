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

	it( 'sends a plain 404 when nothing matches, after the before-file', function() {
		$response = request( 'GET', '/nowhere', [ 'before' => 'before.php' ] );

		expect( $response['status'] )->toBe( 404 );
		expect( $response['headers']['x-before'] )->toBe( 'ran' );
		expect( $response['body'] )->toBe( "404 Not Found\n" );
	} );

	it( "runs the before-file in the handler's scope", function() {
		expect( request( 'GET', '/scope', [ 'before' => 'before.php' ] )['body'] )->toBe( '{"vars":["tillkite","params","from_before"],"private":false}' );
	} );

	it( 'stops at a before-file that returns false, before the handler or the 404', function( string $target ) {
		$response = request( 'GET', $target, [ 'before' => 'deny.php' ] );

		expect( $response['status'] )->toBe( 401 );
		expect( $response['body'] )->toBe( 'denied' );
	} )->with( [ 'a route' => [ '/scope' ], 'a miss' => [ '/nowhere' ] ] );
} );
