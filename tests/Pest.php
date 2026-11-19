<?php
declare( strict_types = 1 );

// Pest loads this file before running the tests. It must be named Pest.php.

// A compiler whose handlers directory is tests/fixtures/handlers.
function make_compiler( array $settings = [] ) : Tillkite\Compiler {
	$compiler = new Tillkite\Compiler();
	$compiler->handlers = __DIR__ . '/fixtures/handlers';
	foreach ( $settings as $name => $value ) {
		$compiler->$name = $value;
	}

	return $compiler;
}

function compile_routes( array $routes, array $settings = [] ) : array {
	$compiler = make_compiler( $settings );
	$compiled = $compiler->compile( $routes );
	expect( $compiler->problems )->toBe( [] );
	return $compiled;
}

function route_problems( array $routes, array $settings = [] ) : array {
	$compiler = make_compiler( $settings );
	$compiler->compile( $routes );
	$problems = $compiler->problems;
	return $problems;
}

function make_matcher( array $routes, array $settings = [] ) : Tillkite\Matcher {
	$matcher = new Tillkite\Matcher( compile_routes( $routes, $settings ) );
	return $matcher;
}

// A new directory, removed when the tests end.
function temp_dir() : string {
	$dir = sys_get_temp_dir() . '/tillkite-test-' . uniqid();
	mkdir( $dir );
	register_shutdown_function( remove_dir( ... ), $dir );
	return $dir;
}

function remove_dir( string $dir ) : void {
	foreach ( array_diff( (array) scandir( $dir ), [ '.', '..' ] ) as $name ) {
		$path = $dir . '/' . $name;
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			remove_dir( $path );
		} else {
			unlink( $path );
		}
	}

	rmdir( $dir );
}

// Writes a routes file with one route, to text.php, and sets its
// modification time.
function write_routes( string $file, string $path, int $mtime ) : void {
	file_put_contents( $file, "<?php return [ [ 'GET', '" . $path . "', 'text.php' ] ];\n" );
	touch( $file, $mtime );
}

// Starts php -S on tests/fixtures/site the first time it's called, and stops
// it when the tests end. Returns its port and the log file that gets its
// output and errors.
function site() : array {
	$site = serve( __DIR__ . '/fixtures/site/public' );
	return $site;
}

// Starts php -S on a document root, with its index.php as the router, once
// for each document root. PHP's own output buffer is off, as it is by
// default, so only Tillkite's buffering decides when headers go out.
// OPcache is on, as it is in production.
function serve( string $public ) : array {
	static $servers = [];
	if ( isset( $servers[$public] ) ) {
		return $servers[$public];
	}

	$log = tempnam( sys_get_temp_dir(), 'tillkite-site-' );
	$port = free_port();

	$output = [ 'file', $log, 'a' ];
	$command = [ PHP_BINARY, '-d', 'opcache.enable_cli=1', '-d', 'output_buffering=0', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $log, '-S', '127.0.0.1:' . $port, '-t', $public, $public . '/index.php' ];
	$process = proc_open( $command, [ [ 'file', '/dev/null', 'r' ], $output, $output ], $pipes );
	register_shutdown_function( function() use ( $process, $log ) : void {
		proc_terminate( $process );
		unlink( $log );
	} );

	for ( $i = 0; $i < 300 && ! str_contains( (string) file_get_contents( $log ), 'started' ); $i++ ) {
		usleep( 10_000 );
	}

	$servers[$public] = [ 'port' => $port, 'log' => $log ];
	return $servers[$public];
}

// A TCP port on 127.0.0.1 that nothing is listening on.
function free_port() : int {
	$socket = stream_socket_server( 'tcp://127.0.0.1:0' );
	$port = (int) substr( (string) strrchr( (string) stream_socket_get_name( $socket, false ), ':' ), 1 );
	fclose( $socket );
	return $port;
}

function request( string $method, string $target, array $options = [], array $set = [] ) : array {
	$headers = [];
	if ( $options !== [] ) {
		$headers['X-Options'] = json_encode( $options );
	}

	if ( $set !== [] ) {
		$headers['X-Set'] = json_encode( $set );
	}

	$response = http( site()['port'], $method, $target, $headers );
	return $response;
}

// Sends a raw HTTP/1.0 request, so the request target goes out exactly as
// written. Header names come back in lowercase.
function http( int $port, string $method, string $target, array $headers = [], string $body = '' ) : array {
	$client = stream_socket_client( 'tcp://127.0.0.1:' . $port );
	$request = $method . ' ' . $target . " HTTP/1.0\r\nHost: localhost\r\nContent-Length: " . strlen( $body ) . "\r\n";
	foreach ( $headers as $name => $value ) {
		$request .= $name . ': ' . $value . "\r\n";
	}

	fwrite( $client, $request . "\r\n" . $body );
	$response = (string) stream_get_contents( $client );
	fclose( $client );

	[ $head, $body ] = explode( "\r\n\r\n", $response, 2 ) + [ '', '' ];
	$lines = explode( "\r\n", $head );
	$headers = [];
	foreach ( array_slice( $lines, 1 ) as $line ) {
		[ $name, $value ] = explode( ':', $line, 2 );
		$headers[strtolower( $name )] = trim( $value );
	}

	$result = [ 'status' => (int) explode( ' ', $lines[0] )[1], 'headers' => $headers, 'body' => $body ];
	return $result;
}
