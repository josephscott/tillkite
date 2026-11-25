<?php
declare( strict_types = 1 );

use Tillkite\Escape as Esc;

$title = 'Contact';
if ( $tillkite->method() === 'POST' ) {
	$title = 'Thanks';
}

echo $tillkite->template( 'header.php', [ 'title' => $title ] );
?>
<main>
<h1><?= Esc::html( $title ) ?></h1>
<?= $tillkite->template( 'route.php' ) ?>
<?php if ( $tillkite->method() === 'POST' ) : ?>
<p>Thanks for your message, <?= Esc::html( $visitor ) ?>.</p>
<?php else : ?>
<form method="post" action="/contact">
<p><label for="message">Message</label><br>
<textarea id="message" name="message" rows="4" cols="40"></textarea></p>
<p><button>Send</button></p>
</form>
<?php endif; ?>
</main>
<?= $tillkite->template( 'footer.php' ) ?>
