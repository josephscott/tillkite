<?php
declare( strict_types = 1 );

use Tillkite\Escape as Esc;

// The route's {path:.+} takes the rest of the URL, slashes and all, and it
// names a file to show. file() is the check for where a value from the
// request becomes a path: it returns the real path of the file inside
// docs/, or null for any name that isn't one, like one with a .. segment,
// or a symlink that leads out. Tillkite already turns away a .. segment in
// a parameter, but file() makes sure for whatever the path holds. docs/ is
// outside public/, so its files are only seen through this page.
$file = $tillkite->file( __DIR__ . '/../docs', $params['path'] . '.md' );
if ( $file === null ) {
	return $tillkite->error( 404 );
}

// Open the path file() returned, not the name, so what's read is what it
// checked. The file is Markdown, shown as text.
echo $tillkite->template( 'layout.php', [
	'title' => 'Docs: ' . $params['path'],
	'content' => '<pre>' . Esc::html( (string) file_get_contents( $file ) ) . '</pre>',
] );
