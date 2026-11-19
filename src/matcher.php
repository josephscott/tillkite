<?php
declare( strict_types = 1 );

namespace Tillkite;

/**
 * Finds the route that matches a request, using the tables built by Compiler.
 *
 * @internal
 */
final class Matcher {
	// One property, because each one costs time to set on every request.
	public function __construct( private array $compiled ) {}

	/**
	 * Matches a request. $path is the raw path, without the query string or
	 * base. Returns one of:
	 *
	 * - [ 'status' => 200, 'route' => 3, 'params' => [ 'id' => '42' ] ]
	 * - [ 'status' => 404 ]
	 * - [ 'status' => 500, 'error' => 'Backtrack limit exhausted' ]
	 */
	public function match( string $method, string $path ) : array {
		$result = $this->compiled['static'][$method][$path] ?? null;
		if ( $result !== null ) {
			return $result;
		}

		// Every method with routes has a dynamic table, even an empty one. A
		// method without uses the '*' table, if there is one.
		$regexes = $this->compiled['dynamic'][$method] ?? null;
		if ( $regexes === null ) {
			$result = $this->compiled['static']['*'][$path] ?? null;
			if ( $result !== null ) {
				return $result;
			}

			$regexes = $this->compiled['dynamic']['*'] ?? [];
		}

		foreach ( $regexes as $regex => $leaves ) {
			$matched = \preg_match( $regex, $path, $matches );
			if ( $matched === 1 ) {
				// A leaf is the match, with the parameter names where their
				// values go, found by its MARK or by the number of matches.
				$result = $leaves[$matches['MARK'] ?? \count( $matches )];
				$params = [];
				foreach ( $result['params'] as $i => $name ) {
					$params[$name] = $matches[$i + 1];
				}

				// Only a path with a % has anything to decode, only one with ..
				// can have a .. segment, which no parameter takes, and only one
				// with a \ can have one in {name}, which doesn't take it.
				$result['params'] = $params;
				if ( \strpbrk( $path, '%\\' ) !== false || \str_contains( $path, '..' ) ) {
					$result = $this->decode( $result );
				}

				return $result;
			}

			if ( $matched === false ) {
				$result = [ 'status' => 500, 'error' => \preg_last_error_msg() ];
				return $result;
			}
		}

		$result = [ 'status' => 404 ];
		return $result;
	}

	// Checks only the dynamic routes in one table, the same way match() does.
	// Compiler uses this to find static routes that an earlier dynamic route
	// hides.
	public function find_dynamic( string $table, string $path ) : array {
		$result = [ 'status' => 404 ];
		foreach ( $this->compiled['dynamic'][$table] ?? [] as $regex => $leaves ) {
			$matched = \preg_match( $regex, $path, $matches );
			if ( $matched === 1 ) {
				$result = $leaves[$matches['MARK'] ?? \count( $matches )];
				$params = [];
				foreach ( $result['params'] as $i => $name ) {
					$params[$name] = $matches[$i + 1];
				}

				$result['params'] = $params;
				$result = $this->decode( $result );
				break;
			}

			if ( $matched === false ) {
				$result = [ 'status' => 500, 'error' => \preg_last_error_msg() ];
				break;
			}
		}

		return $result;
	}

	// Returns a route's match with its parameters decoded, or a 404 when one
	// fails its check, or a 500 when PCRE fails. So a route never gets a value
	// that its regex would turn away, but the routes after it aren't tried,
	// because PCRE has already chosen between them.
	private function decode( array $result ) : array {
		$decoded = $this->decoded( $result['params'], $this->compiled['checks'][$result['route']] ?? [] );
		if ( $decoded['status'] !== 200 ) {
			return $decoded;
		}

		$result['params'] = $decoded['params'];
		return $result;
	}

	// Decodes parameters, and checks each one that was encoded. One with a
	// regex of its own has to match the regex for it in $checks, unless
	// that's '', for a regex written for the encoded form. See
	// Compiler::checks(). One without has the default regex, which takes one
	// path segment, so its value can't decode to a /, which would make it
	// more than one in a file path, or have a \, which Windows reads as a /,
	// encoded or not. A browser sends a \ as a /, but nginx passes on a raw
	// one, which [^/]+ would take.
	//
	// No parameter, whatever its regex, takes a value with a .. segment,
	// between / or \ or at either end, encoded or not: in a file path it
	// leaves the directory, and a browser takes .. segments out of a URL
	// before it sends it, so only a request made by hand has one. It's the
	// check that App::template() makes of a template's name. Nor does one
	// take a null byte, which no URL has a use for: PHP's file functions throw
	// for one, and code in C that a value is handed to stops reading there.
	// It comes only as %00, since web servers turn away a request with a raw
	// one.
	// Returns [ 'status' => 200, 'params' => [ ... ] ], or a 404 or a 500.
	private function decoded( array $params, array $checks ) : array {
		foreach ( $params as $name => $value ) {
			$params[$name] = \rawurldecode( $value );
			if ( \str_contains( $params[$name], "\0" ) || ( \str_contains( $params[$name], '..' ) && \preg_match( '{(?:^|[/\\\\])\.\.(?:[/\\\\]|$)}', $params[$name] ) === 1 ) ) {
				$result = [ 'status' => 404 ];
				return $result;
			}

			$check = $checks[$name] ?? null;
			if ( $check === null && \strpbrk( $params[$name], '/\\' ) !== false ) {
				$result = [ 'status' => 404 ];
				return $result;
			}

			if ( $params[$name] === $value ) {
				continue;
			}

			if ( $check === null || $check === '' ) {
				continue;
			}

			$matched = \preg_match( $check, $params[$name] );
			if ( $matched === false ) {
				$result = [ 'status' => 500, 'error' => \preg_last_error_msg() ];
				return $result;
			}

			if ( $matched === 0 ) {
				$result = [ 'status' => 404 ];
				return $result;
			}
		}

		$result = [ 'status' => 200, 'params' => $params ];
		return $result;
	}
}
