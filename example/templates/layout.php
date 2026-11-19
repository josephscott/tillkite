<?php
declare( strict_types = 1 );

use Tillkite\Escape as Esc;

// The example's page layout. Every page's handler runs it with template(),
// with the page's title, as plain text, and its content, as HTML that's
// already escaped. The 500 page adds the exception, as error.
//
// It holds only what's between the header and the footer. The parts that are
// the same on every page are templates of their own, which this one runs:
// head.php, header.php and footer.php.
//
// Under the heading, it shows the route that matched, from
// $tillkite->route().
//
// In development, a Stack trace button at the end shows how PHP got to this
// page, or the exception's stack trace.
?>
<!doctype html>
<html lang="en">
<?= $tillkite->template( 'head.php', [ 'title' => $data['title'] ] ) ?>
<body>
<?= $tillkite->template( 'header.php' ) ?>
<main>
<h1><?= Esc::html( $data['title'] ) ?></h1>
<?php if ( $tillkite->route() !== null ) : ?>
<figure class="route"><figcaption>The route for this page, in routes.php</figcaption><pre><code><?= Esc::html( route_source( $tillkite->route() ) ) ?></code></pre></figure>
<?php endif; ?>
<?= $data['content'] ?>

<?php if ( development() ) : ?>
<details class="trace"><summary>Stack trace</summary>
<p>Shown only in development, under <code>php -S</code>.</p>
<pre><?= Esc::html( (string) ( $data['error'] ?? stack_trace( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) ) ) ) ?></pre></details>
<?php endif; ?>
</main>
<?= $tillkite->template( 'footer.php' ) ?>
</body>
</html>
