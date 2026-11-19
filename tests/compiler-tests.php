<?php
declare( strict_types = 1 );

describe( 'compiled tables', function() {
	it( 'keeps each route as written, by its number', function() {
		$compiled = compile_routes( [
			[ 'GET', '/', 'page.php' ],
			[ 'POST', '/users', 'page.php', [] ],
		] );

		expect( $compiled['routes'] )->toBe( [ [ 'GET', '/', 'page.php' ], [ 'POST', '/users', 'page.php', [] ] ] );
	} );

	it( 'puts static routes in one hash table per method, ready to return, and GET also answers HEAD', function() {
		$compiled = compile_routes( [
			[ 'GET', '/', 'page.php' ],
			[ 'POST', '/users', 'page.php' ],
		] );

		$match = fn ( int $route ) => [ 'status' => 200, 'route' => $route, 'params' => [] ];
		expect( $compiled['static'] )->toBe( [
			'GET' => [ '/' => $match( 0 ) ],
			'HEAD' => [ '/' => $match( 0 ) ],
			'POST' => [ '/users' => $match( 1 ) ],
		] );
	} );

	it( "adds '*' routes to every table, plus a '*' table of their own", function() {
		$compiled = compile_routes( [
			[ 'GET', '/', 'page.php' ],
			[ '*', '/webhook', 'page.php' ],
		] );

		expect( array_map( 'array_keys', $compiled['static'] ) )->toBe( [
			'GET' => [ '/', '/webhook' ],
			'HEAD' => [ '/', '/webhook' ],
			'*' => [ '/webhook' ],
		] );
	} );

	// /files/a%2Fb decodes to a value with a /, which {name} turns away, so
	// it stays.
	it( 'takes a static path out of its table when an earlier dynamic route matches it', function() {
		$compiler = make_compiler();
		$compiled = $compiler->compile( [
			[ 'GET', '/users/{id}', 'page.php' ],
			[ 'GET', '/users/new', 'page.php' ],
			[ 'GET', '/files/{name}', 'page.php' ],
			[ 'GET', '/files/a%2Fb', 'page.php' ],
		] );

		expect( $compiler->problems )->toHaveCount( 1 );
		expect( array_keys( $compiled['static']['GET'] ) )->toBe( [ '/files/a%2Fb' ] );
	} );

	it( 'puts each static variant of an optional part in the table', function() {
		$compiled = compile_routes( [ [ 'GET', '/blog[/archive[/all]]', 'page.php' ] ] );

		expect( array_keys( $compiled['static']['GET'] ) )->toBe( [ '/blog', '/blog/archive', '/blog/archive/all' ] );
	} );

	// Each route ends in empty groups, so the number of matches says which
	// route matched. Its leaf is the match, with the parameter names where
	// their values go.
	it( 'joins dynamic routes into one regex, told apart by the number of matches', function() {
		$compiled = compile_routes( [
			[ 'GET', '/users/{id:\d+}', 'page.php' ],
			[ 'GET', '/{a}-{b}', 'page.php' ],
			[ 'GET', '/docs/{path:.+}', 'page.php' ],
			[ 'GET', '/café/{id}', 'page.php' ],
		] );

		$leaf = fn ( int $route, array $names ) => [ 'status' => 200, 'route' => $route, 'params' => $names ];
		expect( $compiled['dynamic']['GET'] )->toBe( [
			'{^/(?|users/(\d+)$|([^/]+)\-([^/]++)$()()|docs/(.+)(?<!/)$()|caf%C3%A9/([^/]++)$()())}sD' => [
				2 => $leaf( 0, [ 'id' ] ),
				3 => $leaf( 2, [ 'path' ] ),
				4 => $leaf( 3, [ 'id' ] ),
				5 => $leaf( 1, [ 'a', 'b' ] ),
			],
		] );
	} );

	it( 'keeps a check for each parameter with a regex of its own', function() {
		$compiled = compile_routes( [
			[ 'GET', '/a/{id}', 'page.php' ],
			[ 'GET', '/b/{id:\d+}[/{slug:[a-z-]+}]', 'page.php' ],
			[ 'GET', '/c/{v:(?:[a-z]|%[0-9A-F]{2})+}', 'page.php' ],
			[ 'prefix' => '/t/{t:[^./]+}', 'routes' => [ [ 'GET', '/y', 'page.php' ] ] ],
		] );

		expect( $compiled['checks'] )->toBe( [
			1 => [ 'id' => '{^(?:\d+)$}sD', 'slug' => '{^(?:[a-z-]+)$}sD' ],
			2 => [ 'v' => '' ],
			3 => [ 't' => '{^(?:[^./]+)$}sD' ],
		] );
		expect( $compiled['groups'][0]['checks'] )->toBe( [ 't' => '{^(?:[^./]+)$}sD' ] );
	} );

	it( 'shares how dynamic routes start, even past routes that match other paths', function() {
		$compiled = compile_routes( [
			[ 'GET', '/users/{id:\d+}', 'page.php' ],
			[ 'GET', '/users/{id}/posts', 'page.php' ],
			[ 'GET', '/{lang}/about', 'page.php' ],
			[ 'GET', '/users/{id}/likes', 'page.php' ],
		] );

		expect( array_keys( $compiled['dynamic']['GET'] ) )->toBe( [
			'{^/(?|users/(?|(\d+)$|([^/]++)/(?|posts$()|likes$()()))|([^/]++)/about$()()())}sD',
		] );
	} );

	// /api would share the a, but {a}-{b} can match anything there, so /api
	// stays after it.
	it( 'shares text one character at a time, without moving a route past a parameter', function() {
		$compiled = compile_routes( [
			[ 'GET', '/accounts/{id}', 'page.php' ],
			[ 'GET', '/apps/{id}', 'page.php' ],
			[ 'GET', '/{a}-{b}', 'page.php' ],
			[ 'GET', '/api/{id}', 'page.php' ],
		] );

		expect( array_keys( $compiled['dynamic']['GET'] ) )->toBe( [
			'{^/(?|a(?|ccounts/([^/]++)$|pps/([^/]++)$())|([^/]+)\-([^/]++)$()()|api/([^/]++)$()())}sD',
		] );
	} );

	// The any table only says whether something matches, so every route
	// shares what it can, and each regex ends in /?$.
	it( 'puts every route of every method in the any table', function() {
		$compiled = compile_routes( [
			[ 'GET', '/', 'page.php' ],
			[ 'POST', '/users', 'page.php' ],
			[ 'GET', '/users/{id:\d+}', 'page.php' ],
			[ 'PUT', '/{lang}/about', 'page.php' ],
			[ 'POST', '/users/{id}/posts', 'page.php' ],
		] );

		expect( $compiled['any'] )->toBe( [
			'static' => [ '/' => 0, '/users' => 1 ],
			'dynamic' => [ '{^/(?|users/(?|(\d+)/?$|([^/]++)/posts/?$)|([^/]++)/about/?$)}sD' => [] ],
		] );
	} );

	it( 'tells more routes apart by a MARK', function() {
		$routes = [];
		for ( $i = 0; $i <= Tillkite\Compiler::COUNT_LEAVES; $i++ ) {
			$routes[] = [ 'GET', '/r' . $i . '/{id}', 'page.php' ];
		}

		$compiled = compile_routes( $routes );
		$regex = array_key_first( $compiled['dynamic']['GET'] );
		expect( $regex )->toStartWith( '{^/r(?|0/([^/]++)$(*:0)|1(?|/([^/]++)$(*:1)|0/([^/]++)$(*:10)|' );
		expect( array_column( $compiled['dynamic']['GET'][$regex], 'route' ) )->toBe( range( 0, Tillkite\Compiler::COUNT_LEAVES ) );
	} );

	// A long segment in each route makes a hundred of them too large for
	// one regex, so they're split into several, which a request runs in
	// order.
	it( 'splits routes that are too large for one regex, and keeps their order', function() {
		$long = str_repeat( 'abcdefghij', 40 );
		$routes = [];
		for ( $i = 0; $i < 100; $i++ ) {
			$routes[] = [ 'GET', '/section' . $i . '/' . $long . '/{id:\d+}', 'page.php' ];
		}

		$dynamic = compile_routes( $routes )['dynamic']['GET'];
		expect( count( $dynamic ) )->toBeGreaterThan( 1 );

		$order = [];
		foreach ( $dynamic as $leaves ) {
			array_push( $order, ...array_column( $leaves, 'route' ) );
		}

		expect( $order )->toBe( range( 0, 99 ) );
	} );

	it( 'lets a last parameter that can match a / end with one when trailing_slash is true', function() {
		$compiled = compile_routes( [ [ 'GET', '/docs/{path:.+}/', 'page.php' ] ], [ 'trailing_slash' => true ] );

		expect( array_keys( $compiled['dynamic']['GET'] ) )->toBe( [ '{^/docs/(.+)/$}sD' ] );
	} );

	it( 'stores a non-ASCII literal by its percent-encoded form', function() {
		$compiled = compile_routes( [ [ 'GET', '/café', 'page.php' ] ] );

		expect( array_keys( $compiled['static']['GET'] ) )->toBe( [ '/caf%C3%A9' ] );
	} );
} );

