<?php
declare( strict_types = 1 );

// The example site under php -S, with its route cache on. These check that
// it works the way its home page says. app-tests.php covers the details of
// each feature.

function example_request( string $method, string $target ) : array {
	$response = http( example()['port'], $method, $target );
	return $response;
}

it( 'answers each request its home page lists', function( string $method, string $target, int $status, string $text ) {
	$response = example_request( $method, $target );

	expect( $response['status'] )->toBe( $status );
	expect( $response['body'] )->toContain( $text );
} )->with( [
	'the home page, with a variable from the before-file' => [ 'GET', '/', 200, '<h1>Hello, guest</h1>' ],
	'a static route' => [ 'GET', '/users/new', 200, '<h1>New user</h1>' ],
	'a dynamic route' => [ 'GET', '/users/42', 200, '<h1>User 42</h1>' ],
	'the 404 page' => [ 'GET', '/nope', 404, '<h1>Not found</h1>' ],
	'the 405 page' => [ 'DELETE', '/users/42', 405, '<h1>Method not allowed</h1>' ],
	'the 500 page' => [ 'GET', '/boom', 500, '<h1>Server error</h1>' ],
	'a static file' => [ 'GET', '/style.css', 200, 'font:' ],
] );

it( "runs the app's before-file, even when no route matches", function() {
	expect( example_request( 'GET', '/' )['headers']['x-content-type-options'] )->toBe( 'nosniff' );
	expect( example_request( 'GET', '/nope' )['headers']['x-content-type-options'] )->toBe( 'nosniff' );
} );

it( 'shows the route that matched, and in development the stack trace, which goes through template()', function() {
	$body = example_request( 'GET', '/users/42' )['body'];

	expect( $body )->toContain( '<code>[ &#039;GET&#039;, &#039;/users/{id:\d+}&#039;, &#039;users/show.php&#039; ]</code>' );
	expect( $body )->toContain( '<summary>Stack trace</summary>' );
	expect( $body )->toContain( 'Tillkite\App-&gt;run()' );
	expect( $body )->toContain( 'Tillkite\App-&gt;template()' );
} );

it( 'redirects to the URL without a trailing slash', function() {
	$response = example_request( 'GET', '/users/42/?tab=posts' );

	expect( [ $response['status'], $response['headers']['location'] ] )->toBe( [ 308, '/users/42?tab=posts' ] );
} );

it( 'sends the Server-Timing header, with each template the page ran, and writes the route cache', function() {
	expect( example_request( 'GET', '/' )['headers']['server-timing'] )
		->toMatch( '{^tillkite-total;dur=[\d.]+, tillkite-route;dur=[\d.]+, tillkite-before;dur=[\d.]+, tillkite-handler;dur=[\d.]+, tillkite-template;desc="layout.php";dur=[\d.]+, tillkite-template;desc="head.php";dur=[\d.]+, tillkite-template;desc="header.php";dur=[\d.]+, tillkite-template;desc="nav.php";dur=[\d.]+, tillkite-template;desc="footer.php";dur=[\d.]+$}' );
	expect( glob( dirname( __DIR__ ) . '/example/var/tillkite-*.php' ) )->toHaveCount( 1 );
} );
