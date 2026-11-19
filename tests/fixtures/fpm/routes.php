<?php
declare( strict_types = 1 );

return [
	[ 'GET', '/users/{id:\d+}', 'request.php' ],
	[ '*', '/anything', 'request.php' ],
	[ 'GET', '/no-document-root', 'request.php' ],
	[ 'GET', '/warning', 'warning.php' ],
	[ 'GET', '/throw', 'throw.php' ],
];
