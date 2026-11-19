<?php
declare( strict_types = 1 );

namespace Tillkite;

/**
 * Runs the routes file, before-files, handlers, error files and templates,
 * each in a function scope of its own.
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

	// Runs up to three before-files, in order, then the handler, in one
	// scope, with $tillkite and the route's $params. Compiler has worked out
	// which before-files a route runs, and the paths of the ones it doesn't
	// are '', after the ones it does. A before-file that returns false stops
	// the files after it. The error files run here too, as a handler with no
	// before-files, and so do the before-files when no route matches, with no
	// handler.
	//
	// The last argument is App's next_file(), which runs before each
	// before-file after the first, with 'before', and before the handler,
	// with 'handler'. It returns false once a file has called error(), or a
	// before-file has set a redirect or error status, and then the files
	// after it don't run. When no route matches, it returns false in the
	// handler's place.
	public static function files( App $tillkite, array $params ) : void {
		if ( \func_get_arg( 2 ) !== '' && ( require \func_get_arg( 2 ) ) === false ) {
			return;
		}

		if ( \func_get_arg( 3 ) !== '' && ( ! \func_get_arg( 6 )( 'before' ) || ( require \func_get_arg( 3 ) ) === false ) ) {
			return;
		}

		if ( \func_get_arg( 4 ) !== '' && ( ! \func_get_arg( 6 )( 'before' ) || ( require \func_get_arg( 4 ) ) === false ) ) {
			return;
		}

		if ( \func_get_arg( 6 )( 'handler' ) ) {
			require \func_get_arg( 5 );
		}
	}

	// Runs the 500 file, with the $params of the files that were running, and
	// the $error that stopped them.
	public static function error_file( App $tillkite, array $params, \Throwable $error ) : void {
		require \func_get_arg( 3 );
	}

	// Runs a template, with $data as App::template() was given it. Its output
	// goes to the buffer that template() started.
	public static function template( App $tillkite, array $data ) : void {
		require \func_get_arg( 2 );
	}
}
