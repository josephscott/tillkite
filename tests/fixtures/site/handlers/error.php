<?php
declare( strict_types = 1 );

// Writes, calls error() without returning its false, and writes again.
echo 'partial';
$tillkite->error( (int) $params['code'] );
echo ' after';
