<?php
declare( strict_types = 1 );

namespace Tillkite;

/**
 * Checks the route list and compiles it into the tables that Matcher uses.
 *
 * Nothing is thrown. Every problem found is added to $problems as a message,
 * and the caller decides what to do with them.
 *
 * @internal
 */
final class Compiler {
	public const array STANDARD_METHODS = [ 'GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'CONNECT', 'TRACE' ];

	public const string DEFAULT_REGEX = '[^/]+';

	public array $methods = [];

	public string $handlers = '';

	public ?string $before = null;

	public array $problems = [];

	// Every route: [ 'route' => as written, 'label' => like '3', 'name' => its
	// key in $names ].
	private array $entries = [];

	// What each route's problems start with, in the order of the routes
	// file: [ 'Route 4', the route ]. They're exported only for a problem.
	private array $names = [];

	// The problems found, by their key in $names.
	private array $entry_problems = [];

	private bool $check_files = true;

	// What regex_error() found for each parameter regex, since most routes
	// share a few, like the default.
	private array $regex_errors = [];

	public function compile( array $routes ) : array {
		$this->problems = [];
		$this->entries = [];
		$this->names = [];
		$this->entry_problems = [];

		$compiled = [
			'routes' => [],
		];

		$allowed = $this->check_settings();
		if ( ! \array_is_list( $routes ) ) {
			$this->problems[] = 'The routes file must return a list of routes, without keys.';
			return $compiled;
		}

		$this->list_routes( $routes );
		foreach ( \array_keys( $this->entries ) as $index ) {
			$entry = $this->parse_route( $index, $allowed );
			if ( $entry !== null ) {
				$compiled['routes'][$index] = $entry['route'];
			}
		}

		\ksort( $this->entry_problems );
		foreach ( $this->entry_problems as $name => $messages ) {
			$start = $this->names[$name][0] . ' ' . $this->export( $this->names[$name][1] ) . ': ';
			foreach ( $messages as $message ) {
				$this->problems[] = $start . $message;
			}
		}

		return $compiled;
	}

	// Checks the settings, and returns every allowed method. Without a
	// handlers directory, no file can be found, so only that is reported, not
	// each file that's missing.
	private function check_settings() : array {
		$this->check_files = true;
		if ( ! \is_dir( $this->handlers ) ) {
			$this->problems[] = "The handlers directory doesn't exist: " . $this->handlers;
			$this->check_files = false;
		}

		if ( $this->before !== null && ! $this->file_exists( $this->before ) ) {
			$this->problems[] = "The before file doesn't exist: " . $this->path( $this->before );
		}

		$allowed = self::STANDARD_METHODS;
		foreach ( $this->methods as $method ) {
			if ( ! \is_string( $method ) || \preg_match( '/^[A-Z][A-Z0-9_-]*$/D', $method ) !== 1 ) {
				$this->problems[] = 'Custom methods must be uppercase, like PURGE: ' . $this->export( $method );
				continue;
			}

			if ( ! \in_array( $method, $allowed, true ) ) {
				$allowed[] = $method;
			}
		}

		return $allowed;
	}

	private function list_routes( array $routes ) : void {
		foreach ( $routes as $position => $route ) {
			$this->add_entry( (string) ( $position + 1 ), $route );
		}
	}

	private function add_entry( string $label, mixed $route ) : void {
		$this->names[] = [ 'Route ' . $label, $route ];
		$this->entries[] = [
			'route' => $route,
			'label' => $label,
			'name' => \array_key_last( $this->names ),
		];
	}

	// Returns [ 'methods' => as parse_methods() returns them, 'variants' =>
	// as parse_pattern() returns them, 'route' => as written ], or null when
	// the route has problems.
	private function parse_route( int $index, array $allowed ) : ?array {
		$route = $this->entries[$index]['route'];
		if ( ! \is_array( $route ) || ! \array_is_list( $route ) || \count( $route ) < 3 || \count( $route ) > 4 ) {
			$this->route_problem( $index, 'each route must be [ method, pattern, file ], with optional route options' );
			return null;
		}

		$errors = [];
		$methods = $this->parse_methods( $route[0], $allowed );
		if ( \is_string( $methods ) ) {
			$errors[] = $methods;
		}

		$pattern = 'the pattern must be a string';
		if ( \is_string( $route[1] ) ) {
			$pattern = $this->parse_pattern( $route[1] );
		}

		if ( \is_string( $pattern ) ) {
			$errors[] = $pattern;
		}

		$file = $route[2];
		if ( ! \is_string( $file ) || $file === '' ) {
			$errors[] = 'the file must be a file name';
		} elseif ( ! $this->file_exists( $file ) ) {
			$errors[] = "the file doesn't exist: " . $this->path( $file );
		}

		foreach ( $errors as $error ) {
			$this->route_problem( $index, $error );
		}

		if ( $errors !== [] ) {
			return null;
		}

		$parsed = [
			'methods' => $methods,
			'variants' => $pattern,
			'route' => $route,
		];
		return $parsed;
	}

	// Returns the sorted list of methods, or a problem message. A GET route
	// also answers HEAD, so GET brings HEAD with it.
	private function parse_methods( mixed $value, array $allowed ) : array|string {
		$list = $value;
		if ( \is_string( $value ) ) {
			$list = [ $value ];
		}

		if ( ! \is_array( $list ) || $list === [] || ! \array_is_list( $list ) ) {
			return 'the method must be a string or a list of strings';
		}

		if ( $list === [ '*' ] ) {
			return $list;
		}

		$methods = [];
		foreach ( $list as $method ) {
			$error = $this->method_error( $method, $allowed );
			if ( $error !== '' ) {
				return $error;
			}

			$methods[] = $method;
			if ( $method === 'GET' ) {
				$methods[] = 'HEAD';
			}
		}

		$methods = \array_values( \array_unique( $methods ) );
		\sort( $methods );
		return $methods;
	}

	private function method_error( mixed $method, array $allowed ) : string {
		$error = '';
		if ( ! \is_string( $method ) ) {
			$error = 'the method must be a string or a list of strings';
		} elseif ( $method === '*' ) {
			$error = "'*' can't be combined with other methods";
		} elseif ( \strtoupper( $method ) !== $method ) {
			$error = "methods must be uppercase: use '" . \strtoupper( $method ) . "', not '" . $method . "'";
		} elseif ( ! \in_array( $method, $allowed, true ) ) {
			$error = "unknown method '" . $method . "' (add custom methods with the methods option)";
		}

		return $error;
	}

	// Returns the token list for each variant, or a problem message. Each
	// optional part adds a variant: /blog[/{page}] has the variants /blog and
	// /blog/{page}.
	private function parse_pattern( string $pattern ) : array|string {
		if ( ! \str_starts_with( $pattern, '/' ) ) {
			return 'the pattern must start with /';
		}

		$tokens = $this->tokenize( $pattern );
		if ( \is_string( $tokens ) ) {
			return $tokens;
		}

		$names = [];
		foreach ( $tokens as $token ) {
			if ( $token['type'] !== 'param' ) {
				continue;
			}

			if ( \in_array( $token['name'], $names, true ) ) {
				return "duplicate parameter name '" . $token['name'] . "'";
			}

			$names[] = $token['name'];
		}

		$variants = $this->split_variants( $tokens );
		return $variants;
	}

	// Splits a pattern into literal, param, [ and ] tokens, or returns a
	// problem message.
	private function tokenize( string $pattern ) : array|string {
		$tokens = [];
		$literal = '';
		$length = \strlen( $pattern );
		$i = 0;
		while ( $i < $length ) {
			$char = $pattern[$i];
			if ( $char === '}' ) {
				return 'unbalanced { } in the pattern';
			}

			if ( $char !== '{' && $char !== '[' && $char !== ']' ) {
				$literal .= $char;
				$i++;
				continue;
			}

			if ( $literal !== '' ) {
				$tokens[] = [ 'type' => 'literal', 'text' => $literal ];
				$literal = '';
			}

			if ( $char !== '{' ) {
				$tokens[] = [ 'type' => $char ];
				$i++;
				continue;
			}

			$end = $this->closing_brace( $pattern, $i );
			if ( $end === -1 ) {
				return 'unbalanced { } in the pattern';
			}

			$param = $this->parse_param( \substr( $pattern, $i + 1, $end - $i - 1 ) );
			if ( \is_string( $param ) ) {
				return $param;
			}

			$tokens[] = $param;
			$i = $end + 1;
		}

		if ( $literal !== '' ) {
			$tokens[] = [ 'type' => 'literal', 'text' => $literal ];
		}

		return $tokens;
	}

	// Returns the position of the } that closes the { at $start, or -1.
	// Braces inside the parameter's regex are counted, and escaped ones skipped.
	private function closing_brace( string $pattern, int $start ) : int {
		$depth = 0;
		$length = \strlen( $pattern );
		for ( $i = $start; $i < $length; $i++ ) {
			$char = $pattern[$i];
			if ( $char === '\\' ) {
				$i++;
				continue;
			}

			if ( $char === '{' ) {
				$depth++;
			}

			if ( $char === '}' ) {
				$depth--;
			}

			if ( $depth === 0 ) {
				return $i;
			}
		}

		return -1;
	}

	// Parses the inside of {name} or {name:regex}.
	private function parse_param( string $body ) : array|string {
		$name = $body;
		$regex = self::DEFAULT_REGEX;
		$colon = \strpos( $body, ':' );
		if ( $colon !== false ) {
			$name = \substr( $body, 0, $colon );
			$regex = \substr( $body, $colon + 1 );
		}

		if ( $name === '' ) {
			return 'empty parameter name in {' . $body . '}';
		}

		if ( \preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name ) !== 1 ) {
			return "invalid parameter name '" . $name . "' (use letters, digits and _)";
		}

		if ( $regex === '' ) {
			return 'empty regex in {' . $body . '}';
		}

		$error = $this->regex_error( $regex );
		if ( $error !== '' ) {
			return 'the regex in {' . $body . '} ' . $error;
		}

		$param = [
			'type' => 'param',
			'name' => $name,
			'regex' => $regex,
			'source' => '{' . $body . '}',
		];
		return $param;
	}

