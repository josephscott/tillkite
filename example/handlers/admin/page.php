<?php
declare( strict_types = 1 );

use Tillkite\Escape as Esc;

$title = 'Admin';
if ( isset( $params['page'] ) ) {
	$title .= ': ' . $params['page'];
}

echo $tillkite->template( 'header.php', [ 'title' => $title ] );
?>
<main>
<h1><?= Esc::html( $title ) ?></h1>
<?= $tillkite->template( 'route.php' ) ?>
<p><code>before.php</code> ran first and set <code>$visitor</code> to <?= Esc::html( $visitor ) ?>. Then the group's <code>admin/before.php</code> let you in.</p>
</main>
<?= $tillkite->template( 'footer.php' ) ?>
