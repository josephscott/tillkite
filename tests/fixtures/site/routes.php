<?php
declare( strict_types = 1 );

return [
	[ 'GET', '/', 'text.php' ],
	[ 'GET', '/users/{id:\d+}', 'params.php' ],
	[ 'GET', '/files/{path:.+}', 'params.php' ],
	[ 'GET', '/scope', 'scope.php' ],
	[ 'GET', '/scope/own-before', 'scope.php', [ 'before' => 'route-before.php' ] ],
	[ 'GET', '/scope/only-before', 'scope.php', [ 'before_only' => 'route-before.php' ] ],
	[ 'GET', '/scope/no-before', 'scope.php', [ 'before' => false ] ],
	[ '*', '/anything', 'method.php' ],
	[ 'prefix' => '/group', 'before' => 'group-before.php', 'routes' => [
		[ 'GET', '/own-before', 'scope.php', [ 'before' => 'route-before.php' ] ],
		[ 'GET', '/route/{name}', 'route.php' ],
		[ 'GET', '/prefix', 'group.php' ],
	] ],
	[ 'GET', '/groups', 'group.php' ],
	[ 'prefix' => '/group-error', 'before' => 'error-before.php', 'routes' => [
		[ 'GET', '', 'scope.php', [ 'before' => 'route-before.php' ] ],
	] ],
	[ 'prefix' => '/group-errors', 'errors' => [ 404 => 'group-error.php', 405 => 'group-error.php', 500 => 'group-error.php' ], 'routes' => [
		[ 'GET', '/error/{code}', 'error.php' ],
		[ 'GET', '/throws/{id}', 'throws.php' ],
	] ],
	[ 'prefix' => '/teams/{team}', 'before' => 'error-before.php', 'routes' => [
		[ 'GET', '/about', 'text.php' ],
	] ],
	[ 'prefix' => '/denied-group', 'before_only' => 'deny.php', 'routes' => [
		[ 'GET', '/page', 'text.php' ],
	] ],
	[ 'prefix' => '/after', 'before' => 'route-before.php', 'routes' => [
		[ 'GET', '/page', 'text.php' ],
	] ],
	[ 'GET', '/route/{name}', 'route.php' ],
	[ 'GET', '/timings', 'timings.php' ],
	[ 'GET', '/templates', 'templates.php' ],
	[ 'GET', '/exit', 'exit.php' ],
	[ 'GET', '/late-header', 'late-header.php' ],
	[ 'GET', '/late-header/unbuffered', 'late-header.php', [ 'buffer' => false ] ],
	[ 'GET', '/late-header/buffered', 'late-header.php', [ 'buffer' => true ] ],
	[ 'GET', '/ends-buffer', 'ends-buffer.php' ],
	[ 'GET', '/error/{code}', 'error.php' ],
	[ 'GET', '/throws/{id}', 'throws.php' ],
	[ 'GET', '/fatal/{kind}/{display}', 'fatal.php' ],
	// A long run of a's and no match runs into PCRE's backtrack limit.
	[ 'GET', '/backtrack/{v:(?:a|aa)+}', 'text.php' ],
];
