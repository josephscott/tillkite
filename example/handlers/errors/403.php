<?php
declare( strict_types = 1 );

// Runs for error( 403 ), which admin/before.php calls. Like every error
// file, it has a scope of its own, without the before-files' variables, and
// Tillkite has already set the status.
echo $tillkite->template( 'layout.php', [
	'title' => 'Forbidden',
	'content' => '<p>This page needs a key. Add <code>?key=demo</code> to the URL.</p>',
] );
