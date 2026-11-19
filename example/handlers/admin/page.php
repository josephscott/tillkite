<?php
declare( strict_types = 1 );

use Tillkite\Escape as Esc;

$title = 'Admin';
if ( isset( $params['page'] ) ) {
	$title .= ': ' . $params['page'];
}

echo $tillkite->template( 'layout.php', [
	'title' => $title,
	'content' => '<p><code>before.php</code> ran first and set <code>$visitor</code> to ' . Esc::html( $visitor ) . '. Then the group\'s <code>admin/before.php</code> let you in.</p>',
] );
