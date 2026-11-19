<?php
declare( strict_types = 1 );

echo 'the 403 page ' . json_encode( [ array_keys( get_defined_vars() ), $params ] );
