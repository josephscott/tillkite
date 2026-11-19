<?php
declare( strict_types = 1 );

echo $tillkite->template( 'layout.php', [
	'title' => 'User ' . $params['id'],
	'content' => '<p>The id comes from <code>$params[\'id\']</code>.</p>',
] );
