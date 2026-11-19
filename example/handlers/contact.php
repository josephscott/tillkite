<?php
declare( strict_types = 1 );

use Tillkite\Escape as Esc;

if ( $tillkite->method() === 'POST' ) {
	echo $tillkite->template( 'layout.php', [
		'title' => 'Thanks',
		'content' => '<p>Thanks for your message, ' . Esc::html( $visitor ) . '.</p>',
	] );
	return;
}

echo $tillkite->template( 'layout.php', [
	'title' => 'Contact',
	'content' => <<<'HTML'
		<form method="post" action="/contact">
		<p><label for="message">Message</label><br>
		<textarea id="message" name="message" rows="4" cols="40"></textarea></p>
		<p><button>Send</button></p>
		</form>
		HTML,
] );
