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
