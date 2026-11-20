<?php
declare( strict_types = 1 );

// What this scope holds, that $tillkite is the app's subclass, and whether
// App's private $problems, which is set by now, can be reached.
echo json_encode( [
	'vars' => array_keys( get_defined_vars() ),
	'shout' => $tillkite->shout( 'hi' ),
	'private' => isset( $tillkite->problems ),
] );
