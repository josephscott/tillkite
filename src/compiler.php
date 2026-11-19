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

	public const array ROUTE_OPTIONS = [ 'buffer', 'before', 'before_only' ];

	public const array GROUP_KEYS = [ 'prefix', 'routes', 'buffer', 'before', 'before_only', 'errors' ];

	// A regex with up to this many routes tells them apart by the number of
	// matches instead of a MARK. See leaf_ends().
	public const int COUNT_LEAVES = 12;

	// Custom parameter regexes that can't match a /: word characters, \d, \w,
	// \. and \-, classes of those (ranges between letters and digits never
	// include /), negated classes that list /, quantifiers, | and (?:...).
	// Anything else is treated as able to match a /.
	private const string SEGMENT_ONLY = '{^(?:[A-Za-z0-9_|*+?,]|\\\\[dw.-]|\{\d+(?:,\d*)?\}|\(\?:|\)|\[(?:[A-Za-z0-9_]-[A-Za-z0-9_]|[A-Za-z0-9_.]|\\\\[dw.-]|-(?=\]))+\]|\[\^[^\]\\\\]*/[^\]\\\\]*\])+$}D';

	public bool $trailing_slash = false;

	public array $methods = [];

	public string $handlers = '';

	public ?string $before = null;

	public array $errors = [];

	public array $problems = [];

	// Every route, with each group's routes in the group's place:
	// [ 'route' => as written, 'label' => like '3' or '4.2', 'name' => its key
	// in $names, 'prefix' => its group's or '', 'chain' => the before-files
	// that run ahead of its own (see add_before()), 'buffer' => its group's
	// buffer option or null, 'group' => its group's key in $groups or null ].
	private array $entries = [];

	// Each group, in order: [ the group as compile() returns it in groups,
	// the regex for a path under its prefix, its number of segments ].
	private array $groups = [];

	// What each route's or group's problems start with, in the order of the
	// routes file: [ 'Route 4.2', the route ] or [ 'Group 4', the group
	// without its routes ]. They're exported only for a problem.
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
	 * - routes:  each route as route() returns it: as written, with its
	 *            group's prefix joined to its pattern
	 * - checks:  for each route with parameters that have a regex of their
	 *            own, [ name => the regex a decoded value has to match too,
	 *            or '' for none ]. See checks().
	 * - static:  method => [ path => the match: [ 'status' => 200, ... ] ]
	 * - dynamic: method => [ regex => [ key => the match, with the parameter
	 *            names where their values go ] ], where the key is the
	 *            regex's MARK or its number of matches
	 * - any:     [ 'static' => ..., 'dynamic' => ... ] for every route of
	 *            every method, matching with or without a trailing slash
	 * - default_files: what a route runs that has no before or buffer
	 *            options and isn't in a group: the paths of its before-files
	 *            in the order they run, with '' for each one fewer than
	 *            three, then its buffer option, or null for the app's, then
	 *            its group's key in groups, or null
	 * - files:   the same for each route that runs something else, or is in
	 *            a group
	 * - groups:  for each group, [ 'prefix' => as written, 'errors' => as
	 *            written, or [], 'files' => what a miss under its prefix
	 *            runs, which is what a route in it with no options would,
	 *            'names' => the names of its prefix's parameters, 'checks' =>
	 *            their checks, as above ]
	 * - prefixes: a regex for a path under a group's prefix, whose MARK is
	 *            the first such group's key in groups, and whose matches are
	 *            the prefix's parameters, or '' when there are no groups
	 *
	 * A route for '*' is added to every method's table, and also gets a '*'
	 * table for methods that have no routes of their own.
	 *
	 * Routes are numbered by their place in the list, starting at 0, with
	 * each group's routes in the group's place.
	 */
	public function compile( array $routes ) : array {
		$this->problems = [];
		$this->entries = [];
		$this->names = [];
		$this->entry_problems = [];
		$this->groups = [];

		$compiled = [
			'routes' => [],
			'checks' => [],
			'static' => [],
			'dynamic' => [],
			'any' => [ 'static' => [], 'dynamic' => [] ],
			'default_files' => [ '', '', '', null, null ],
			'files' => [],
			'groups' => [],
			'prefixes' => '',
		];

		$allowed = $this->check_settings();
		if ( ! \array_is_list( $routes ) ) {
			$this->problems[] = 'The routes file must return a list of routes, without keys.';
			return $compiled;
		}

		// Most routes run only the app's before-file, so only the others take
		// room in the cache.
		if ( $this->before !== null ) {
			$compiled['default_files'][0] = $this->path( $this->before );
		}

		$this->list_routes( $routes );
		$parsed = [];
		foreach ( \array_keys( $this->entries ) as $index ) {
			$entry = $this->parse_route( $index, $allowed );
			if ( $entry !== null ) {
				$parsed[$index] = $entry;
				$compiled['routes'][$index] = $entry['route'];
				$checks = \array_merge( ...\array_column( $entry['variants'], 'checks' ) );
				if ( $checks !== [] ) {
					$compiled['checks'][$index] = $checks;
				}

				if ( $entry['files'] !== $compiled['default_files'] ) {
					$compiled['files'][$index] = $entry['files'];
				}
			}
		}

		// PCRE takes the first alternative that matches. A path under more
		// than one group's prefix, like /api/admin/x under /api and
		// /api/admin, is under the one with the most segments, so its
		// before-files run for misses under it whatever the order of the
		// list. Prefixes with as many segments, like /{lang} and /en, keep
		// their order. In (?|...), each alternative's matches are numbered
		// from 1.
		$alternatives = [];
		$segments = [];
		foreach ( $this->groups as $key => [ $group, $regex, $count ] ) {
			$compiled['groups'][] = $group;
			$alternatives[$key] = $regex . '(*:' . $key . ')';
			$segments[$key] = $count;
		}

		\uksort( $alternatives, static fn ( int $a, int $b ) : int => $segments[$b] <=> $segments[$a] );
		if ( $alternatives !== [] ) {
			$compiled['prefixes'] = '{^(?|' . \implode( '|', $alternatives ) . ')}D';
		}

		$blocked = [];
		$reachable = [];
		$built_items = [];
		$built_tables = [];
		foreach ( $this->table_keys( $parsed, $allowed ) as $table ) {
			$static = [];
			$items = [];
			$seen = [];
			foreach ( $parsed as $index => $entry ) {
				if ( ! $this->applies( $entry['methods'], $table ) ) {
					continue;
				}

				foreach ( $entry['variants'] as $variant ) {
					if ( $variant['regex'] === '' ) {
						$path = $variant['path'];
						if ( isset( $static[$path] ) ) {
							$blocked[$index][$static[$path]] ??= $table;
							continue;
						}

						$static[$path] = $index;
						continue;
					}

					// Best effort for dynamic routes: only an identical regex is
					// known to match the same requests.
					$regex = $variant['regex'];
					if ( isset( $seen[$regex] ) ) {
						$blocked[$index][$seen[$regex]] ??= $table;
						continue;
					}

					$seen[$regex] = $index;
					$items[] = [ $index, $variant['pieces'], $variant['names'] ];
					$reachable[$index] = true;
				}
			}

			// HEAD usually has the same routes as GET, so it reuses its regexes.
			// array_search() compares the items in less time than serialize()
			// takes to make a key of them.
			$built = \array_search( $items, $built_items, true );
			if ( $built === false ) {
				$built = \count( $built_tables );
				$built_items[] = $items;
				$built_tables[] = $this->combine( $items, false );
			}

			$compiled['static'][$table] = $static;
			$compiled['dynamic'][$table] = $built_tables[$built];
		}

		// Every route of every method, for the matcher's first check after a
		// miss. Its regexes end in /?$ instead of $, so a path ending in / also
		// finds the routes for the path without it.
		$items = [];
		$seen = [];
		foreach ( $parsed as $index => $entry ) {
			foreach ( $entry['variants'] as $variant ) {
				if ( $variant['regex'] === '' ) {
					$compiled['any']['static'][$variant['path']] ??= $index;
				} elseif ( ! isset( $seen[$variant['regex']] ) ) {
					$seen[$variant['regex']] = true;
					$items[] = [ $index, $variant['pieces'], $variant['names'] ];
				}
			}
		}

		$compiled['any']['dynamic'] = $this->combine( $items, true );

		// The matcher checks the static table first, so a static path that an
		// earlier dynamic route already matches must come out of it. Otherwise
		// the later static route would win. It has the checks too, so a path
		// whose value the dynamic route turns away once it's decoded, like
		// /files/a%2Fb for /files/{name}, stays.
		$matcher = new Matcher( [ 'dynamic' => $this->check_copies( $compiled['dynamic'] ), 'checks' => $compiled['checks'] ] );
		foreach ( $compiled['static'] as $table => $paths ) {
			foreach ( $paths as $path => $index ) {
				$found = $matcher->find_dynamic( $table, (string) $path );
				if ( $found['status'] === 200 && $found['route'] < $index ) {
					unset( $compiled['static'][$table][$path] );
					$blocked[$index][$found['route']] ??= $table;
					continue;
				}

				$reachable[$index] = true;
			}
		}

		foreach ( $parsed as $index => $entry ) {
			if ( ! isset( $reachable[$index] ) ) {
				$this->route_problem( $index, $this->blocked_message( $index, $blocked[$index], $parsed ) );
			}
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

		foreach ( $this->error_problems( $this->errors ) as $problem ) {
			$this->problems[] = \ucfirst( $problem );
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

	// Checks the error files, the app's or a group's.
	private function error_problems( mixed $errors ) : array {
		if ( ! \is_array( $errors ) ) {
			return [ "errors must be an array, like [ 404 => '404.php' ]" ];
		}

		$problems = [];
		foreach ( $errors as $code => $file ) {
			if ( ! isset( App::REASONS[$code] ) ) {
				$problems[] = 'unknown error code in errors: ' . $code . '. Use 500, or a standard 4xx code like 404 or 403';
			} elseif ( ! \is_string( $file ) || $file === '' ) {
				$problems[] = 'the ' . $code . ' error file must be a file name';
			} elseif ( ! $this->file_exists( $file ) ) {
				$problems[] = 'the ' . $code . " error file doesn't exist: " . $this->path( $file );
			}
		}

		return $problems;
	}

	// Fills $entries with every route, putting each group's routes in the
	// group's place, and checks the groups. A group with problems leaves out
	// its routes, so they don't each repeat its problem.
	private function list_routes( array $routes ) : void {
		$chain = [];
		if ( $this->before !== null ) {
			$chain[] = [ $this->before, "the app's" ];
		}

		foreach ( $routes as $position => $route ) {
			$label = (string) ( $position + 1 );
			if ( ! $this->is_group( $route ) ) {
				$this->add_entry( $label, $route, '', $chain, null, null );
				continue;
			}

			$group = $this->parse_group( $label, $route, $chain );
			if ( $group === null ) {
				continue;
			}

			foreach ( $route['routes'] as $inner => $inner_route ) {
				$inner_label = $label . '.' . ( $inner + 1 );
				if ( $this->is_group( $inner_route ) ) {
					$this->names[] = [ 'Group ' . $inner_label, $this->shown_group( $inner_route ) ];
					$this->entry_problem( \array_key_last( $this->names ), "a group can't be inside another group" );
					continue;
				}

				$this->add_entry( $inner_label, $inner_route, $route['prefix'], $group['chain'], $group['buffer'], $group['key'] );
			}
		}
	}

	// A group has a prefix or routes key. A route is a list, so it has
	// neither.
	private function is_group( mixed $entry ) : bool {
		$group = \is_array( $entry ) && ( \array_key_exists( 'prefix', $entry ) || \array_key_exists( 'routes', $entry ) );
		return $group;
	}

	// A group as its problems show it, without its routes.
	private function shown_group( array $group ) : array {
		unset( $group['routes'] );
		return $group;
	}

	private function add_entry( string $label, mixed $route, string $prefix, array $chain, ?bool $buffer, ?int $group ) : void {
		$this->names[] = [ 'Route ' . $label, $route ];
		$this->entries[] = [
			'route' => $route,
			'label' => $label,
			'name' => \array_key_last( $this->names ),
			'prefix' => $prefix,
			'chain' => $chain,
			'buffer' => $buffer,
			'group' => $group,
		];
	}

	// Returns [ 'chain' => the before-files that run ahead of each route's
	// own, 'buffer' => the group's buffer option or null, 'key' => its key in
	// $groups ], or null when the group has problems.
	private function parse_group( string $label, array $group, array $chain ) : ?array {
		$this->names[] = [ 'Group ' . $label, $this->shown_group( $group ) ];
		$name = \array_key_last( $this->names );

		$errors = [];
		$prefix = $this->parse_prefix( $group['prefix'] ?? null );
		if ( \is_string( $prefix ) ) {
			$errors[] = $prefix;
		}

		if ( ! \is_array( $group['routes'] ?? null ) || ! \array_is_list( $group['routes'] ) ) {
			$errors[] = 'routes must be a list of routes';
		}

		foreach ( $group as $key => $value ) {
			$error = '';
			if ( ! \in_array( $key, self::GROUP_KEYS, true ) ) {
				$error = "unknown group key '" . $key . "'";
			} elseif ( \in_array( $key, self::ROUTE_OPTIONS, true ) ) {
				$error = $this->option_error( $key, $value );
			}

			if ( $error !== '' ) {
				$errors[] = $error;
			}
		}

		if ( isset( $group['before'], $group['before_only'] ) ) {
			$errors[] = 'use before or before_only, not both';
		}

		if ( \array_key_exists( 'errors', $group ) ) {
			\array_push( $errors, ...$this->error_problems( $group['errors'] ) );
		}

		if ( $errors === [] ) {
			$added = $this->add_before( $chain, $group, "the group's" );
			if ( \is_string( $added ) ) {
				$errors[] = $added;
			} else {
				$chain = $added;
			}
		}

		foreach ( $errors as $error ) {
			$this->entry_problem( $name, $error );
		}

		if ( $errors !== [] ) {
			return null;
		}

		$key = \count( $this->groups );
		$buffer = $group['buffer'] ?? null;
		[ $regex, $names, $segments, $checks ] = $prefix;
		$compiled = [
			'prefix' => $group['prefix'],
			'errors' => $group['errors'] ?? [],
			'files' => $this->files( $chain, $buffer, $key ),
			'names' => $names,
			'checks' => $checks,
		];
		$this->groups[] = [ $compiled, $regex, $segments ];
		$parsed = [ 'chain' => $chain, 'buffer' => $buffer, 'key' => $key ];
		return $parsed;
	}

	// Returns [ the regex for a path that the prefix starts, which is the
	// prefix, then a / or the end, the names of its parameters, its number of
	// segments, its checks ], or a problem message. Each parameter is a match.
	//
	// The prefix is joined to each of the group's patterns, which are
	// checked then. Here it only has to be a pattern on its own, without
	// optional parts, which go at the end of a pattern.
	private function parse_prefix( mixed $prefix ) : array|string {
		if ( ! \is_string( $prefix ) || ! \str_starts_with( $prefix, '/' ) || \str_ends_with( $prefix, '/' ) ) {
			return "the prefix must start with / and not end with /, like '/admin'";
		}

		$tokens = $this->tokenize( $prefix );
		if ( \is_string( $tokens ) ) {
			return $tokens;
		}

		$regex = '';
		$names = [];
		$segments = 0;
		foreach ( $tokens as $token ) {
			if ( $token['type'] === '[' || $token['type'] === ']' ) {
				return "the prefix can't have optional parts";
			}

			if ( $token['type'] === 'literal' ) {
				$regex .= \preg_quote( $this->encode( $token['text'] ) );
				$segments += \substr_count( $token['text'], '/' );
			} else {
				$regex .= '(' . $token['regex'] . ')';
				$names[] = $token['name'];
			}
		}

		$regex .= '(?:/|$)';
		return [ $regex, $names, $segments, $this->checks( $tokens ) ];
	}

	// Returns the before-files that run for one level, the group's or the
	// route's, from those of the levels above it and its own options.
	// 'before' adds a file after them, 'before_only' runs a file in their
	// place, and 'before' => false runs none. Each is [ file, whose it is ],
	// starting with [ the app's before file, "the app's" ]. Returns a problem
	// message when the level's before file runs already.
	private function add_before( array $chain, array $options, string $whose ) : array|string {
		if ( isset( $options['before_only'] ) ) {
			$chain = [ [ $options['before_only'], $whose ] ];
			return $chain;
		}

		$before = $options['before'] ?? null;
		if ( $before === false ) {
			$chain = [];
			return $chain;
		}

		if ( $before === null ) {
			return $chain;
		}

		foreach ( $chain as [ $file, $file_whose ] ) {
			if ( $this->same_file( $before, $file ) ) {
				$error = 'the before file is ' . $file_whose . ' before file too, so it would run twice';
				return $error;
			}
		}

		$chain[] = [ $before, $whose ];
		return $chain;
	}

	// Returns [ 'methods' => as parse_methods() returns them, 'variants' =>
	// as parse_pattern() returns them, 'route' => as route() returns it,
	// 'files' => what it runs, as compile() describes ], or null when the
	// route has problems.
	private function parse_route( int $index, array $allowed ) : ?array {
		$entry = $this->entries[$index];
		$route = $entry['route'];
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
		if ( \is_string( $route[1] ) && ! $this->fits_prefix( $entry['prefix'], $route[1] ) ) {
			$pattern = "in a group, the pattern must start with / or [, or be '' for the prefix by itself";
		} elseif ( \is_string( $route[1] ) ) {
			$pattern = $this->parse_pattern( $entry['prefix'] . $route[1] );
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

		$options = $route[3] ?? [];
		if ( ! \is_array( $options ) || ( $options !== [] && \array_is_list( $options ) ) ) {
			$errors[] = "route options must be an array, like [ 'buffer' => false ]";
			$options = [];
		}

		$options_valid = true;
		foreach ( $options as $key => $value ) {
			$error = $this->option_error( $key, $value );
			if ( $error !== '' ) {
				$errors[] = $error;
				$options_valid = false;
			}
		}

		if ( isset( $options['before'], $options['before_only'] ) ) {
			$errors[] = 'use before or before_only, not both';
			$options_valid = false;
		}

		$chain = $entry['chain'];
		if ( $options_valid ) {
			$added = $this->add_before( $chain, $options, "the route's" );
			if ( \is_string( $added ) ) {
				$errors[] = $added;
			} else {
				$chain = $added;
			}
		}

		foreach ( $errors as $error ) {
			$this->route_problem( $index, $error );
		}

		if ( $errors !== [] ) {
			return null;
		}

		$route[1] = $entry['prefix'] . $route[1];
		$parsed = [
			'methods' => $methods,
			'variants' => $pattern,
			'route' => $route,
			'files' => $this->files( $chain, $options['buffer'] ?? $entry['buffer'], $entry['group'] ),
		];
		return $parsed;
	}

	// What a route runs, as compile() describes it: the paths of the
	// before-files in $chain, with '' for each one fewer than three, its
	// buffer option and its group's key in groups.
	private function files( array $chain, ?bool $buffer, ?int $group ) : array {
		$files = [ '', '', '', $buffer, $group ];
		foreach ( $chain as $position => [ $before ] ) {
			$files[$position] = $this->path( $before );
		}

		return $files;
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

	private function option_error( int|string $key, mixed $value ) : string {
		$error = '';
		if ( ! \in_array( $key, self::ROUTE_OPTIONS, true ) ) {
			$error = "unknown route option '" . $key . "'";
		} elseif ( $key === 'buffer' && ! \is_bool( $value ) ) {
			$error = 'the buffer option must be true or false';
		} elseif ( $key === 'before' && $value !== false && ( ! \is_string( $value ) || $value === '' ) ) {
			$error = 'the before option must be a file name or false';
		} elseif ( $key === 'before' && \is_string( $value ) && ! $this->file_exists( $value ) ) {
			$error = "the before file doesn't exist: " . $this->path( $value );
		} elseif ( $key === 'before_only' && ( ! \is_string( $value ) || $value === '' ) ) {
			$error = "the before_only option must be a file name. For no before-file, use 'before' => false";
		} elseif ( $key === 'before_only' && ! $this->file_exists( $value ) ) {
			$error = "the before_only file doesn't exist: " . $this->path( $value );
		}

		return $error;
	}

	// In a group, a pattern goes on after the prefix: with a /, with an
	// optional part, or not at all.
	private function fits_prefix( string $prefix, string $pattern ) : bool {
		$fits = $prefix === '' || $pattern === '' || \str_starts_with( $pattern, '/' ) || \str_starts_with( $pattern, '[' );
		return $fits;
	}

	// Whether two file names, relative to handlers, are the same file.
	private function same_file( string $a, string $b ) : bool {
		$same = $a === $b;
		if ( ! $same ) {
			$real = \realpath( $this->path( $a ) );
			$same = $real !== false && $real === \realpath( $this->path( $b ) );
		}

		return $same;
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

	// Returns [ 'source', 'path', 'regex', 'pieces', 'names', 'checks' ]. A
	// static variant has a path and an empty regex. A dynamic one has a regex,
	// the same regex split into pieces, its parameter names and their checks.
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
			'checks' => $this->checks( $tokens ),
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

	// The regexes that a parameter's value has to match decoded as well, by
	// its name, for Matcher::decoded(). A route's regex sees the value still
	// encoded, where %2E isn't a dot, so [^./]+ would let %2E%2E through.
	//
	// The default regex is left out, and the matcher knows a parameter
	// without a check for one: it takes one segment, so the matcher turns
	// away a value that decodes to more, which needs no regex in the cache
	// for the parameters most routes have. A regex that names %, like
	// (?:[a-z]|%[0-9A-F]{2})+, was written for the encoded form, and wouldn't
	// match the decoded value, so its check is '', for none at all.
	private function checks( array $tokens ) : array {
		$checks = [];
		foreach ( $tokens as $token ) {
			if ( $token['type'] !== 'param' || $token['regex'] === self::DEFAULT_REGEX ) {
				continue;
			}

			$checks[$token['name']] = '';
			if ( ! \str_contains( $token['regex'], '%' ) ) {
				$checks[$token['name']] = '{^(?:' . $token['regex'] . ')$}sD';
			}
		}

		return $checks;
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

	// Joins a table's [ route index, pieces, names ] items, in route order,
	// into one regex, with routes that start with the same pieces sharing
	// them, and returns [ regex => leaves ]. $any is for the any table, whose
	// regexes only say whether something matches. When PCRE says the regex is
	// too large, splits the list in half and tries again.
	private function combine( array $items, bool $any ) : array {
		$regexes = [];
		if ( $items === [] ) {
			return $regexes;
		}

		$tree = [ 'fixed' => true, 'children' => [], 'last' => [], 'variants' => [] ];
		foreach ( $items as [ $index, $pieces, $names ] ) {
			$this->insert( $tree, $pieces, 0, [ 'status' => 200, 'route' => $index, 'params' => $names ], $any );
		}

		[ $ends, $leaves ] = $this->leaf_ends( $this->leaves( $tree ), $any );
		$position = 0;
		$combined = '{^' . $this->render( $tree, $ends, $position, ! $any ) . '}sD';

		$error = $this->compile_error( $this->check_copy( $combined ) );
		if ( $error === '' ) {
			$regexes = [ $combined => $leaves ];
			return $regexes;
		}

		// PCRE's offset is into the joined regex, not the route's pattern, so
		// it's left out. Some PCRE versions give the method's table and the any
		// table different offsets, which would report the route twice.
		if ( \count( $items ) === 1 ) {
			$this->route_problem( $items[0][0], "the pattern doesn't compile: " . \preg_replace( '/ at offset \d+$/D', '', $error ) );
			return $regexes;
		}

		$half = \intdiv( \count( $items ) + 1, 2 );
		$regexes = \array_merge(
			$this->combine( \array_slice( $items, 0, $half ), $any ),
			$this->combine( \array_slice( $items, $half ), $any )
		);
		return $regexes;
	}

	// The tree's leaves in the order PCRE tries them.
	private function leaves( array $node ) : array {
		$leaves = [];
		foreach ( $node['children'] as $child ) {
			if ( $child['piece'] === null ) {
				$leaves[] = $child['leaf'];
				continue;
			}

			\array_push( $leaves, ...$this->leaves( $child ) );
		}

		return $leaves;
	}

	// Returns [ ends, leaves ]: what ends each leaf's regex, and the leaves
	// by the key the matcher finds them with.
	//
	// A regex with few leaves pads each one with empty groups, so the number
	// of matches tells them apart, like FastRoute's GroupCountBased. That's
	// cheaper than a MARK, which PHP adds to the matches as a new string.
	// With more leaves, the padding would cost more than the MARK saves.
	private function leaf_ends( array $leaves, bool $any ) : array {
		$ends = [];
		$keys = [];
		if ( $any ) {
			$ends = \array_fill( 0, \count( $leaves ), '/?$' );
			$result = [ $ends, $keys ];
			return $result;
		}

		if ( \count( $leaves ) > self::COUNT_LEAVES ) {
			foreach ( $leaves as $position => $leaf ) {
				$ends[] = '$(*:' . $position . ')';
				$keys[$position] = $leaf;
			}

			$result = [ $ends, $keys ];
			return $result;
		}

		// Each leaf gets the smallest number of groups that's at least its
		// parameter count and more than any leaf before it, fewest first.
		$order = \array_keys( $leaves );
		\usort( $order, fn ( $a, $b ) => \count( $leaves[$a]['params'] ) <=> \count( $leaves[$b]['params'] ) );
		$groups = 0;
		foreach ( $order as $position ) {
			$params = \count( $leaves[$position]['params'] );
			$groups = \max( $params, $groups + 1 );
			$ends[$position] = '$' . \str_repeat( '()', $groups - $params );
			$keys[$groups + 1] = $leaves[$position];
		}

		\ksort( $ends );
		\ksort( $keys );
		$result = [ $ends, $keys ];
		return $result;
	}

	// Adds a route's pieces to the tree, one level per piece, ending in its
	// leaf. PCRE tries a node's children in order, so a route goes under the
	// last child with the same piece only when it can't match a path that any
	// later child matches; otherwise it starts a new child at the end.
	//
	// Only a fixed child is shared. When a shared piece can end in more than
	// one place, like /(.+), PCRE would try the later route's rest before the
	// earlier route's other ways to match it, so the later route could win.
	// Below a piece like that there's only ever one route.
	//
	// The any table only says whether something matches, so order doesn't
	// matter there, and every route shares as much as it can.
	private function insert( array &$node, array $pieces, int $depth, array $leaf, bool $any ) : void {
		$node['variants'][] = $pieces;
		if ( $depth === \count( $pieces ) ) {
			$node['children'][] = [ 'piece' => null, 'leaf' => $leaf ];
			return;
		}

		$piece = $pieces[$depth];
		$position = $node['last'][$piece['regex']] ?? null;
		if ( $position !== null && ! $any && ! $this->can_skip( $node, $position + 1, $pieces, $depth ) ) {
			$position = null;
		}

		if ( $position === null ) {
			$position = \count( $node['children'] );
			$fixed = $node['fixed'] && $piece['fixed'];
			$node['children'][] = [
				'piece' => $piece,
				'fixed' => $fixed,
				'children' => [],
				'last' => [],
				'variants' => [],
			];

			if ( $fixed || $any ) {
				$node['last'][$piece['regex']] = $position;
			}
		}

		$this->insert( $node['children'][$position], $pieces, $depth + 1, $leaf, $any );
	}

	// Whether a route can go ahead of the node's children from $start on: no
	// path may match both it and any route under them.
	private function can_skip( array $node, int $start, array $pieces, int $depth ) : bool {
		$count = \count( $node['children'] );
		for ( $i = $start; $i < $count; $i++ ) {
			$child = $node['children'][$i];

			// Everything before this level matched one segment per piece, so a
			// route that ends here matches fewer segments than this one, and a
			// child whose piece can't match the same segment holds no route
			// that can match the same path.
			if ( $node['fixed'] && ( $child['piece'] === null || $this->different_segments( $child['piece'], $pieces[$depth] ) ) ) {
				continue;
			}

			if ( $child['piece'] === null ) {
				return false;
			}

			foreach ( $child['variants'] as $other ) {
				if ( ! $this->disjoint( $other, $pieces ) ) {
					return false;
				}
			}
		}

		return true;
	}

	// Whether no path can match both lists of pieces. It only answers true
	// when it's sure: it compares the pieces in step while both match exactly
	// one segment each.
	private function disjoint( array $a, array $b ) : bool {
		$count = \min( \count( $a ), \count( $b ) );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! $a[$i]['fixed'] || ! $b[$i]['fixed'] ) {
				return false;
			}

			if ( $this->different_segments( $a[$i], $b[$i] ) ) {
				return true;
			}
		}

		// Each piece starts with a /. The shorter list matches exactly one
		// segment per piece, and the longer one needs more segments than that.
		if ( \count( $a ) !== \count( $b ) ) {
			return true;
		}

		return false;
	}

	// Whether two fixed pieces can't match the same segment: both are plain
	// text and differ, or the text they start with differs.
	private function different_segments( array $a, array $b ) : bool {
		if ( ! $a['fixed'] || ! $b['fixed'] ) {
			return false;
		}

		if ( $a['literal'] && $b['literal'] ) {
			if ( $a['text'] !== $b['text'] ) {
				return true;
			}

			return false;
		}

		$length = \min( \strlen( $a['text'] ), \strlen( $b['text'] ) );
		if ( \strncmp( $a['text'], $b['text'], $length ) !== 0 ) {
			return true;
		}

		return false;
	}

	// Writes a tree node as a regex. $position counts the leaves written so
	// far, and $ordered is false for the any table. Each child becomes an
	// alternative: [ 'text' => its literal text, 'rest' => the regex after
	// that, its children included, 'literal' => true when it has no
	// parameters ]. A leaf has no text and a rest of its end.
	private function render( array $node, array $ends, int &$position, bool $ordered ) : string {
		$alternatives = [];
		foreach ( $node['children'] as $child ) {
			if ( $child['piece'] === null ) {
				$alternatives[] = [ 'text' => '', 'rest' => $ends[$position], 'literal' => true ];
				$position++;
				continue;
			}

			$piece = $child['piece'];
			$rest = \substr( $piece['regex'], \strlen( \preg_quote( $piece['text'] ) ) );
			$alternatives[] = [
				'text' => $piece['text'],
				'rest' => $rest . $this->render( $child, $ends, $position, $ordered ),
				'literal' => $piece['literal'],
			];
		}

		$regex = $this->alternation( $alternatives, 0, $ordered );
		return $regex;
	}

	// Writes alternatives whose text up to $offset is already matched, with
	// the ones that go on with the same character sharing it, so PCRE tries
	// one alternative per character instead of all of them:
	// /a(?|ccounts...|pps...)|/balance... instead of /accounts...|/apps...|/balance...
	//
	// PCRE tries alternatives in order, and that order decides which route
	// wins. Moving one ahead of others is safe only when no path can match
	// both, which is the case when their text differs at $offset. An
	// alternative whose text runs out before a parameter can match anything
	// there, so nothing moves past it. A leaf, or plain text that runs out,
	// is next followed by / or the end, which no other text has there.
	private function alternation( array $alternatives, int $offset, bool $ordered ) : string {
		$groups = [];
		$open = [];
		foreach ( $alternatives as $alternative ) {
			$key = 'end';
			if ( \strlen( $alternative['text'] ) > $offset ) {
				$key = $alternative['text'][$offset];
			} elseif ( ! $alternative['literal'] ) {
				$key = 'param';
			}

			if ( $key === 'param' && $ordered ) {
				$groups[] = [ $alternative ];
				$open = [];
				continue;
			}

			if ( isset( $open[$key] ) ) {
				$groups[$open[$key]][] = $alternative;
				continue;
			}

			$open[$key] = \count( $groups );
			$groups[] = [ $alternative ];
		}

		$regexes = [];
		foreach ( $groups as $group ) {
			$regexes[] = $this->group_regex( $group, $offset, $ordered );
		}

		$regex = $regexes[0];
		if ( \count( $regexes ) > 1 ) {
			$regex = '(?|' . \implode( '|', $regexes ) . ')';
		}

		return $regex;
	}

	// A group's shared text after $offset, then its alternatives.
	private function group_regex( array $group, int $offset, bool $ordered ) : string {
		$first = $group[0]['text'];
		if ( \count( $group ) === 1 ) {
			$regex = \preg_quote( \substr( $first, $offset ) ) . $group[0]['rest'];
			return $regex;
		}

		$length = \strlen( $first ) - $offset;
		foreach ( $group as $alternative ) {
			$length = \min( $length, \strlen( $alternative['text'] ) - $offset );
			while ( $length > 0 && \strncmp( \substr( $alternative['text'], $offset ), \substr( $first, $offset ), $length ) !== 0 ) {
				$length--;
			}
		}

		if ( $length > 0 ) {
			$regex = \preg_quote( \substr( $first, $offset, $length ) ) . $this->alternation( $group, $offset + $length, $ordered );
			return $regex;
		}

		$regexes = [];
		foreach ( $group as $alternative ) {
			$regexes[] = \preg_quote( \substr( $alternative['text'], $offset ) ) . $alternative['rest'];
		}

		$regex = '(?|' . \implode( '|', $regexes ) . ')';
		return $regex;
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

	// PHP keeps each compiled regex in a cache, under its string. Once the
	// routes come from the route cache, the matcher's regexes are OPcache's
	// strings, which PHP finds in that cache by their address. If a regex had
	// been run here first, from a string built while compiling, it would stay
	// cached under a copy of that string, and every later match in this
	// process would compare the whole regex with the copy. So the checks here
	// run a copy of each regex that starts with an empty comment, (?#), which
	// matches the same way.
	private function check_copy( string $regex ) : string {
		$copy = '{(?#)' . \substr( $regex, 1 );
		return $copy;
	}

	// Explains why a route never matches. $blockers is [ earlier route index => table ].
	private function blocked_message( int $index, array $blockers, array $parsed ) : string {
		\ksort( $blockers );
		$labels = [];
		foreach ( \array_keys( $blockers ) as $blocker ) {
			$labels[] = $this->entries[$blocker]['label'];
		}

		if ( \count( $labels ) > 1 ) {
			$message = 'never matches, because routes ' . $this->join( $labels ) . ' come first and match the same requests';
			return $message;
		}

		$blocker = \array_key_first( $blockers );
		if (
			$parsed[$blocker]['methods'] === $parsed[$index]['methods']
			&& $parsed[$blocker]['route'][1] === $parsed[$index]['route'][1]
		) {
			$message = 'duplicate of route ' . $labels[0];
			return $message;
		}

		$message = 'never matches, because route ' . $labels[0] . ' comes first and matches the same requests';
		if (
			$blockers[$blocker] === 'HEAD'
			&& ! \in_array( 'GET', $parsed[$index]['methods'], true )
			&& \in_array( 'GET', $parsed[$blocker]['methods'], true )
		) {
			$message .= ' (a GET route also answers HEAD)';
		}

		return $message;
	}

	// [ '1', '2', '4.1' ] => '1, 2 and 4.1'
	private function join( array $labels ) : string {
		$last = \array_pop( $labels );
		$joined = \implode( ', ', $labels ) . ' and ' . $last;
		return $joined;
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

	// The dynamic tables with check_copy() of each regex.
	private function check_copies( array $tables ) : array {
		$copies = [];
		foreach ( $tables as $table => $regexes ) {
			$copies[$table] = [];
			foreach ( $regexes as $regex => $leaves ) {
				$copies[$table][$this->check_copy( $regex )] = $leaves;
			}
		}

		return $copies;
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
