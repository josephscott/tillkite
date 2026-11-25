<?php
declare( strict_types = 1 );

use Tillkite\Escape as Esc;

// Tillkite has already set the status and the Allow header.
echo $tillkite->template( 'header.php', [ 'title' => 'Method not allowed' ] );
?>
<main>
<h1>Method not allowed</h1>
<?= $tillkite->template( 'route.php' ) ?>
<p>This URL doesn't take <?= Esc::html( $tillkite->method() ) ?> requests.</p>
</main>
<?= $tillkite->template( 'footer.php' ) ?>