describe( 'problems', function() {
	it( 'shows the route number and the entry as written', function() {
		$problems = route_problems( [
			[ 'GET', '/', 'page.php' ],
			[ 'GET', '/users', 'missing.php', [ 'buffer' => false ] ],
		] );

		expect( $problems )->toBe( [
			"Route 2 [ 'GET', '/users', 'missing.php', [ 'buffer' => false ] ]: the file doesn't exist: " . __DIR__ . '/fixtures/handlers/missing.php',
		] );
	} );

	it( 'reports a malformed route', function( mixed $route, string $message ) {
		$problems = route_problems( [ $route ] );

		expect( $problems )->toHaveCount( 1 );
		expect( $problems[0] )->toEndWith( $message );
	} )->with( [
		'not an array' => [ 'GET /', 'each route must be [ method, pattern, file ], with optional route options' ],
		'too short' => [ [ 'GET', '/' ], 'each route must be [ method, pattern, file ], with optional route options' ],
		'too long' => [ [ 'GET', '/', 'page.php', [], [] ], 'each route must be [ method, pattern, file ], with optional route options' ],
		'keyed' => [ [ 'method' => 'GET', 'pattern' => '/', 'file' => 'page.php' ], 'each route must be [ method, pattern, file ], with optional route options' ],
		'pattern not a string' => [ [ 'GET', 42, 'page.php' ], 'the pattern must be a string' ],
		'empty file' => [ [ 'GET', '/', '' ], 'the file must be a file name' ],
		'options a list' => [ [ 'GET', '/', 'page.php', [ false ] ], "route options must be an array, like [ 'buffer' => false ]" ],
		'unknown option' => [ [ 'GET', '/', 'page.php', [ 'cache' => true ] ], "unknown route option 'cache'" ],
		'buffer not a bool' => [ [ 'GET', '/', 'page.php', [ 'buffer' => 'no' ] ], 'the buffer option must be true or false' ],
		'before not a file' => [ [ 'GET', '/', 'page.php', [ 'before' => true ] ], 'the before option must be a file name or false' ],
		'before missing' => [ [ 'GET', '/', 'page.php', [ 'before' => 'missing.php' ] ], "the before file doesn't exist: " . __DIR__ . '/fixtures/handlers/missing.php' ],
		'before_only false' => [ [ 'GET', '/', 'page.php', [ 'before_only' => false ] ], "the before_only option must be a file name. For no before-file, use 'before' => false" ],
		'before_only missing' => [ [ 'GET', '/', 'page.php', [ 'before_only' => 'missing.php' ] ], "the before_only file doesn't exist: " . __DIR__ . '/fixtures/handlers/missing.php' ],
		'before and before_only' => [ [ 'GET', '/', 'page.php', [ 'before' => 'before.php', 'before_only' => 'before.php' ] ], 'use before or before_only, not both' ],
	] );

	// ./before.php names the same file as before.php.
	it( 'reports a before-file that runs already, so it would run twice', function( array $routes, string $message ) {
		$problems = route_problems( $routes, [ 'before' => 'before.php' ] );

		expect( $problems )->toHaveCount( 1 );
		expect( $problems[0] )->toEndWith( $message );
	} )->with( [
		"a route's that's the app's" => [
			[ [ 'GET', '/', 'page.php', [ 'before' => './before.php' ] ] ],
			"the before file is the app's before file too, so it would run twice",
		],
		"a group's that's the app's" => [
			[ [ 'prefix' => '/a', 'before' => 'before.php', 'routes' => [] ] ],
			"the before file is the app's before file too, so it would run twice",
		],
		"a route's that's its group's" => [
			[ [ 'prefix' => '/a', 'before' => 'group-before.php', 'routes' => [ [ 'GET', '', 'page.php', [ 'before' => 'group-before.php' ] ] ] ] ],
			"the before file is the group's before file too, so it would run twice",
		],
	] );

	it( 'reports a bad method', function( mixed $method, string $message ) {
		$problems = route_problems( [ [ $method, '/', 'page.php' ] ] );

		expect( $problems )->toHaveCount( 1 );
		expect( $problems[0] )->toEndWith( $message );
	} )->with( [
		'lowercase' => [ 'get', "methods must be uppercase: use 'GET', not 'get'" ],
		'unknown' => [ 'FETCH', "unknown method 'FETCH' (add custom methods with the methods option)" ],
		'* with others' => [ [ '*', 'GET' ], "'*' can't be combined with other methods" ],
		'empty list' => [ [], 'the method must be a string or a list of strings' ],
	] );

	it( 'reports a bad pattern', function( string $pattern, string $message ) {
		$problems = route_problems( [ [ 'GET', $pattern, 'page.php' ] ] );

		expect( $problems )->toHaveCount( 1 );
		expect( $problems[0] )->toContain( $message );
	} )->with( [
		'no leading slash' => [ 'users', 'the pattern must start with /' ],
		'unclosed brace' => [ '/users/{id', 'unbalanced { } in the pattern' ],
		'stray brace' => [ '/users/id}', 'unbalanced { } in the pattern' ],
		'unclosed bracket' => [ '/blog[/{page}', 'unbalanced [ ] in the pattern' ],
		'stray bracket' => [ '/blog]', 'unbalanced [ ] in the pattern' ],
		'optional in the middle' => [ '/blog[/{page}]/all', 'optional parts must be at the end of the pattern' ],
		'empty optional' => [ '/blog[]', 'empty optional part [ ] in the pattern' ],
		'empty nested optional' => [ '/blog[/a[]]', 'empty optional part [ ] in the pattern' ],
		'capturing group' => [ '/users/{id:(\d+)}', 'the regex in {id:(\d+)} has a capturing group; use (?:...) instead' ],
		'verb' => [ '/users/{id:\d+(*ACCEPT)}', "the regex in {id:\d+(*ACCEPT)} has a (*...) verb, which parameters can't use" ],
		'invalid regex' => [ '/users/{id:[}', "the regex in {id:[} doesn't compile: Compilation failed: missing terminating ]" ],
		'regex escaping its group' => [ '/users/{id:a)|(?:b}', "the regex in {id:a)|(?:b} doesn't compile: Compilation failed: unmatched closing parenthesis" ],
		'empty regex' => [ '/users/{id:}', 'empty regex in {id:}' ],
		'duplicate name' => [ '/{a}/{a}', "duplicate parameter name 'a'" ],
		'empty name' => [ '/users/{}', 'empty parameter name in {}' ],
		'invalid name' => [ '/users/{1st}', "invalid parameter name '1st' (use letters, digits and _)" ],
		'name with a newline' => [ "/users/{id\n}", "invalid parameter name 'id\n' (use letters, digits and _)" ],
	] );

	it( 'accepts braces, (?:...) and an escaped or bracketed (* in a regex', function() {
		compile_routes( [
			[ 'GET', '/year/{year:\d{4}}', 'page.php' ],
			[ 'GET', '/brace/{b:\{}', 'page.php' ],
			[ 'GET', '/verbish/{v:\(\*[(*]}', 'page.php' ],
			[ 'GET', '/group/{g:(?:a|b)}', 'page.php' ],
		] );
	} );

	it( 'accepts optional parts at the end, nested', function() {
		compile_routes( [ [ 'GET', '/blog[/{year}[/{month}]]', 'page.php' ] ] );
	} );

	it( "reports a route that's too large for a regex on its own", function() {
		$problems = route_problems( [ [ 'GET', '/' . str_repeat( 'a', 70000 ) . '/{id}', 'page.php' ] ] );

		expect( $problems )->toHaveCount( 1 );
		expect( $problems[0] )->toContain( "the pattern doesn't compile: Compilation failed: regular expression is too large" );
	} );

	it( 'reports a trailing slash when trailing_slash is false', function() {
		$problems = route_problems( [
			[ 'GET', '/', 'page.php' ],
			[ 'GET', '/users/', 'page.php' ],
		] );

		expect( $problems )->toHaveCount( 1 );
		expect( $problems[0] )->toEndWith( "'/users/' ends with /, but trailing_slash is false" );
	} );

	it( 'reports a missing trailing slash when trailing_slash is true', function( string $pattern, string $message ) {
		$problems = route_problems( [ [ 'GET', $pattern, 'page.php' ] ], [ 'trailing_slash' => true ] );

		expect( $problems )->toHaveCount( 1 );
		expect( $problems[0] )->toEndWith( $message );
	} )->with( [
		'static' => [ '/users', "'/users' must end with /, because trailing_slash is true" ],
		'optional variant' => [ '/blog[/{page}/]', "'/blog' must end with /, because trailing_slash is true" ],
		'dot in an earlier segment' => [ '/v1.0/users', "'/v1.0/users' must end with /, because trailing_slash is true" ],
	] );

	it( 'lets a last segment with a literal dot leave off the slash when trailing_slash is true', function() {
		compile_routes( [
			[ 'GET', '/', 'page.php' ],
			[ 'GET', '/users/', 'page.php' ],
			[ 'GET', '/feed.xml', 'page.php' ],
			[ 'GET', '/files/{name}.txt', 'page.php' ],
			[ 'GET', '/blog/[{page}/]', 'page.php' ],
		], [ 'trailing_slash' => true ] );
	} );

	it( 'accepts a method, a list, * and custom methods', function() {
		compile_routes( [
			[ 'GET', '/a', 'page.php' ],
			[ [ 'GET', 'POST' ], '/b', 'page.php' ],
			[ '*', '/c', 'page.php' ],
			[ 'PURGE', '/d', 'page.php' ],
		], [ 'methods' => [ 'PURGE' ] ] );
	} );

	it( 'reports a routes list with keys', function() {
		$problems = route_problems( [ 'home' => [ 'GET', '/', 'page.php' ] ] );

		expect( $problems )->toBe( [ 'The routes file must return a list of routes, without keys.' ] );
	} );

	it( 'reports a duplicate route', function() {
		$problems = route_problems( [
			[ [ 'POST', 'GET' ], '/a', 'page.php' ],
			[ [ 'GET', 'POST' ], '/a', 'page.php' ],
		] );

		expect( $problems )->toHaveCount( 1 );
		expect( $problems[0] )->toEndWith( 'duplicate of route 1' );
	} );

	it( 'reports a route that an earlier route hides', function( array $routes, string $message ) {
		$problems = route_problems( $routes );

		expect( $problems )->toHaveCount( 1 );
		expect( $problems[0] )->toEndWith( $message );
	} )->with( [
		'static behind dynamic' => [
			[ [ 'GET', '/users/{id}', 'page.php' ], [ 'GET', '/users/new', 'page.php' ] ],
			'never matches, because route 1 comes first and matches the same requests',
		],
		'static behind the shared group' => [
			[ [ 'GET', '/{lang}/about', 'page.php' ], [ 'GET', '/en/about', 'page.php' ] ],
			'never matches, because route 1 comes first and matches the same requests',
		],
		'dynamic with the same regex' => [
			[ [ 'GET', '/users/{id}', 'page.php' ], [ 'GET', '/users/{name}', 'page.php' ] ],
			'never matches, because route 1 comes first and matches the same requests',
		],
		'HEAD below GET' => [
			[ [ 'GET', '/a', 'page.php' ], [ 'HEAD', '/a', 'page.php' ] ],
			'never matches, because route 1 comes first and matches the same requests (a GET route also answers HEAD)',
		],
		'GET below *' => [
			[ [ '*', '/a', 'page.php' ], [ 'GET', '/a', 'page.php' ] ],
			'never matches, because route 1 comes first and matches the same requests',
		],
		'every method hidden' => [
			[ [ 'GET', '/a', 'page.php' ], [ 'POST', '/a', 'page.php' ], [ [ 'GET', 'POST' ], '/a', 'page.php' ] ],
			'never matches, because routes 1 and 2 come first and match the same requests',
		],
		'every variant hidden' => [
			[ [ 'GET', '/blog', 'page.php' ], [ 'GET', '/blog/{page}', 'page.php' ], [ 'GET', '/blog[/{n}]', 'page.php' ] ],
			'never matches, because routes 1 and 2 come first and match the same requests',
		],
	] );

	it( "doesn't report a route that still matches some requests", function( array $routes ) {
		compile_routes( $routes );
	} )->with( [
		'* below GET' => [ [ [ 'GET', '/a', 'page.php' ], [ '*', '/a', 'page.php' ] ] ],
		'one method still free' => [ [ [ 'GET', '/a', 'page.php' ], [ [ 'GET', 'POST' ], '/a', 'page.php' ] ] ],
		'one variant still free' => [ [ [ 'GET', '/blog', 'page.php' ], [ 'GET', '/blog[/{page}]', 'page.php' ] ] ],
		'narrower regex first' => [ [ [ 'GET', '/users/{id:\d+}', 'page.php' ], [ 'GET', '/users/{name}', 'page.php' ] ] ],
	] );

	it( 'reports bad settings', function( array $settings, string $message ) {
		$problems = route_problems( [], $settings );

		expect( $problems )->toBe( [ $message ] );
	} )->with( [
		'missing handlers directory' => [ [ 'handlers' => '/no/such/dir' ], "The handlers directory doesn't exist: /no/such/dir" ],
		'missing before file' => [ [ 'before' => 'missing.php' ], "The before file doesn't exist: " . __DIR__ . '/fixtures/handlers/missing.php' ],
		'unknown error code' => [ [ 'errors' => [ 418 => '404.php' ] ], 'Unknown error code in errors: 418. Use 500, or a standard 4xx code like 404 or 403' ],
		'error file not a string' => [ [ 'errors' => [ 404 => true ] ], 'The 404 error file must be a file name' ],
		'missing error file' => [ [ 'errors' => [ 500 => 'missing.php' ] ], "The 500 error file doesn't exist: " . __DIR__ . '/fixtures/handlers/missing.php' ],
		'lowercase custom method' => [ [ 'methods' => [ 'purge' ] ], "Custom methods must be uppercase, like PURGE: 'purge'" ],
		'custom method with a newline' => [ [ 'methods' => [ "PURGE\n" ] ], "Custom methods must be uppercase, like PURGE: 'PURGE\n'" ],
		'custom method not a string' => [ [ 'methods' => [ [ 'PURGE', 1 ] ] ], "Custom methods must be uppercase, like PURGE: [ 'PURGE', 1 ]" ],
	] );

	it( 'accepts valid settings', function() {
		compile_routes( [], [
			'before' => 'before.php',
			'errors' => [ 403 => '404.php', 404 => '404.php', 405 => '405.php', 500 => '500.php' ],
			'methods' => [ 'PURGE', 'GET' ],
		] );
	} );
} );

