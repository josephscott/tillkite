<?php
declare( strict_types = 1 );

// The route turns buffering off, so each row goes out as it's written. Its
// Server-Timing header went out before this file ran.
header( 'Content-Type: text/csv; charset=utf-8' );
echo "id,name\n";
foreach ( [ 'Ada', 'Grace', 'Linus' ] as $i => $name ) {
	echo ( $i + 1 ) . ',' . $name . "\n";
	flush();
}
