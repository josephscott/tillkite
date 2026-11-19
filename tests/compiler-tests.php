<?php
declare( strict_types = 1 );

describe( 'problems', function() {
	it( 'reports a routes list with keys', function() {
		$problems = route_problems( [ 'home' => [ 'GET', '/', 'page.php' ] ] );

		expect( $problems )->toBe( [ 'The routes file must return a list of routes, without keys.' ] );
	} );

	it( 'reports bad settings', function( array $settings, string $message ) {
		$problems = route_problems( [], $settings );

		expect( $problems )->toBe( [ $message ] );
	} )->with( [
		'missing handlers directory' => [ [ 'handlers' => '/no/such/dir' ], "The handlers directory doesn't exist: /no/such/dir" ],
		'missing before file' => [ [ 'before' => 'missing.php' ], "The before file doesn't exist: " . __DIR__ . '/fixtures/handlers/missing.php' ],
		'lowercase custom method' => [ [ 'methods' => [ 'purge' ] ], "Custom methods must be uppercase, like PURGE: 'purge'" ],
		'custom method with a newline' => [ [ 'methods' => [ "PURGE\n" ] ], "Custom methods must be uppercase, like PURGE: 'PURGE\n'" ],
		'custom method not a string' => [ [ 'methods' => [ [ 'PURGE', 1 ] ] ], "Custom methods must be uppercase, like PURGE: [ 'PURGE', 1 ]" ],
	] );

	it( 'accepts valid settings', function() {
		compile_routes( [], [ 'before' => 'before.php', 'methods' => [ 'PURGE', 'GET' ] ] );
	} );
} );
