<?php
declare( strict_types = 1 );

namespace Tillkite;

/**
 * Runs the routes file, each in a function scope of its own.
 *
 * The files run inside these static methods, so there's no $this, and their
 * class scope is this class, which has nothing private to reach. App's
 * private members stay out of reach. Their variables start with only the
 * ones each method names. The file paths come in as extra arguments, read
 * with func_get_arg(), so they aren't variables the files can see.
 *
 * @internal
 */
final class Runner {
	// Returns what the routes file returns. Its scope starts empty.
	public static function routes() : mixed {
		$routes = require \func_get_arg( 0 );
		return $routes;
	}
}
