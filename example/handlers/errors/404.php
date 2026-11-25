<?php
declare( strict_types = 1 );

use Tillkite\Escape as Esc;

// Runs when no route matches, and for error( 404 ), which blog.php calls.
// It has a scope of its own, without the before-file's variables, and
// Tillkite has already set the status.
echo $tillkite->template( 'header.php', [ 'title' => 'Not found' ] );
?>
<main>
<h1>Not found</h1>
<?= $tillkite->template( 'route.php' ) ?>
<p>There's nothing at <code><?= Esc::html( $tillkite->uri() ) ?></code>.</p>
</main>
<?= $tillkite->template( 'footer.php' ) ?>
