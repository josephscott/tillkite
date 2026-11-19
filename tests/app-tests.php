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
		'relative cache' => [ [ 'cache' => 'var' ], 'The cache option must be an absolute path, built from __DIR__: var' ],
		'a base that ends in /' => [ [ 'base' => '/app/' ], 'The base option must start with / and not end with /, like /app: /app/' ],
		'missing routes file' => [ [ 'routes' => '/nowhere/routes.php' ], "The routes file doesn't exist: /nowhere/routes.php" ],
		'not an array' => [ [ 'routes' => __DIR__ . '/fixtures/handlers/page.php' ], 'The routes file must return an array of routes, not int' ],
	] );

	// Outside php -S, the request runs as a plain PHP script, the way
	// php-fpm would run it. A problem with a newline can't add a line of its
	// own to the log.
	it( 'logs the problems outside php -S, and runs the 500 file or sends a plain 500', function( array $options, string $output, string $logged ) {
		$environment = [ 'REQUEST_URI' => '/', 'REQUEST_METHOD' => 'GET', 'HTTP_X_OPTIONS' => json_encode( $options + [ 'trailing_slash' => 1 ] ) ];
		$command = [ PHP_BINARY, '-d', 'display_errors=0', '-d', 'error_log=/dev/stderr', SITE . '/public/index.php' ];
		$process = proc_open( $command, [ [ 'file', '/dev/null', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes, null, $environment );
		$response = stream_get_contents( $pipes[1] );
		$log = stream_get_contents( $pipes[2] );
		proc_close( $process );

		expect( $response )->toBe( $output );
		expect( $log )->toContain( 'Tillkite: The trailing_slash option must be a bool, not int' );
		expect( $log )->toContain( $logged );
	} )->with( [
		'no 500 file' => [ [ 'routes' => "/no\nwhere" ], "500 Internal Server Error\n", "Tillkite: The routes file doesn't exist: /no\\nwhere\n" ],
		'a 500 file' => [
			[ 'errors' => [ 500 => '500.php' ] ],
			"the 500 page: Tillkite found problems with its configuration:\n\n- The trailing_slash option must be a bool, not int\n"
				. ' [["tillkite","params","error"],[]]',
			'',
		],
		"a problem with the site's setup, which the 500 file doesn't get" => [
			[ 'errors' => [ 500 => '500.php' ], 'cache' => '/nowhere' ],
			"500 Internal Server Error\n\nThis site isn't set up safely, so Tillkite won't run it:\n\n"
				. "- The cache directory doesn't exist, so the routes would be compiled on every request. Make it, writable only by the user PHP runs as, or leave the cache option out.\n\n"
				. "The error log has the details.\n",
			"Tillkite: The cache directory doesn't exist, so the routes would be compiled on every request. Make it, writable only by the user PHP runs as, or leave the cache option out: /nowhere\n",
		],
	] );
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

describe( 'base', function() {
	it( 'removes base before matching, and sends a 404 outside it', function() {
		$options = [ 'base' => '/app' ];

		expect( request( 'GET', '/app/users/5', $options )['body'] )->toBe( '{"id":"5"}' );
		expect( request( 'GET', '/users/5', $options )['status'] )->toBe( 404 );
		expect( request( 'GET', '/application', $options )['status'] )->toBe( 404 );
	} );

	it( 'redirects the root URL to the form trailing_slash asks for', function( bool $trailing_slash, string $root, string $other ) {
		$options = [ 'base' => '/app', 'trailing_slash' => $trailing_slash, 'routes' => SITE . '/root-routes.php' ];
		$redirect = request( 'GET', $other . '?a=1', $options );

		expect( request( 'GET', $root, $options )['body'] )->toBe( 'text' );
		expect( [ $redirect['status'], $redirect['headers']['location'] ] )->toBe( [ 308, $root . '?a=1' ] );
	} )->with( [
		'without the slash' => [ false, '/app', '/app/' ],
		'with the slash' => [ true, '/app/', '/app' ],
	] );
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
		$response = request( 'GET', '/error/405', [ 'errors' => [ 500 => '500.php' ] ] );
		$message = 'error() takes a 4xx code other than 405, like 404 or 403, not 405';

		expect( [ $response['status'], $response['body'] ] )->toBe( [ 500, 'the 500 page: ' . $message . ' [["tillkite","params","error"],{"code":"405"}]' ] );
		expect( file_get_contents( site()['log'] ) )->toContain( 'Tillkite: ' . $message );
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

describe( 'the 500 file', function() {
	it( 'runs for an uncaught exception, and throws away the buffered output', function() {
		$response = request( 'GET', '/throws/7', [ 'errors' => [ 500 => '500.php' ] ] );

		expect( $response['status'] )->toBe( 500 );
		expect( $response['body'] )->toBe( 'the 500 page: boom [["tillkite","params","error"],{"id":"7"}]' );
	} );

	it( 'adds its output to output already sent, and logs the error', function() {
		$response = request( 'GET', '/throws/7', [ 'buffer' => false, 'errors' => [ 500 => '500.php' ] ] );

		expect( $response['body'] )->toStartWith( 'partialthe 500 page: boom' );
		expect( file_get_contents( site()['log'] ) )->toContain( 'Tillkite: uncaught RuntimeException: boom' );
	} );

	it( 'runs when matching the request fails, which is also logged', function() {
		$path = '/backtrack/' . str_repeat( 'a', 60 ) . '!';
		$with_file = request( 'GET', $path, [ 'errors' => [ 500 => '500.php' ] ] );
		$without_file = request( 'GET', $path );

		expect( [ $with_file['status'], $with_file['body'] ] )
			->toBe( [ 500, 'the 500 page: Matching the request failed: Backtrack limit exhausted [["tillkite","params","error"],[]]' ] );
		expect( [ $without_file['status'], $without_file['body'] ] )->toBe( [ 500, "500 Internal Server Error\n" ] );
		expect( file_get_contents( site()['log'] ) )->toContain( 'Tillkite: matching the request failed: Backtrack limit exhausted' );
	} );

	it( 'runs for an exception in the 404 file', function() {
		$response = request( 'GET', '/nope', [ 'errors' => [ 404 => 'throws.php', 500 => '500.php' ] ] );

		expect( [ $response['status'], $response['body'] ] )->toBe( [ 500, 'the 500 page: boom [["tillkite","params","error"],[]]' ] );
	} );
} );

// A fatal error ends the request without reaching the 500 file.
describe( 'fatal errors', function() {
	it( 'replace the buffered output with one line of text', function( string $kind ) {
		$response = request( 'GET', '/fatal/' . $kind . '/0' );

		expect( [ $response['status'], $response['body'] ] )->toBe( [ 500, "500 Internal Server Error\n" ] );
	} )->with( [
		'running out of memory' => [ 'memory' ],
		'an uncaught exception, with no 500 file' => [ 'exception' ],
	] );

	it( "keep PHP's message when display_errors is on", function() {
		$response = request( 'GET', '/fatal/exception/1' );

		expect( $response['status'] )->toBe( 500 );
		expect( $response['body'] )->toStartWith( 'partial' )->toContain( 'Uncaught RuntimeException: boom' );
	} );
} );

describe( 'the route cache', function() {
	it( 'compiles the routes once, and again when the routes file changes', function() {
		$dir = temp_dir();
		mkdir( $dir . '/var' );
		$options = [ 'routes' => $dir . '/routes.php', 'handlers' => SITE . '/handlers', 'cache' => $dir . '/var' ];
		$mtime = time() - 60;

		// A routes file that notes each time it runs.
		file_put_contents( $dir . '/routes.php', "<?php file_put_contents( __DIR__ . '/ran', 'x', FILE_APPEND ); return [ [ 'GET', '/a', 'text.php' ] ];\n" );
		touch( $dir . '/routes.php', $mtime );
		expect( request( 'GET', '/a', $options )['body'] )->toBe( 'text' );
		expect( request( 'GET', '/a', $options )['body'] )->toBe( 'text' );
		expect( file_get_contents( $dir . '/ran' ) )->toBe( 'x' );

		// A new modification time. OPcache still has the old routes file, so
		// this also checks that Tillkite clears it, and that the old cache
		// file is removed.
		write_routes( $dir . '/routes.php', '/b', $mtime + 30 );
		expect( request( 'GET', '/b', $options )['body'] )->toBe( 'text' );
		expect( scandir( $dir . '/var' ) )->toHaveCount( 3 );
	} );

	// As a plain PHP script, as under php-fpm, with no wait for OPcache, which
	// the command line doesn't use.
	it( 'compiles the routes again when a file that the routes file loaded changes', function() {
		$dir = temp_dir();
		mkdir( $dir . '/var' );
		file_put_contents( $dir . '/routes.php', "<?php return require __DIR__ . '/more.php';\n" );
		file_put_contents( $dir . '/more.php', "<?php return [ [ 'GET', '/a', 'text.php' ] ];\n" );
		$get = function( string $path ) use ( $dir ) : string {
			$options = [ 'routes' => $dir . '/routes.php', 'handlers' => SITE . '/handlers', 'cache' => $dir . '/var' ];
			$environment = [ 'REQUEST_URI' => $path, 'REQUEST_METHOD' => 'GET', 'HTTP_X_OPTIONS' => json_encode( $options ) ];
			$command = [ PHP_BINARY, '-d', 'display_errors=0', '-d', 'opcache.revalidate_freq=-1', SITE . '/public/index.php' ];
			$process = proc_open( $command, [ [ 'file', '/dev/null', 'r' ], [ 'pipe', 'w' ], [ 'file', '/dev/null', 'w' ] ], $pipes, null, $environment );
			$body = (string) stream_get_contents( $pipes[1] );
			proc_close( $process );
			return $body;
		};

		expect( $get( '/a' ) )->toBe( 'text' );
		$cached = include glob( $dir . '/var/tillkite-*.php' )[0];
		expect( array_keys( $cached['includes'] ) )->toBe( [ realpath( $dir . '/more.php' ) ] );

		file_put_contents( $dir . '/more.php', "<?php return [ [ 'GET', '/changed', 'text.php' ] ];\n" );
		expect( $get( '/changed' ) )->toBe( 'text' );
		expect( $get( '/a' ) )->toBe( "404 Not Found\n" );
	} );

	// The tests run under php -S, where Tillkite's own files are in the key.
	// A new modification time for the compiler's file is enough.
	it( "uses a new cache file under php -S when one of Tillkite's own files changes", function() {
		$dir = temp_dir();
		$options = [ 'cache' => $dir ];
		request( 'GET', '/', $options );
		$first = scandir( $dir );

		$file = (string) ( new ReflectionClass( Tillkite\Compiler::class ) )->getFileName();
		$mtime = (int) filemtime( $file );
		touch( $file, $mtime + 1 );
		$body = request( 'GET', '/', $options )['body'];
		touch( $file, $mtime );

		expect( $body )->toBe( 'text' );
		expect( scandir( $dir ) )->toHaveCount( 3 );
		expect( scandir( $dir ) )->not->toBe( $first );
	} );

	it( "doesn't cache routes that have problems", function() {
		$dir = temp_dir();

		expect( request( 'GET', '/', [ 'routes' => SITE . '/problem-routes.php', 'cache' => $dir ] )['status'] )->toBe( 500 );
		expect( scandir( $dir ) )->toBe( [ '.', '..' ] );
	} );

	it( "reports a cache directory PHP can't write to as a problem with the site's setup", function() {
		$dir = temp_dir();
		chmod( $dir, 0o555 );
		$response = request( 'GET', '/', [ 'cache' => $dir ] );
		chmod( $dir, 0o755 );

		expect( $response['status'] )->toBe( 500 );
		expect( $response['body'] )->toContain( "- The user PHP runs as can't write to the cache directory, so the routes would be compiled on every request. Make it writable by that user, or leave the cache option out: " . $dir . "\n" );
	} )->skip( function_exists( 'posix_geteuid' ) && posix_geteuid() === 0, 'root can write to any directory' );
} );

describe( 'php -S', function() {
	it( 'leaves a file in the document root for php -S to serve', function() {
		$response = request( 'GET', '/style.css' );

		expect( $response['body'] )->toBe( "body { color: teal; }\n" );
		expect( $response['headers']['content-type'] )->toStartWith( 'text/css' );
	} );

	it( 'routes a path that only looks like a file there', function( string $path ) {
		expect( request( 'GET', $path )['body'] )->toBe( "404 Not Found\n" );
	} )->with( [
		'outside the document root' => [ '/../routes.php' ],
		'the front controller' => [ '/index.php' ],
		'another PHP file there, which php -S would run' => [ '/other.php' ],
		'a null byte' => [ '/style.css%00' ],
	] );
} );
