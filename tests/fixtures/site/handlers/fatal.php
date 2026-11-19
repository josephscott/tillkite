<?php
declare( strict_types = 1 );

// Ends the request with a fatal error, partway through: running out of
// memory, or an exception that nothing catches. display_errors comes from
// the URL.
ini_set( 'display_errors', $params['display'] );
ini_set( 'memory_limit', '16M' );
echo 'partial';
if ( $params['kind'] === 'memory' ) {
	$big = str_repeat( 'x', 32 * 1024 * 1024 );
}

throw new RuntimeException( 'boom' );
