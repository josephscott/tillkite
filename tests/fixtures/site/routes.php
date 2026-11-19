<?php
declare( strict_types = 1 );

return [
	[ 'GET', '/', 'text.php' ],
	[ 'GET', '/users/{id:\d+}', 'params.php' ],
	[ 'GET', '/files/{path:.+}', 'params.php' ],
	[ 'GET', '/scope', 'scope.php' ],
	[ '*', '/anything', 'method.php' ],
	[ 'GET', '/route/{name}', 'route.php' ],
	[ 'GET', '/timings', 'timings.php' ],
	[ 'GET', '/templates', 'templates.php' ],
	[ 'GET', '/late-header', 'late-header.php' ],
	[ 'GET', '/ends-buffer', 'ends-buffer.php' ],
	[ 'GET', '/error/{code}', 'error.php' ],
	[ 'GET', '/throws/{id}', 'throws.php' ],
	[ 'GET', '/fatal/{kind}/{display}', 'fatal.php' ],
	// A long run of a's and no match runs into PCRE's backtrack limit.
	[ 'GET', '/backtrack/{v:(?:a|aa)+}', 'text.php' ],
];
