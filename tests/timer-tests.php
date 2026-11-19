<?php
declare( strict_types = 1 );

use Tillkite\Timer;

it( 'times each step in milliseconds, a running one so far, until it stops', function() {
	$timer = new Timer();
	usleep( 2000 );
	$timer->next( 'handler' );
	$running = $timer->timings();
	$timer->stop();

	expect( array_keys( $running ) )->toBe( [ 'total', 'route', 'handler' ] );
	expect( $running['route'] )->toBeGreaterThanOrEqual( 2.0 )->toBeLessThan( 1000.0 );
	expect( $running['total'] )->toBeGreaterThanOrEqual( $running['route'] );
	expect( $timer->timings() )->toBe( $timer->timings() );
} );

it( 'names the step it ended, and sends a template\'s step as tillkite-template, with its name as the description', function() {
	$timer = new Timer();
	$timer->next( 'handler' );
	expect( $timer->next( 'template:admin/"nav".php' ) )->toBe( 'handler' );
	expect( $timer->next( 'handler' ) )->toBe( 'template:admin/"nav".php' );
	$timer->stop();

	expect( array_keys( $timer->timings() ) )->toBe( [ 'total', 'route', 'handler', 'template:admin/"nav".php' ] );
	expect( $timer->header() )->toMatch( '{, tillkite-template;desc="admin/\\\\"nav\\\\"\.php";dur=[\d.]+$}' );
} );

// Only printable ASCII goes in the header, so a newline can't make PHP drop
// it, and % is encoded too, so each name has one form.
it( "percent-encodes what isn't printable ASCII in a template's name, and %", function() {
	$timer = new Timer();
	$timer->next( "template:a\nb\x01 café 100%.php" );
	$timer->stop();

	expect( $timer->header() )->toMatch( '{tillkite-template;desc="a%0Ab%01 caf%C3%A9 100%25\.php";dur=[\d.]+$}' );
} );
