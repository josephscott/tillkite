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
	 * - [ 'status' => 405, 'allow' => [ 'GET', 'HEAD' ] ]
	 * - [ 'status' => 308, 'path' => '/users' ]
	 * - [ 'status' => 404 ]
	 * - [ 'status' => 500, 'error' => 'Backtrack limit exhausted' ]
	 *
	 * A 404, 405 or 308 for a path under a group's prefix also has 'group',
	 * the group's key in groups, and 'params', its prefix's parameters, like
	 * [ 'status' => 404, 'group' => 0, 'params' => [ 'id' => '42' ] ].
	 *
	 * The usual cases, a static or dynamic route that matches, are written out
	 * here instead of calling find(), because every function call costs time.
	 * find_dynamic() is the same code.
	 */
	public function match( string $method, string $path ) : array {
		$result = $this->compiled['static'][$method][$path] ?? null;
		if ( $result !== null ) {
			return $result;
		}

		// Every method with routes has a dynamic table, even an empty one. A
		// method without uses the '*' table, if there is one.
		$table = $method;
		$regexes = $this->compiled['dynamic'][$method] ?? null;
		if ( $regexes === null ) {
			$table = '*';
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
					if ( $result['status'] === 404 && $this->compiled['prefixes'] !== '' ) {
						$result = $this->group( $result, $path );
					}
				}

				return $result;
			}

			if ( $matched === false ) {
				$result = [ 'status' => 500, 'error' => \preg_last_error_msg() ];
				return $result;
			}
		}

		// Only a miss looks for the group it's under, so a match costs
		// nothing more.
		$result = $this->miss( $table, $path );
		if ( $result['status'] !== 500 && $this->compiled['prefixes'] !== '' ) {
			$result = $this->group( $result, $path );
		}

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

	// Adds the group whose prefix the path is under, if there is one, to a
	// miss. When the regex fails, the miss is a 500, so a group's
	// before-files are never skipped. A prefix whose parameter fails its
	// check isn't one the path is under, as if the value weren't encoded.
	private function group( array $result, string $path ) : array {
		$matched = \preg_match( $this->compiled['prefixes'], $path, $matches );
		if ( $matched === false ) {
			$result = [ 'status' => 500, 'error' => \preg_last_error_msg() ];
			return $result;
		}

		if ( $matched === 0 ) {
			return $result;
		}

		$key = (int) $matches['MARK'];
		$params = [];
		foreach ( $this->compiled['groups'][$key]['names'] as $i => $name ) {
			$params[$name] = $matches[$i + 1];
		}

		$decoded = $this->decoded( $params, $this->compiled['groups'][$key]['checks'] );
		if ( $decoded['status'] === 500 ) {
			return $decoded;
		}

		if ( $decoded['status'] === 404 ) {
			return $result;
		}

		$result['group'] = $key;
		$result['params'] = $decoded['params'];
		return $result;
	}

	// Nothing matched. The first check, across every method and with or
	// without the trailing slash, settles most misses. Only a path that some
	// route matches needs the checks for a 405 or a 308.
	private function miss( string $table, string $path ) : array {
		$result = [ 'status' => 404 ];
		if ( ! \str_starts_with( $path, '/' ) || ! $this->anywhere( $path ) ) {
			return $result;
		}

		$result = $this->allowed( $table, $path );
		if ( $result['status'] !== 404 ) {
			return $result;
		}

		$result = $this->redirect( $table, $path );
		return $result;
	}

	// Whether any route, for any method, matches the path, or the path with
	// its trailing slash added or removed. It may say yes when none does, but
	// never no when one does. The regexes end in /?$, so matching them
	// against the path with one trailing slash covers both.
	private function anywhere( string $path ) : bool {
		$base = $path;
		if ( $path !== '/' && \str_ends_with( $path, '/' ) ) {
			$base = \substr( $path, 0, -1 );
		}

		$subject = $base . '/';
		if ( $path === '/' ) {
			$subject = $path;
		}

		if ( isset( $this->compiled['any']['static'][$base] ) || isset( $this->compiled['any']['static'][$subject] ) ) {
			return true;
		}

		foreach ( $this->compiled['any']['dynamic'] as $regex => $leaves ) {
			// A PCRE error is a yes, so the full checks report it.
			if ( \preg_match( $regex, $subject ) !== 0 ) {
				return true;
			}
		}

		return false;
	}

	// The static table is checked first. Compiler has already removed any
	// static path that an earlier dynamic route matches.
	private function find( string $table, string $path ) : array {
		$result = $this->compiled['static'][$table][$path] ?? $this->find_dynamic( $table, $path );
		return $result;
	}

	// Looks for routes with other methods that match the path, for a 405.
	private function allowed( string $table, string $path ) : array {
		$allow = [];
		foreach ( \array_keys( $this->compiled['static'] ) as $other ) {
			if ( $other === '*' || $other === $table ) {
				continue;
			}

			$found = $this->find( $other, $path );
			if ( $found['status'] === 500 ) {
				return $found;
			}

			if ( $found['status'] === 200 ) {
				$allow[] = $other;
			}
		}

		$result = [ 'status' => 404 ];
		if ( $allow !== [] ) {
			$result = [ 'status' => 405, 'allow' => $allow ];
		}

		return $result;
	}

	// Adds or removes the trailing slash, and redirects if that matches.
	private function redirect( string $table, string $path ) : array {
		$result = [ 'status' => 404 ];
		if ( $path === '/' ) {
			return $result;
		}

		$other = $path . '/';
		if ( \str_ends_with( $path, '/' ) ) {
			$other = \substr( $path, 0, -1 );
		}

		$found = $this->find( $table, $other );
		if ( $found['status'] === 500 ) {
			$result = $found;
		} elseif ( $found['status'] === 200 ) {
			$result = [ 'status' => 308, 'path' => $other ];
		}

		return $result;
	}
}
