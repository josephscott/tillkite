<?php
declare( strict_types = 1 );

// 10 synthetic routes for make bench, generated once and committed so every
// run uses the same routes. Each resource has ten routes, static ones first:
// /res0, /res0/new, /res0/{id}, ..., /res0/{id}/items/{item}/comments/{comment}

return [
	[ 'GET', '/res0' ],
	[ 'POST', '/res0' ],
	[ 'GET', '/res0/new' ],
	[ 'GET', '/res0/{id}' ],
	[ 'PUT', '/res0/{id}' ],
	[ 'DELETE', '/res0/{id}' ],
	[ 'GET', '/res0/{id}/edit' ],
	[ 'GET', '/res0/{id}/items' ],
	[ 'GET', '/res0/{id}/items/{item}' ],
	[ 'GET', '/res0/{id}/items/{item}/comments/{comment}' ],
];
