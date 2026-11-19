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
