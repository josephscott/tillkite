<?php
declare( strict_types = 1 );

// The before-file for the /api group. The group uses before_only, so it
// runs in place of before.php, which sets things only HTML pages need.
header( 'Content-Type: application/json' );
header( 'Cache-Control: no-store' );