	// Returns the token list for each variant, shortest first, or a problem
	// message. Optional parts have to be at the end, and can be nested.
	private function split_variants( array $tokens ) : array|string {
		$variants = [];
		$current = [];
		$opened = 0;
		$closed = 0;
		$previous = '';
		foreach ( $tokens as $token ) {
			$type = $token['type'];
			if ( $closed > 0 && $type !== ']' ) {
				return 'optional parts must be at the end of the pattern';
			}

			if ( ( $type === '[' || $type === ']' ) && $previous === '[' ) {
				return 'empty optional part [ ] in the pattern';
			}

			$previous = $type;
			if ( $type === '[' ) {
				$variants[] = $current;
				$opened++;
				continue;
			}

			if ( $type === ']' ) {
				$closed++;
				if ( $closed > $opened ) {
					return 'unbalanced [ ] in the pattern';
				}

				continue;
			}

			// Join literals that meet across an optional part, so /blog[/{page}]
			// gives one literal, /blog/, before {page}.
			$last = \array_key_last( $current );
			if ( $type === 'literal' && $last !== null && $current[$last]['type'] === 'literal' ) {
				$current[$last]['text'] .= $token['text'];
				continue;
			}

			$current[] = $token;
		}

		if ( $opened !== $closed ) {
			return 'unbalanced [ ] in the pattern';
		}

		$variants[] = $current;
		return $variants;
	}

