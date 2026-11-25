<?php
declare( strict_types = 1 );

// Runs for error( 403 ), which admin/before.php calls. Like every error
// file, it has a scope of its own, without the before-files' variables, and
// Tillkite has already set the status.
echo $tillkite->template( 'header.php', [ 'title' => 'Forbidden' ] );
?>
<main>
<h1>Forbidden</h1>
<?= $tillkite->template( 'route.php' ) ?>
<p>This page needs a key. Add <code>?key=demo</code> to the URL.</p>
</main>
<?= $tillkite->template( 'footer.php' ) ?>
