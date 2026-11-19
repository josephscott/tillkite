<?php
declare( strict_types = 1 );

// Routes that use the features only Tillkite has, for make bench to time on
// their own. They're the example site's kinds of routes, as [ method,
// pattern ] pairs, and every one runs the same handler.
return [
	[ 'GET', '/' ],
	[ 'GET', '/users/new' ],
	[ 'GET', '/users/{id:\d+}' ],
	[ [ 'GET', 'POST' ], '/contact' ],
	[ 'GET', '/blog[/{year:\d{4}}[/{month:0[1-9]|1[0-2]}[/{slug:[a-z0-9-]+}]]]' ],
	[ 'GET', '/docs/{path:.+}' ],
	[ 'GET', '/admin/{page}' ],
	[ 'POST', '/hooks/github' ],
	[ '*', '/webhook' ],
];
