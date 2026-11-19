<?php
declare( strict_types = 1 );

describe( 'matching', function() {
	it( 'matches a static route', function() {
		$matcher = make_matcher( [
			[ 'GET', '/', 'page.php' ],
			[ 'GET', '/users/new', 'page.php' ],
		] );

		expect( $matcher->match( 'GET', '/users/new' ) )->toBe( [ 'status' => 200, 'route' => 1, 'params' => [] ] );
	} );

	it( 'matches a dynamic route and names its parameters', function() {
		$matcher = make_matcher( [ [ 'GET', '/users/{name}/posts/{id:\d+}', 'page.php' ] ] );

		expect( $matcher->match( 'GET', '/users/jo/posts/7' ) )
			->toBe( [ 'status' => 200, 'route' => 0, 'params' => [ 'name' => 'jo', 'id' => '7' ] ] );
	} );

	it( 'decodes parameters', function() {
		$matcher = make_matcher( [ [ 'GET', '/users/{name}/posts/{id:\d+}', 'page.php' ] ] );

		expect( $matcher->match( 'GET', '/users/Jo%20Smith/posts/7' ) )
			->toBe( [ 'status' => 200, 'route' => 0, 'params' => [ 'name' => 'Jo Smith', 'id' => '7' ] ] );
	} );

	it( 'checks an encoded value against its regex again once it is decoded', function( string $pattern, string $path, ?string $value ) {
		$result = make_matcher( [ [ 'GET', $pattern, 'page.php' ] ] )->match( 'GET', $path );

		expect( $result['params']['v'] ?? null )->toBe( $value );
		expect( $result['status'] )->toBe( $value === null ? 404 : 200 );
	} )->with( [
		'an encoded dot and slash, against a regex that turns them away' => [ '/f/{v:[^./]+}', '/f/%2E%2E%2Fetc%2Fpasswd', null ],
		'a name a lookahead turns away' => [ '/u/{v:(?!admin$)[^/]+}', '/u/%61dmin', null ],
		'a value its regex takes' => [ '/f/{v:[^./]+}', '/f/a%20b', 'a b' ],
		'a catch-all' => [ '/f/{v:.+}', '/f/a%2F..%0Ab', "a/..\nb" ],
		'an encoded slash, against the default regex, which takes one segment' => [ '/f/{v}', '/f/a%2Fetc', null ],
		'an encoded backslash, against the default regex, written out' => [ '/f/{v:[^/]+}', '/f/a%5Cetc', null ],
		'a raw backslash, against the default regex' => [ '/f/{v}', '/f/a\\etc', null ],
		'a regex written for the encoded form' => [ '/f/{v:(?:[a-z]|%[0-9A-F]{2})+}', '/f/a%2Eb', 'a.b' ],
	] );

	it( 'turns away a .. segment or a null byte in any parameter, encoded or not', function( string $pattern, string $path, ?string $value ) {
		$result = make_matcher( [ [ 'GET', $pattern, 'page.php' ] ] )->match( 'GET', $path );

		expect( $result['params']['v'] ?? null )->toBe( $value );
		expect( $result['status'] )->toBe( $value === null ? 404 : 200 );
	} )->with( [
		'by itself, for the default regex' => [ '/f/{v}', '/f/..', null ],
		'encoded' => [ '/f/{v}', '/f/%2E%2E', null ],
		'in a catch-all' => [ '/f/{v:.+}', '/f/a/../b', null ],
		'after an encoded backslash, for a regex written for the encoded form' => [ '/f/{v:(?:[^/%]|%[0-9A-F]{2})+}', '/f/a%5C..', null ],
		'two dots that are not a segment' => [ '/f/{v:.+}', '/f/a..b/..c', 'a..b/..c' ],
		'a null byte, in a catch-all' => [ '/f/{v:.+}', '/f/a%00b', null ],
	] );

	it( 'checks only the values that were encoded', function() {
		$matcher = make_matcher( [ [ 'GET', '/{a:[a-z]+(?=-)}-{b}', 'page.php' ] ] );

		expect( $matcher->match( 'GET', '/x-y%20z' )['params'] )->toBe( [ 'a' => 'x', 'b' => 'y z' ] );
	} );

	it( 'returns 500 when checking a decoded value fails', function() {
		// Encoded, the value has no a, so the regex's first choice takes it at
		// once. Decoded, it's all a, which only the slow second choice can try.
		$matcher = make_matcher( [ [ 'GET', '/f/{v:[^a]+|(?:a|aa)+b}', 'page.php' ] ] );
		$limit = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.backtrack_limit', '1000' );
		$result = $matcher->match( 'GET', '/f/' . str_repeat( '%61', 30 ) . 'cb' );
		ini_set( 'pcre.backtrack_limit', (string) $limit );

		expect( $result['status'] )->toBe( 500 );
	} );

	it( 'returns 404 when nothing matches', function() {
		$matcher = make_matcher( [ [ 'GET', '/users/{id:\d+}', 'page.php' ] ] );

		expect( $matcher->match( 'GET', '/users/x' ) )->toBe( [ 'status' => 404 ] );
	} );

	// The b at the end gets PCRE past its quick check for one, and the c in
	// front of it makes it try every way to split the a's first.
	it( 'returns 500 when PCRE fails', function() {
		$matcher = make_matcher( [ [ 'GET', '/f/{v:(?:a|aa)+b}', 'page.php' ] ] );
		$limit = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.backtrack_limit', '1000' );
		$result = $matcher->match( 'GET', '/f/' . str_repeat( 'a', 30 ) . 'cb' );
		ini_set( 'pcre.backtrack_limit', (string) $limit );

		expect( $result )->toBe( [ 'status' => 500, 'error' => 'Backtrack limit exhausted' ] );
	} );

	it( 'leaves missing optional parameters out', function() {
		$matcher = make_matcher( [ [ 'GET', '/blog[/{page:\d+}[/{sort}]]', 'page.php' ] ] );

		expect( $matcher->match( 'GET', '/blog' )['params'] )->toBe( [] );
		expect( $matcher->match( 'GET', '/blog/2' )['params'] )->toBe( [ 'page' => '2' ] );
		expect( $matcher->match( 'GET', '/blog/2/new' )['params'] )->toBe( [ 'page' => '2', 'sort' => 'new' ] );
		expect( $matcher->match( 'GET', '/blog/x' )['status'] )->toBe( 404 );
	} );

	it( 'tells routes apart by a MARK', function() {
		$routes = [];
		for ( $i = 0; $i <= Tillkite\Compiler::COUNT_LEAVES; $i++ ) {
			$routes[] = [ 'GET', '/r' . $i . '/{id}', 'page.php' ];
		}

		$matcher = make_matcher( $routes );
		expect( $matcher->match( 'GET', '/r7/x' ) )->toBe( [ 'status' => 200, 'route' => 7, 'params' => [ 'id' => 'x' ] ] );
	} );

	it( 'matches a catch-all across segments', function() {
		$matcher = make_matcher( [ [ 'GET', '/docs/{path:.+}', 'page.php' ] ] );

		expect( $matcher->match( 'GET', '/docs/a/b/c.md' )['params'] )->toBe( [ 'path' => 'a/b/c.md' ] );
	} );

	it( 'matches a non-ASCII literal by its percent-encoded form', function() {
		$matcher = make_matcher( [
			[ 'GET', '/café', 'page.php' ],
			[ 'GET', '/café/{id}', 'page.php' ],
		] );

		expect( $matcher->match( 'GET', '/caf%C3%A9' )['route'] )->toBe( 0 );
		expect( $matcher->match( 'GET', '/caf%C3%A9/1' )['route'] )->toBe( 1 );
	} );

	it( 'answers HEAD with a GET route', function() {
		$matcher = make_matcher( [
			[ 'GET', '/a', 'page.php' ],
			[ 'GET', '/b/{id}', 'page.php' ],
		] );

		expect( $matcher->match( 'HEAD', '/a' )['route'] )->toBe( 0 );
		expect( $matcher->match( 'HEAD', '/b/1' )['route'] )->toBe( 1 );
	} );

	it( "answers any method with a '*' route, even one with no routes of its own", function() {
		$matcher = make_matcher( [
			[ 'GET', '/', 'page.php' ],
			[ '*', '/webhook', 'page.php' ],
			[ '*', '/hooks/{id}', 'page.php' ],
		] );

		expect( $matcher->match( 'GET', '/webhook' )['route'] )->toBe( 1 );
		expect( $matcher->match( 'DELETE', '/webhook' )['route'] )->toBe( 1 );
		expect( $matcher->match( 'BREW', '/webhook' )['route'] )->toBe( 1 );
		expect( $matcher->match( 'BREW', '/hooks/1' )['route'] )->toBe( 2 );
	} );

	it( 'escapes regex characters in literal parts of a dynamic route', function() {
		$matcher = make_matcher( [ [ 'GET', '/v1.0/{x}', 'page.php' ] ] );

		expect( $matcher->match( 'GET', '/v1.0/a' )['status'] )->toBe( 200 );
		expect( $matcher->match( 'GET', '/v1x0/a' )['status'] )->toBe( 404 );
	} );
} );
