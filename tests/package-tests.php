<?php
declare( strict_types = 1 );

// In a checkout under a document root, a request shouldn't run these. php -S
// runs a .php file under its document root, and takes bin/tillkite as its
// router. A time limit keeps one that does run from running long.
test( "the repository's scripts don't run for a web request", function( string $target, array $server ) : void {
	$root = dirname( __DIR__ );
	$port = free_port();
	$command = [ PHP_BINARY, '-d', 'max_execution_time=2', '-d', 'display_errors=0', '-d', 'register_argc_argv=1', '-S', '127.0.0.1:' . $port, '-t', $root, ...$server ];
	$process = proc_open( $command, [ [ 'file', '/dev/null', 'r' ], [ 'file', '/dev/null', 'w' ], [ 'file', '/dev/null', 'w' ] ], $pipes, $root );
	register_shutdown_function( static function() use ( $process ) : void {
		proc_terminate( $process );
	} );

	for ( $i = 0; $i < 300; $i++ ) {
		set_error_handler( static fn () : bool => true );
		$socket = stream_socket_client( 'tcp://127.0.0.1:' . $port, $errno, $error, 0.1 );
		restore_error_handler();
		if ( $socket !== false ) {
			fclose( $socket );
			break;
		}

		usleep( 10_000 );
	}

	$response = http( $port, 'GET', $target );
	proc_terminate( $process );

	expect( $response['status'] )->toBe( 404 );
	expect( $response['body'] )->not->toContain( 'Usage' );
	expect( $response['body'] )->not->toContain( 'Made a Tillkite site' );
} )->with( [
	'bin/dist.php' => [ '/bin/dist.php', [] ],
	'bin/tillkite' => [ '/?new+made-by-a-request', [ 'bin/tillkite' ] ],
] );
