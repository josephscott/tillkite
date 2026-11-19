<?php
declare( strict_types = 1 );

// Each route is [ method, pattern, handler file ], with route options last
// when it has any. The first route that matches wins.
//
// A handler file, a before-file or an error file is a path inside handlers/,
// so they can be in directories, like users/show.php. The directories are
// only for keeping the files in order: a file's path has nothing to do with
// its URL.
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

	// Always throws, to show the 500 page.
	[ 'GET', '/boom', 'boom.php' ],
];
