<?php
declare( strict_types = 1 );

// api/before.php ran instead of before.php, so $visitor isn't set here.
echo json_encode( [ 'status' => 'ok', 'visitor_set' => isset( $visitor ) ] ), "\n";
