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

	// Always throws, to show the 500 page.
	[ 'GET', '/boom', 'boom.php' ],
];
