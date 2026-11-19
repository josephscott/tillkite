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

	// Custom parameter regexes that can't match a /: word characters, \d, \w,
	// \. and \-, classes of those (ranges between letters and digits never
	// include /), negated classes that list /, quantifiers, | and (?:...).
	// Anything else is treated as able to match a /.
	private const string SEGMENT_ONLY = '{^(?:[A-Za-z0-9_|*+?,]|\\\\[dw.-]|\{\d+(?:,\d*)?\}|\(\?:|\)|\[(?:[A-Za-z0-9_]-[A-Za-z0-9_]|[A-Za-z0-9_.]|\\\\[dw.-]|-(?=\]))+\]|\[\^[^\]\\\\]*/[^\]\\\\]*\])+$}D';

	public bool $trailing_slash = false;

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

	/**
	 * Returns the compiled tables:
	 *
	 * - routes:  each route as written
	 * - static:  method => [ path => the match: [ 'status' => 200, ... ] ]
	 * - dynamic: method => [ regex => [ key => the match, with the parameter
	 *            names where their values go ] ], where the key is the
	 *            regex's number of matches
	 *
	 * A route for '*' is added to every method's table, and also gets a '*'
	 * table for methods that have no routes of their own.
	 *
	 * Routes are numbered by their place in the list, starting at 0.
	 */
	public function compile( array $routes ) : array {
		$this->problems = [];
		$this->entries = [];
		$this->names = [];
		$this->entry_problems = [];

		$compiled = [
			'routes' => [],
			'static' => [],
			'dynamic' => [],
		];

		$allowed = $this->check_settings();
		if ( ! \array_is_list( $routes ) ) {
			$this->problems[] = 'The routes file must return a list of routes, without keys.';
			return $compiled;
		}

		$this->list_routes( $routes );
		$parsed = [];
		foreach ( \array_keys( $this->entries ) as $index ) {
			$entry = $this->parse_route( $index, $allowed );
			if ( $entry !== null ) {
				$parsed[$index] = $entry;
				$compiled['routes'][$index] = $entry['route'];
			}
		}

		foreach ( $this->table_keys( $parsed, $allowed ) as $table ) {
			$static = [];
			$dynamic = [];
			foreach ( $parsed as $index => $entry ) {
				if ( ! $this->applies( $entry['methods'], $table ) ) {
					continue;
				}

				foreach ( $entry['variants'] as $variant ) {
					if ( $variant['regex'] === '' ) {
						$static[$variant['path']] ??= $index;
						continue;
					}

					// Each value is a match, so the key is one more than the
					// number of parameters.
					$regex = '{^' . $variant['regex'] . '$}sD';
					$dynamic[$regex] ??= [ \count( $variant['names'] ) + 1 => [ 'status' => 200, 'route' => $index, 'params' => $variant['names'] ] ];
				}
			}

			$compiled['static'][$table] = $static;
			$compiled['dynamic'][$table] = $dynamic;
		}

		\ksort( $this->entry_problems );
		foreach ( $this->entry_problems as $name => $messages ) {
			$start = $this->names[$name][0] . ' ' . $this->export( $this->names[$name][1] ) . ': ';
			foreach ( $messages as $message ) {
				$this->problems[] = $start . $message;
			}
		}

		// A static route's match is always the same, so it's stored ready to
		// return. Coming from OPcache, it costs nothing to build.
		foreach ( $compiled['static'] as $table => $paths ) {
			foreach ( $paths as $path => $index ) {
				$compiled['static'][$table][$path] = [ 'status' => 200, 'route' => $index, 'params' => [] ];
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

	// Returns the variants, or a problem message. Each
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

		$split = $this->split_variants( $tokens );
		if ( \is_string( $split ) ) {
			return $split;
		}

		$variants = [];
		foreach ( $split as $variant_tokens ) {
			$variant = $this->compile_variant( $variant_tokens );
			$error = $this->slash_error( $variant_tokens, $variant['source'] );
			if ( $error !== '' ) {
				return $error;
			}

			$variants[] = $variant;
		}

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

	// Returns [ 'source', 'path', 'regex', 'pieces', 'names' ]. A static
	// variant has a path and an empty regex. A dynamic one has a regex, the
	// same regex split into pieces, and its parameter names.
	private function compile_variant( array $tokens ) : array {
		$source = '';
		$path = '';
		$names = [];
		foreach ( $tokens as $token ) {
			if ( $token['type'] === 'literal' ) {
				$source .= $token['text'];
				$path .= $this->encode( $token['text'] );
				continue;
			}

			$names[] = $token['name'];
			$source .= $token['source'];
		}

		$variant = [
			'source' => $source,
			'path' => $path,
			'regex' => '',
			'pieces' => [],
			'names' => $names,
		];

		if ( $names === [] ) {
			return $variant;
		}

		$pieces = $this->pieces( $tokens );
		$variant['path'] = '';
		$variant['regex'] = \implode( '', \array_column( $pieces, 'regex' ) );
		$variant['pieces'] = $pieces;
		return $variant;
	}

	// Splits a dynamic variant's regex at each / in its literals, so routes
	// that start the same way can share their first pieces. Each piece is:
	//
	// - regex:   its part of the regex
	// - text:    the literal text it starts with
	// - literal: true when it has no parameters
	// - fixed:   true when none of its parameters can match a /, so it
	//            matches exactly one path segment
	private function pieces( array $tokens ) : array {
		$pieces = [];
		$piece = null;
		$last = \count( $tokens ) - 1;
		foreach ( $tokens as $i => $token ) {
			if ( $token['type'] === 'param' ) {
				$piece['regex'] .= $this->param_regex( $token, $tokens[$i + 1] ?? null, $i === $last );
				$piece['literal'] = false;
				$piece['fixed'] = $piece['fixed'] && $this->segment_only( $token['regex'] );
				continue;
			}

			// A pattern starts with a literal /, so the first chunk is empty
			// before the first piece exists.
			foreach ( \explode( '/', $this->encode( $token['text'] ) ) as $n => $chunk ) {
				if ( $n > 0 ) {
					if ( $piece !== null ) {
						$pieces[] = $piece;
					}

					$piece = [ 'regex' => '/', 'text' => '/', 'literal' => true, 'fixed' => true ];
				}

				if ( $piece === null ) {
					continue;
				}

				$piece['regex'] .= \preg_quote( $chunk );
				if ( $piece['literal'] ) {
					$piece['text'] .= $chunk;
				}
			}
		}

		$pieces[] = $piece;
		return $pieces;
	}

	// The default [^/]+ is possessive when a / or the end comes next, so PCRE
	// never backtracks into it. When URLs never end in /, a last parameter
	// whose regex allows / (like a catch-all) must not match one at the end.
	private function param_regex( array $token, ?array $next, bool $is_last ) : string {
		$regex = '(' . $token['regex'] . ')';
		$closes = $next === null || ( $next['type'] === 'literal' && \str_starts_with( $next['text'], '/' ) );
		if ( $token['regex'] === self::DEFAULT_REGEX && $closes ) {
			$regex = '([^/]++)';
		}

		if ( $is_last && ! $this->trailing_slash && ! $this->segment_only( $token['regex'] ) ) {
			$regex .= '(?<!/)';
		}

		return $regex;
	}

	private function segment_only( string $regex ) : bool {
		if ( $regex === self::DEFAULT_REGEX || \preg_match( self::SEGMENT_ONLY, $regex ) === 1 ) {
			return true;
		}

		return false;
	}

	// Returns a problem message when the variant doesn't follow trailing_slash.
	private function slash_error( array $tokens, string $source ) : string {
		$error = '';
		if ( $source === '/' ) {
			return $error;
		}

		$last = $tokens[\count( $tokens ) - 1];
		$ends_with_slash = false;
		if ( $last['type'] === 'literal' && \str_ends_with( $last['text'], '/' ) ) {
			$ends_with_slash = true;
		}

		if ( ! $this->trailing_slash && $ends_with_slash ) {
			$error = "'" . $source . "' ends with /, but trailing_slash is false";
		}

		if ( $this->trailing_slash && ! $ends_with_slash && ! $this->last_segment_has_dot( $tokens ) ) {
			$error = "'" . $source . "' must end with /, because trailing_slash is true";
		}

		return $error;
	}

	// With trailing_slash on, a last segment with a literal dot, like
	// /feed.xml, can leave off the slash.
	private function last_segment_has_dot( array $tokens ) : bool {
		for ( $i = \count( $tokens ) - 1; $i >= 0; $i-- ) {
			if ( $tokens[$i]['type'] !== 'literal' ) {
				continue;
			}

			$text = $tokens[$i]['text'];
			$slash = \strrpos( $text, '/' );
			if ( $slash !== false ) {
				$text = \substr( $text, $slash + 1 );
			}

			if ( \str_contains( $text, '.' ) ) {
				return true;
			}

			if ( $slash !== false ) {
				return false;
			}
		}

		return false;
	}

	// Browsers send non-ASCII characters percent-encoded, with uppercase hex.
	private function encode( string $text ) : string {
		$encoded = (string) \preg_replace_callback(
			'/[\x80-\xFF]+/',
			fn ( $matches ) => \rawurlencode( $matches[0] ),
			$text
		);
		return $encoded;
	}

	// Returns the tables to build: each method used by a route, in the order
	// of the allowed list, then '*' if any route uses it.
	private function table_keys( array $parsed, array $allowed ) : array {
		$used = [];
		foreach ( $parsed as $entry ) {
			foreach ( $entry['methods'] as $method ) {
				$used[$method] = true;
			}
		}

		$keys = [];
		foreach ( $allowed as $method ) {
			if ( isset( $used[$method] ) ) {
				$keys[] = $method;
			}
		}

		if ( isset( $used['*'] ) ) {
			$keys[] = '*';
		}

		return $keys;
	}

	private function applies( array $methods, string $table ) : bool {
		if ( $methods === [ '*' ] || \in_array( $table, $methods, true ) ) {
			return true;
		}

		return false;
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
