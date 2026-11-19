<?php
declare( strict_types = 1 );

// The before-file for the /admin group, which runs after before.php, so
// $visitor is set. Without the key, it shows the 403 page, and the handler
// doesn't run.
//
// A key in the URL keeps the example simple, but it ends up in logs and in
// the Referer header, so a real site checks who's signed in instead. Either
// way, hash_equals() compares a secret in the same time whatever the guess
// holds, and ?key[]=demo makes $_GET['key'] an array, which it doesn't take.
$key = $_GET['key'] ?? '';
if ( ! is_string( $key ) || ! hash_equals( 'demo', $key ) ) {
	return $tillkite->error( 403 );
}
