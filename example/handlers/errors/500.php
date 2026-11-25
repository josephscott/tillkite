<?php
declare( strict_types = 1 );

use Tillkite\Escape as Esc;

// $error is the exception. In development, under php -S, the page names it,
// and the footer's Stack trace button shows where it was thrown. They can
// reveal file paths and other details, so everywhere else, visitors see only
// the message.
echo $tillkite->template( 'header.php', [ 'title' => 'Server error' ] );
?>
<main>
<h1>Server error</h1>
<?= $tillkite->template( 'route.php' ) ?>
<p>Something went wrong.</p>
<?php if ( development() ) : ?>
<p><code><?= Esc::html( $error::class . ': ' . $error->getMessage() ) ?></code></p>
<?php endif; ?>
</main>
<?= $tillkite->template( 'footer.php', [ 'error' => $error ] ) ?>
