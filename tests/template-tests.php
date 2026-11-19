<?php
declare( strict_types = 1 );

// template() runs in the test's own process, since it works anywhere, even
// before run().

const TEMPLATES = __DIR__ . '/fixtures/templates';

it( 'returns what the template printed, from a scope with only $tillkite and $data', function() {
	$tillkite = new Tillkite\App( [ 'templates' => TEMPLATES ] );
	expect( $tillkite->template( 'scope.php', [ 'title' => 'Hi' ] ) )->toBe( '{"vars":["tillkite","data"],"data":{"title":"Hi"}}' );
	expect( $tillkite->template( 'scope.php' ) )->toBe( '{"vars":["tillkite","data"],"data":[]}' );
} );

it( 'runs a template from a template, and keeps what a template left in buffers of its own', function() {
	$level = ob_get_level();
	$tillkite = new Tillkite\App( [ 'templates' => TEMPLATES ] );
	expect( $tillkite->template( 'layout.php', [ 'name' => 'Ada' ] ) )->toBe( '<main>Hello, Ada</main>' );
	expect( ob_get_level() )->toBe( $level );
} );

it( "finds templates in the handlers directory, or else the routes file's, when templates is empty", function( array $options ) {
	expect( ( new Tillkite\App( $options ) )->template( 'scope.php' ) )->toBe( '{"vars":["tillkite","data"],"data":[]}' );
} )->with( [
	'handlers' => [ [ 'handlers' => TEMPLATES, 'routes' => '/nowhere/routes.php' ] ],
	'routes' => [ [ 'routes' => TEMPLATES . '/routes.php' ] ],
] );

it( 'throws away what a template that throws printed, and the buffers it started, and passes the exception on', function() {
	$tillkite = new Tillkite\App( [ 'templates' => TEMPLATES ] );
	$level = ob_get_level();
	ob_start();
	expect( fn () => $tillkite->template( 'throws.php' ) )->toThrow( RuntimeException::class, 'Boom' );
	expect( ob_get_level() )->toBe( $level + 1 );
	expect( ob_get_clean() )->toBe( '' );
} );

it( 'runs nothing for a name that is not a .php file in the directory, and logs it', function( string $name ) {
	$tillkite = new Tillkite\App( [ 'templates' => TEMPLATES ] );
	$log = logged( function() use ( $tillkite, $name ) {
		expect( $tillkite->template( $name ) )->toBe( '' );
	} );
	expect( $log )->toContain( "Tillkite: template() takes a .php file in the templates directory, so it ran nothing for '" . Tillkite\Escape::log( $name ) . "', and returned ''" );
} )->with( [
	'.. back into it' => '../templates/scope.php',
	'.. after a directory that does not exist' => 'nowhere/../../templates/scope.php',
	'.. with backslashes' => '..\\templates\\scope.php',
	'not .php' => 'scope.json',
	'a NUL' => "scope\0.php",
] );
