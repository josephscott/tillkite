<?php
declare( strict_types = 1 );

use Tillkite\Escape as Esc;

$title = 'User ' . $params['id'];
echo $tillkite->template( 'header.php', [ 'title' => $title ] );
?>
<main>
<h1><?= Esc::html( $title ) ?></h1>
<?= $tillkite->template( 'route.php' ) ?>
<p>The id comes from <code>$params['id']</code>.</p>
</main>
<?= $tillkite->template( 'footer.php' ) ?>
