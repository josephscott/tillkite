<?php
declare( strict_types = 1 );

use Tillkite\Escape as Esc;

// Tillkite has already set the status and the Allow header.
echo $tillkite->template( 'layout.php', [
	'title' => 'Method not allowed',
	'content' => '<p>This URL doesn\'t take ' . Esc::html( $tillkite->method() ) . ' requests.</p>',
] );
