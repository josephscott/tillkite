<?php
declare( strict_types = 1 );

echo 'the 500 page: ' . $error->getMessage() . ' ' . json_encode( [ array_keys( get_defined_vars() ), $params ] );
