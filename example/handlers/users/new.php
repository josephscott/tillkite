<?php
declare( strict_types = 1 );

echo $tillkite->template( 'layout.php', [
	'title' => 'New user',
	'content' => '<p>This is a static route. It comes before <code>/users/{id:\d+}</code> in routes.php.</p>',
] );
