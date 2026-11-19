<?php
declare( strict_types = 1 );

namespace Tillkite;

/**
 * Runs the routes file, before-files and handlers, each in a function scope of its own.
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

	// Runs the before-file, then the handler, in one scope, with $tillkite
	// and the route's $params. The before-file's path can be '', for none. A
	// before-file that returns false stops the handler. The error files run
	// here too, as a handler with no before-file, and so does the before-file
	// when no route matches, with no handler.
	//
	// The last argument is App's next_file(), which runs before the handler.
	// It returns false once a file has called error(), and then the handler
	// doesn't run. When no route matches, it returns false in the handler's
	// place.
	public static function files( App $tillkite, array $params ) : void {
		if ( \func_get_arg( 2 ) !== '' && ( require \func_get_arg( 2 ) ) === false ) {
			return;
		}

		if ( \func_get_arg( 4 )() ) {
			require \func_get_arg( 3 );
		}
	}
}
