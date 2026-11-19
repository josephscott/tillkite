<?php
declare( strict_types = 1 );

// register() and get() run in the test's own process, since they work
// anywhere. The example site's blog gets its posts with get() in a real
// request.

it( 'gets what was registered, the last value for a name registered twice, and logs a name with nothing registered', function() {
	$tillkite = new Tillkite\App();
	$db = new stdClass();
	$tillkite->register( 'db', $db );
	$tillkite->register( 'user', 'Ann' );
	$tillkite->register( 'user', 'Bea' );
	$tillkite->register( 'none', null );

	$log = logged( function() use ( $tillkite, $db ) {
		expect( $tillkite->get( 'db' ) )->toBe( $db );
		expect( $tillkite->get( 'user' ) )->toBe( 'Bea' );
		expect( $tillkite->get( 'none' ) )->toBeNull();
	} );
	expect( $log )->toBe( '' );

	$log = logged( function() use ( $tillkite ) {
		expect( $tillkite->get( 'dbb' ) )->toBeNull();
		expect( $tillkite->get( "x\n[06-Nov-2026] forged" ) )->toBeNull();
	} );
	expect( $log )->toContain( "Tillkite: nothing is registered as 'dbb', so get() returned null" );

	// A name with a newline can't start a line of its own in the log.
	expect( $log )->toContain( "Tillkite: nothing is registered as 'x\\n[06-Nov-2026] forged', so get() returned null" );
} );
