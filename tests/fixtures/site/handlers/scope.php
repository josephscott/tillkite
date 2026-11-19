<?php
declare( strict_types = 1 );

// What this scope holds, and whether App's private $problems, which is set
// by now, can be reached.
echo json_encode( [
	'vars' => array_keys( get_defined_vars() ),
	'private' => isset( $tillkite->problems ),
] );
