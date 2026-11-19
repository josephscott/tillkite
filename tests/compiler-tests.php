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
