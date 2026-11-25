<?php
declare( strict_types = 1 );

echo $tillkite->template( 'header.php', [ 'title' => 'New user' ] );
?>
<main>
<h1>New user</h1>
<?= $tillkite->template( 'route.php' ) ?>
<p>This is a static route. It comes before <code>/users/{id:\d+}</code> in routes.php.</p>
</main>
<?= $tillkite->template( 'footer.php' ) ?>
