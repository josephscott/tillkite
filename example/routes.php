<?php
declare( strict_types = 1 );

// Each route is [ method, pattern, handler file ], with route options last
// when it has any. A group puts its prefix in front of each of its routes'
// patterns, and takes the same options. The first route that matches wins.
//
// A handler file, a before-file or an error file is a path inside handlers/,
// so they can be in directories, like users/show.php. The directories are
// only for keeping the files in order: a file's path has nothing to do with
// its URL, and a group's prefix doesn't put its files in a directory.
return [
	[ 'GET', '/', 'home.php' ],

	// A static route, listed before the dynamic route below it.
	[ 'GET', '/users/new', 'users/new.php' ],
	[ 'GET', '/users/{id:\d+}', 'users/show.php' ],

	// One route for two methods.
	[ [ 'GET', 'POST' ], '/contact', 'contact.php' ],

	// Optional parts, one inside another, so one route serves /blog,
	// /blog/2026, /blog/2026/10 and /blog/2026/10/this-is-the-slug. The
	// month has to be 01 to 12, so /blog/2026/13 is a 404.
	[ 'GET', '/blog[/{year:\d{4}}[/{month:0[1-9]|1[0-2]}[/{slug:[a-z0-9-]+}]]]', 'blog.php' ],

	// A parameter that takes the rest of the path, slashes and all.
	[ 'GET', '/docs/{path:.+}', 'docs.php' ],

	// Streams its output, so it isn't buffered.
	[ 'GET', '/export.csv', 'export.php', [ 'buffer' => false ] ],

	// A group of routes under /admin, which all run admin/before.php after
	// before.php. So do the paths under /admin that no route matches.
	[ 'prefix' => '/admin', 'before' => 'admin/before.php', 'routes' => [
		[ 'GET', '', 'admin/page.php' ],
		[ 'GET', '/{page}', 'admin/page.php' ],
	] ],

	// A group that runs only its own before-file, without before.php, and
	// has its own error files, so its 404 and 405 pages are JSON too.
	[ 'prefix' => '/api', 'before_only' => 'api/before.php', 'errors' => [ 404 => 'api/error.php', 405 => 'api/error.php' ], 'routes' => [
		[ 'GET', '/status', 'api/status.php' ],
	] ],

	// No before-file at all.
	[ 'POST', '/hooks/github', 'hooks/github.php', [ 'before' => false ] ],

	// Any method.
	[ '*', '/webhook', 'webhook.php' ],

	// Always throws, to show the 500 page.
	[ 'GET', '/boom', 'boom.php' ],
];
