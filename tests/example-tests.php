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
	'optional parts, all left out' => [ 'GET', '/blog', 200, '<h1>Blog</h1>' ],
	'optional parts, some left out' => [ 'GET', '/blog/2026/10', 200, '<h1>Blog: October 2026</h1>' ],
	'optional parts, all there, with a post from get()' => [ 'GET', '/blog/2026/10/this-is-the-slug', 200, '<h1>This is the slug</h1>' ],
	'a handler that shows the 404 page' => [ 'GET', '/blog/2026/10/no-such-post', 404, '<h1>Not found</h1>' ],
	'a parameter that takes the rest of the path, which names a file that file() finds' => [ 'GET', '/docs/guide/install', 200, '<h1>Docs: guide/install</h1>' ],
	'a name that file() finds no file for' => [ 'GET', '/docs/guide/nope', 404, '<h1>Not found</h1>' ],
	'one route for GET and POST' => [ 'POST', '/contact', 200, 'Thanks for your message, guest.' ],
	'a route for any method' => [ 'DELETE', '/webhook', 200, 'Received a DELETE request.' ],
	'a webhook without a valid signature' => [ 'POST', '/hooks/github', 401, "The signature doesn't match the body." ],
	"a group's before-file that shows the 403 page" => [ 'GET', '/admin/stats', 403, '<h1>Forbidden</h1>' ],
	"a group's before-file that lets it run, after the app's" => [ 'GET', '/admin/stats?key=demo', 200, 'set <code>$visitor</code> to guest' ],
	"the group's prefix by itself" => [ 'GET', '/admin?key=demo', 200, '<h1>Admin</h1>' ],
	"a path under the group's prefix that no route matches, which its before-file hides" => [ 'GET', '/admin/stats/nope', 403, '<h1>Forbidden</h1>' ],
	'the same path with the key' => [ 'GET', '/admin/stats/nope?key=demo', 404, '<h1>Not found</h1>' ],
	'only its own before-file' => [ 'GET', '/api/status', 200, '{"status":"ok","visitor_set":false}' ],
	"a group's own 404 file" => [ 'GET', '/api/nope', 404, '{"error":404}' ],
	'a stream' => [ 'GET', '/export.csv', 200, "id,name\n1,Ada\n2,Grace\n3,Linus\n" ],
	'the 404 page' => [ 'GET', '/nope', 404, '<h1>Not found</h1>' ],
	'the 405 page' => [ 'DELETE', '/users/42', 405, '<h1>Method not allowed</h1>' ],
	'the 500 page' => [ 'GET', '/boom', 500, '<h1>Server error</h1>' ],
	'a static file' => [ 'GET', '/style.css', 200, 'font:' ],
] );

it( "runs the app's before-file, even when no route matches, except where a route turns it off or runs only its own", function() {
	$api = example_request( 'GET', '/api/status' )['headers'];

	expect( example_request( 'GET', '/' )['headers']['x-content-type-options'] )->toBe( 'nosniff' );
	expect( example_request( 'GET', '/admin/stats?key=demo' )['headers']['x-content-type-options'] )->toBe( 'nosniff' );
	expect( example_request( 'GET', '/nope' )['headers']['x-content-type-options'] )->toBe( 'nosniff' );
	expect( example_request( 'GET', '/api/nope' )['headers']['content-type'] )->toBe( 'application/json' );
	$body = '{"zen":"Keep it logically awesome."}';
	$signed = http( example()['port'], 'POST', '/hooks/github', [ 'X-Hub-Signature-256' => 'sha256=' . hash_hmac( 'sha256', $body, 'example-secret' ) ], $body );
	expect( [ $signed['status'], $signed['body'] ] )->toBe( [ 200, "Received, with a valid signature. The before-file didn't run.\n" ] );
	expect( $signed['headers'] )->not->toHaveKey( 'x-content-type-options' );
	expect( $api )->not->toHaveKey( 'x-content-type-options' );
	expect( $api['content-type'] )->toBe( 'application/json' );
} );

it( 'shows the route that matched, and in development the stack trace, which goes through template()', function() {
	$body = example_request( 'GET', '/users/42' )['body'];

	expect( $body )->toContain( '<code>[ &#039;GET&#039;, &#039;/users/{id:\d+}&#039;, &#039;users/show.php&#039; ]</code>' );
	expect( $body )->toContain( '<summary>Stack trace</summary>' );
	expect( $body )->toContain( 'Tillkite\App-&gt;run()' );
	expect( $body )->toContain( 'Tillkite\App-&gt;template()' );
} );

it( 'marks Admin in the nav, a template the header runs, on the pages in the /admin group, from group()', function() {
	$current = '<a href="/admin" aria-current="true">Admin</a>';

	expect( example_request( 'GET', '/admin/stats?key=demo' )['body'] )->toContain( $current );
	expect( example_request( 'GET', '/admin/stats/nope' )['body'] )->toContain( $current );
	expect( example_request( 'GET', '/' )['body'] )->not->toContain( $current );
} );

it( 'redirects to the URL without a trailing slash', function() {
	$response = example_request( 'GET', '/users/42/?tab=posts' );

	expect( [ $response['status'], $response['headers']['location'] ] )->toBe( [ 308, '/users/42?tab=posts' ] );
} );

it( 'sends the Server-Timing header, with each template the page ran, and writes the route cache', function() {
	expect( example_request( 'GET', '/' )['headers']['server-timing'] )
		->toMatch( '{^tillkite-total;dur=[\d.]+, tillkite-route;dur=[\d.]+, tillkite-before;dur=[\d.]+, tillkite-handler;dur=[\d.]+, tillkite-template;desc="header.php";dur=[\d.]+, tillkite-template;desc="head.php";dur=[\d.]+, tillkite-template;desc="nav.php";dur=[\d.]+, tillkite-template;desc="route.php";dur=[\d.]+, tillkite-template;desc="footer.php";dur=[\d.]+$}' );
	expect( glob( dirname( __DIR__ ) . '/example/var/tillkite-*.php' ) )->toHaveCount( 1 );
} );