	// The regex has to compile on its own, so it can't break out of the group
	// it's placed in, and it can't add capturing groups of its own. Nor can
	// it use a verb like (*ACCEPT), (*COMMIT) or (*MARK): the matcher tells
	// which route matched by its groups or its MARK, and the routes after it
	// have to stay in play. Escapes and character classes are masked first,
	// so \(* and [(*] aren't taken for verbs.
	private function regex_error( string $regex ) : string {
		if ( isset( $this->regex_errors[$regex] ) ) {
			$error = $this->regex_errors[$regex];
			return $error;
		}

		$matches = [];
		$error = $this->compile_error( '{' . $regex . '}' );
		if ( $error === '' ) {
			$error = $this->compile_error( '{(' . $regex . ')|}', $matches );
		}

		if ( $error !== '' ) {
			$error = "doesn't compile: " . $error;
		} elseif ( \count( $matches ) !== 2 ) {
			$error = 'has a capturing group; use (?:...) instead';
		} elseif ( \str_contains( (string) \preg_replace( [ '{\\\\.}s', '{\[\^?\]?[^\]]*\]}' ], 'x', $regex ), '(*' ) ) {
			$error = "has a (*...) verb, which parameters can't use";
		}

		$this->regex_errors[$regex] = $error;
		return $error;
	}

