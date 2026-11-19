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

				$result['params'] = $params;
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
}
