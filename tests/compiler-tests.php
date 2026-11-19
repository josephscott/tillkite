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

	it( 'keeps the first route for a path', function() {
		$compiled = compile_routes( [
			[ 'GET', '/a', 'page.php' ],
			[ [ 'GET', 'POST' ], '/a', 'page.php' ],
		] );

		expect( array_map( fn ( $paths ) => array_column( $paths, 'route' ), $compiled['static'] ) )
			->toBe( [ 'GET' => [ 0 ], 'HEAD' => [ 0 ], 'POST' => [ 1 ] ] );
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
			'{^(?|/users/(\d+)$|/([^/]+)\-([^/]++)$()()|/docs/(.+)(?<!/)$()|/caf%C3%A9/([^/]++)$()())}sD' => [
				2 => $leaf( 0, [ 'id' ] ),
				3 => $leaf( 2, [ 'path' ] ),
				4 => $leaf( 3, [ 'id' ] ),
				5 => $leaf( 1, [ 'a', 'b' ] ),
			],
		] );
	} );

	it( 'tells more routes apart by a MARK', function() {
		$routes = [];
		for ( $i = 0; $i <= Tillkite\Compiler::COUNT_LEAVES; $i++ ) {
			$routes[] = [ 'GET', '/r' . $i . '/{id}', 'page.php' ];
		}

		$compiled = compile_routes( $routes );
		$regex = array_key_first( $compiled['dynamic']['GET'] );
		expect( $regex )->toStartWith( '{^(?|/r0/([^/]++)$(*:0)|/r1/([^/]++)$(*:1)|' );
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

	it( 'reports bad settings', function( array $settings, string $message ) {
		$problems = route_problems( [], $settings );

		expect( $problems )->toBe( [ $message ] );
	} )->with( [
		'missing handlers directory' => [ [ 'handlers' => '/no/such/dir' ], "The handlers directory doesn't exist: /no/such/dir" ],
		'missing before file' => [ [ 'before' => 'missing.php' ], "The before file doesn't exist: " . __DIR__ . '/fixtures/handlers/missing.php' ],
		'lowercase custom method' => [ [ 'methods' => [ 'purge' ] ], "Custom methods must be uppercase, like PURGE: 'purge'" ],
		'custom method with a newline' => [ [ 'methods' => [ "PURGE\n" ] ], "Custom methods must be uppercase, like PURGE: 'PURGE\n'" ],
		'custom method not a string' => [ [ 'methods' => [ [ 'PURGE', 1 ] ] ], "Custom methods must be uppercase, like PURGE: [ 'PURGE', 1 ]" ],
	] );

	it( 'accepts valid settings', function() {
		compile_routes( [], [ 'before' => 'before.php', 'methods' => [ 'PURGE', 'GET' ] ] );
	} );
} );