	// Checks that a regex compiles by matching it against ''. Returns '' when
	// it does, or PCRE's message when it doesn't. A temporary error handler
	// takes PCRE's warning, so it never reaches the app's own error handler.
	// With PREG_UNMATCHED_AS_NULL, $matches has an entry for every group.
	private function compile_error( string $regex, array &$matches = [] ) : string {
		$error = '';
		\set_error_handler( function( int $number, string $message ) use ( &$error ) : bool {
			$error = \str_replace( 'preg_match(): ', '', $message );
			return true;
		} );
		$result = \preg_match( $regex, '', $matches, \PREG_UNMATCHED_AS_NULL );
		\restore_error_handler();

		if ( $result === false && $error === '' ) {
			$error = \preg_last_error_msg();
		}

		return $error;
	}

	private function route_problem( int $index, string $message ) : void {
		$this->entry_problem( $this->entries[$index]['name'], $message );
	}

	// A route's regex goes into several tables, so the same problem can come
	// up more than once.
	private function entry_problem( int $name, string $message ) : void {
		if ( ! \in_array( $message, $this->entry_problems[$name] ?? [], true ) ) {
			$this->entry_problems[$name][] = $message;
		}
	}

	private function file_exists( string $file ) : bool {
		if ( ! $this->check_files || \is_file( $this->path( $file ) ) ) {
			return true;
		}

		return false;
	}

	private function path( string $file ) : string {
		$path = $this->handlers . '/' . $file;
		return $path;
	}

	// Shows a route entry the way it's written in the routes file. Strings
	// aren't escaped, so a regex reads the same as it does there.
	private function export( mixed $value ) : string {
		if ( \is_string( $value ) ) {
			$exported = "'" . $value . "'";
			return $exported;
		}

		if ( \is_object( $value ) ) {
			$exported = \get_debug_type( $value );
			return $exported;
		}

		if ( ! \is_array( $value ) ) {
			$exported = \strtolower( \var_export( $value, true ) );
			return $exported;
		}

		$parts = [];
		$is_list = \array_is_list( $value );
		foreach ( $value as $key => $item ) {
			$part = $this->export( $item );
			if ( ! $is_list ) {
				$part = $this->export( $key ) . ' => ' . $part;
			}

			$parts[] = $part;
		}

		$exported = '[]';
		if ( $parts !== [] ) {
			$exported = '[ ' . \implode( ', ', $parts ) . ' ]';
		}

		return $exported;
	}
}
