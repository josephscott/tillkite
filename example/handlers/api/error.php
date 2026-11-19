<?php
declare( strict_types = 1 );

// The /api group's error file, for a 404 or 405 under /api. api/before.php
// has sent the JSON headers, even when no route matched.
echo json_encode( [ 'error' => http_response_code() ] ), "\n";
