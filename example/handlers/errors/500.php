<?php
declare( strict_types = 1 );

use Tillkite\Escape as Esc;

// $error is the exception. In development, under php -S, the page names it,
// and the Stack trace button shows where it was thrown. They can reveal file
// paths and other details, so everywhere else, visitors see only the message.
$html = '<p>Something went wrong.</p>';
if ( development() ) {
	$html .= '<p><code>' . Esc::html( $error::class . ': ' . $error->getMessage() ) . '</code></p>';
}

echo $tillkite->template( 'layout.php', [
	'title' => 'Server error',
	'content' => $html,
	'error' => $error,
] );
