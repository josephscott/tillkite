<?php
declare( strict_types = 1 );

use Tillkite\Escape as Esc;

// Each part after /blog is optional, and a part that's left out isn't in
// $params. /blog lists every post, /blog/2026 the posts from that year,
// /blog/2026/10 that month's, and /blog/2026/10/this-is-the-slug shows one.
$year = $params['year'] ?? '';
$month = $params['month'] ?? '';
$slug = $params['slug'] ?? '';
$path = rtrim( "/blog/{$year}/{$month}/{$slug}", '/' );

// index.php registered the posts.
$posts = $tillkite->get( 'posts' );

$html = '<p><code>$params</code> is <code>' . Esc::html( (string) json_encode( $params ) ) . '</code></p>';

if ( $slug !== '' ) {
	if ( ! isset( $posts[$path] ) ) {
		return $tillkite->error( 404 );
	}

	// The route only lets digits into $year and $month, but they're escaped
	// anyway, like any text that goes in a page.
	$month_url = "/blog/{$year}/{$month}";
	$year_url = "/blog/{$year}";
	$html .= '<p>More from <a href="' . Esc::url( $month_url ) . '">' . Esc::html( $month_url ) . '</a> and <a href="' . Esc::url( $year_url ) . '">' . Esc::html( $year_url ) . '</a>.</p>';
	$title = $posts[$path];
} else {
	// The posts here are written in index.php, but a real site's would come
	// from a database, where anyone who writes a post sets its title and
	// URL, so they're escaped, the URL for an href and the title as text.
	$list = '';
	foreach ( $posts as $url => $post_title ) {
		if ( str_starts_with( $url, $path . '/' ) ) {
			$list .= '<li><a href="' . Esc::url( $url ) . '">' . Esc::html( $post_title ) . '</a></li>';
		}
	}

	if ( $list === '' ) {
		$html .= '<p>No posts.</p>';
	} else {
		$html .= '<ul>' . $list . '</ul>';
	}

	$title = 'Blog';
	if ( $month !== '' ) {
		$title = 'Blog: ' . date( 'F Y', (int) mktime( 0, 0, 0, (int) $month, 1, (int) $year ) );
	} elseif ( $year !== '' ) {
		$title = 'Blog: ' . $year;
	}
}

echo $tillkite->template( 'header.php', [ 'title' => $title ] );
?>
<main>
<h1><?= Esc::html( $title ) ?></h1>
<?= $tillkite->template( 'route.php' ) ?>
<?= $html ?>

</main>
<?= $tillkite->template( 'footer.php' ) ?>
