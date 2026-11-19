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
	// and the route's $params. Either path can be '', for none. A before-file
	// that returns false stops the handler, and then this returns false. The
	// returns are bare, since a variable here would be one the files see.
	public static function files( App $tillkite, array $params ) : bool {
		if ( \func_get_arg( 2 ) !== '' && ( require \func_get_arg( 2 ) ) === false ) {
			return false;
		}

		if ( \func_get_arg( 3 ) !== '' ) {
			require \func_get_arg( 3 );
		}

		return true;
	}
}
