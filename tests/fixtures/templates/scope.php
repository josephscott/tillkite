<?php
declare( strict_types = 1 );

// What this scope holds, and the data it was given.
echo json_encode( [ 'vars' => array_keys( get_defined_vars() ), 'data' => $data ] );