describe( 'groups', function() {
	it( "joins the prefix to each of the group's patterns, and puts its routes in its place", function() {
		$compiled = compile_routes( [
			[ 'GET', '/', 'page.php' ],
			[ 'prefix' => '/users/{id:\d+}', 'routes' => [
				[ 'GET', '', 'page.php' ],
				[ 'GET', '/posts[/{page:\d+}]', 'page.php' ],
			] ],
			[ 'GET', '/about', 'page.php' ],
		] );

		$matcher = new Tillkite\Matcher( $compiled );
		expect( $matcher->match( 'GET', '/users/5' ) )->toBe( [ 'status' => 200, 'route' => 1, 'params' => [ 'id' => '5' ] ] );
		expect( $matcher->match( 'GET', '/users/5/posts/2' ) )->toBe( [ 'status' => 200, 'route' => 2, 'params' => [ 'id' => '5', 'page' => '2' ] ] );
		expect( $matcher->match( 'GET', '/about' )['route'] )->toBe( 3 );
		expect( $compiled['routes'][2] )->toBe( [ 'GET', '/users/{id:\d+}/posts[/{page:\d+}]', 'page.php' ] );
	} );

	// The app's before file is before.php. A route's files are the
	// before-files that run, in order, then the buffer option and its
	// group's key, which the next test covers. A route that runs only the
	// app's, with the app's buffer setting, outside a group, isn't in files.
	it( "works out each route's files from the app's, its group's and its own options", function( array $group, array $options, array $before, ?bool $buffer ) {
		$compiled = compile_routes( [
			[ 'GET', '/', 'page.php' ],
			[ 'prefix' => '/a', 'routes' => [ [ 'GET', '/b', 'page.php', $options ] ] ] + $group,
		], [ 'before' => 'before.php' ] );

		$path = fn ( string $file ) => __DIR__ . '/fixtures/handlers/' . $file;
		expect( $compiled['default_files'] )->toBe( [ $path( 'before.php' ), '', '', null, null ] );
		expect( $compiled['files'] )->toBe( [ 1 => [ ...array_pad( array_map( $path, $before ), 3, '' ), $buffer, 0 ] ] );
	} )->with( [
		"the group's after the app's" => [ [ 'before' => 'group-before.php' ], [], [ 'before.php', 'group-before.php' ], null ],
		"the route's after the group's" => [ [ 'before' => 'group-before.php' ], [ 'before' => 'route-before.php' ], [ 'before.php', 'group-before.php', 'route-before.php' ], null ],
		"only the group's, then the route's" => [ [ 'before_only' => 'group-before.php' ], [ 'before' => 'route-before.php' ], [ 'group-before.php', 'route-before.php' ], null ],
		"none from the group, then the route's" => [ [ 'before' => false ], [ 'before' => 'route-before.php' ], [ 'route-before.php' ], null ],
		"only the route's" => [ [ 'before' => 'group-before.php' ], [ 'before_only' => 'route-before.php' ], [ 'route-before.php' ], null ],
		'none' => [ [ 'before' => 'group-before.php' ], [ 'before' => false ], [], null ],
		"the group's buffer option" => [ [ 'buffer' => false ], [], [ 'before.php' ], false ],
		"the route's buffer option over the group's" => [ [ 'buffer' => false ], [ 'buffer' => true ], [ 'before.php' ], true ],
	] );

	it( "names a route in a group by the group's number and its own", function() {
		$problems = route_problems( [
			[ 'prefix' => '/admin', 'routes' => [
				[ 'GET', '/users', 'page.php' ],
				[ 'GET', 'users', 'page.php' ],
				[ 'prefix' => '/inner', 'routes' => [] ],
			] ],
			[ 'GET', '/admin/users', 'page.php' ],
		] );

		expect( $problems )->toBe( [
			"Route 1.2 [ 'GET', 'users', 'page.php' ]: in a group, the pattern must start with / or [, or be '' for the prefix by itself",
			"Group 1.3 [ 'prefix' => '/inner' ]: a group can't be inside another group",
			"Route 2 [ 'GET', '/admin/users', 'page.php' ]: duplicate of route 1.1",
		] );
	} );

	// The group's route has a problem of its own too, which isn't reported
	// until the group's are fixed.
	it( 'reports a problem with a group, and leaves out its routes', function( array $group, string $message ) {
		$problems = route_problems( [ $group + [ 'routes' => [ [ 'GET', '/b', 'missing.php' ] ] ] ] );

		expect( $problems )->toHaveCount( 1 );
		expect( $problems[0] )->toStartWith( 'Group 1 ' );
		expect( $problems[0] )->toEndWith( $message );
	} )->with( [
		'no prefix' => [ [], "the prefix must start with / and not end with /, like '/admin'" ],
		'no leading slash' => [ [ 'prefix' => 'admin' ], "the prefix must start with / and not end with /, like '/admin'" ],
		'a trailing slash' => [ [ 'prefix' => '/admin/' ], "the prefix must start with / and not end with /, like '/admin'" ],
		'an optional part' => [ [ 'prefix' => '/admin[/{page}]' ], "the prefix can't have optional parts" ],
		'a bad regex' => [ [ 'prefix' => '/users/{id:(\d+)}' ], 'the regex in {id:(\d+)} has a capturing group; use (?:...) instead' ],
		'routes not a list' => [ [ 'prefix' => '/admin', 'routes' => 'admin.php' ], 'routes must be a list of routes' ],
		'unknown key' => [ [ 'prefix' => '/admin', 'handlers' => 'admin' ], "unknown group key 'handlers'" ],
		'a bad option' => [ [ 'prefix' => '/admin', 'before' => 'missing.php' ], "the before file doesn't exist: " . __DIR__ . '/fixtures/handlers/missing.php' ],
		'before and before_only' => [ [ 'prefix' => '/admin', 'before' => 'before.php', 'before_only' => 'before.php' ], 'use before or before_only, not both' ],
		'errors not an array' => [ [ 'prefix' => '/admin', 'errors' => '404.php' ], "errors must be an array, like [ 404 => '404.php' ]" ],
		'an unknown error code' => [ [ 'prefix' => '/admin', 'errors' => [ 418 => '404.php' ] ], 'unknown error code in errors: 418. Use 500, or a standard 4xx code like 404 or 403' ],
		'a missing error file' => [ [ 'prefix' => '/admin', 'errors' => [ 404 => 'missing.php' ] ], "the 404 error file doesn't exist: " . __DIR__ . '/fixtures/handlers/missing.php' ],
	] );

	// Each route in a group has its group's key in groups, where the group
	// keeps its prefix and error files.
	it( 'keeps each group, with its prefix and error files', function() {
		$compiled = compile_routes( [
			[ 'GET', '/', 'page.php' ],
			[ 'prefix' => '/api', 'errors' => [ 404 => '404.php' ], 'routes' => [ [ 'GET', '/status', 'page.php' ] ] ],
			[ 'prefix' => '/plain', 'routes' => [ [ 'GET', '', 'page.php' ] ] ],
		] );

		expect( array_map( fn ( array $group ) => [ $group['prefix'], $group['errors'] ], $compiled['groups'] ) )->toBe( [
			[ '/api', [ 404 => '404.php' ] ],
			[ '/plain', [] ],
		] );
		expect( array_map( fn ( array $files ) => $files[4], $compiled['files'] ) )->toBe( [ 1 => 0, 2 => 1 ] );
	} );
} );
