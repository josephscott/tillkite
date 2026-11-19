<?php
declare( strict_types = 1 );

return [
	[ 'GET', '/', 'text.php' ],
	[ 'GET', '/users/{id:\d+}', 'params.php' ],
	[ 'GET', '/files/{path:.+}', 'params.php' ],
	[ 'GET', '/scope', 'scope.php' ],
	[ 'GET', '/late-header', 'late-header.php' ],
	[ 'GET', '/ends-buffer', 'ends-buffer.php' ],
];
