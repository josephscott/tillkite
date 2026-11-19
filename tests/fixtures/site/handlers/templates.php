<?php
declare( strict_types = 1 );

// Runs a template that runs another. After the response, it runs one more,
// which isn't timed, and prints the steps that timings() has by then.
echo $tillkite->template( 'layout.php', [ 'name' => 'Ada' ] );
register_shutdown_function( function() use ( $tillkite ) : void {
	$tillkite->template( 'scope.php' );
	echo ' ', json_encode( array_keys( $tillkite->timings() ) );
} );
