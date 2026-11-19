<?php
declare( strict_types = 1 );

use Tillkite\Escape as Esc;

// Runs when no route matches. It has a scope of its own, without the before-file's variables, and
// Tillkite has already set the status.
echo $tillkite->template( 'layout.php', [
	'title' => 'Not found',
	'content' => '<p>There\'s nothing at <code>' . Esc::html( $tillkite->uri() ) . '</code>.</p>',
] );
