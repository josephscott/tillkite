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
