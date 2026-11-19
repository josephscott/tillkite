<?php
declare( strict_types = 1 );

// The request as Tillkite read it, and whether PHP built $_SERVER, which
// $GLOBALS shows without building it.
header( 'Content-Type: application/json' );
echo json_encode( [ 'method' => $tillkite->method(), 'uri' => $tillkite->uri(), 'params' => $params, 'server' => isset( $GLOBALS['_SERVER'] ) ] );
