<?php
declare( strict_types = 1 );

use Tillkite\Escape as Esc;

$title = 'Hello, ' . $visitor;
echo $tillkite->template( 'header.php', [ 'title' => $title ] );
?>
<main>
<h1><?= Esc::html( $title ) ?></h1>
<?= $tillkite->template( 'route.php' ) ?>
<p class="lead">Every page here is a PHP file that Tillkite routes to, and shows the route it matched. Try each one:</p>
<dl>
<div>
<dt><a href="/users/new">/users/new</a> and <a href="/users/42">/users/42</a></dt>
<dd>A static route, and a dynamic one whose id has to be digits.</dd>
</div>
<div>
<dt><a href="/blog">/blog</a>, <a href="/blog/2026">/blog/2026</a>, <a href="/blog/2026/10">/blog/2026/10</a> and <a href="/blog/2026/10/this-is-the-slug">/blog/2026/10/this-is-the-slug</a></dt>
<dd>One route, where each part after <code>/blog</code> is optional. A post that doesn't exist, like <a href="/blog/2026/10/no-such-post">/blog/2026/10/no-such-post</a>, shows the 404 page with <code>error()</code>. The posts come from <code>get()</code>, which returns what <code>index.php</code> gave <code>register()</code>.</dd>
</div>
<div>
<dt><a href="/docs/guide/install">/docs/guide/install</a>, <a href="/docs/guide/routes">/docs/guide/routes</a> and <a href="/docs/guide/nope">/docs/guide/nope</a></dt>
<dd>A parameter that takes the rest of the path, which names a file in <code>docs/</code>. The handler finds it with <code>file()</code>, which returns <code>null</code> for a name that isn't a file inside the directory, so a page that doesn't exist is the 404 page.</dd>
</div>
<div>
<dt><a href="/contact">/contact</a></dt>
<dd>One route for GET and POST.</dd>
</div>
<div>
<dt><a href="/admin">/admin</a>, <a href="/admin/stats">/admin/stats</a> and <a href="/admin/stats/nope">/admin/stats/nope</a></dt>
<dd>A group of routes under <code>/admin</code>, with a before-file that runs after <code>before.php</code>. Without <code>?key=demo</code>, it shows the 403 page with <code>error()</code>, and the handler doesn't run. It runs for the paths under <code>/admin</code> that no route matches too, so without the key, they get the 403 page instead of the 404 page, and don't show which pages exist. On each of these pages, the nav marks Admin, from <code>group()</code>.</dd>
</div>
<div>
<dt><a href="/api/status">/api/status</a> and <a href="/api/nope">/api/nope</a></dt>
<dd>A group that runs only its own before-file, which sends JSON headers, and not <code>before.php</code>. It has its own error files, so a path under <code>/api</code> that no route matches gets a JSON 404.</dd>
</div>
<div>
<dt><a href="/export.csv">/export.csv</a></dt>
<dd>A route that streams its output instead of buffering it.</dd>
</div>
<div>
<dt><a href="/users/42/">/users/42/</a></dt>
<dd>Redirects to the URL without the trailing slash.</dd>
</div>
<div>
<dt><a href="/nope">/nope</a> and <a href="/boom">/boom</a></dt>
<dd>The 404 page, and the 500 page for an exception.</dd>
</div>
<div>
<dt><code>/webhook</code> and <code>/hooks/github</code></dt>
<dd>A route for any method, and a POST route that skips the before-file, and checks the request's signature itself, as a webhook from GitHub would. Try them with curl, with the commands in <code>hooks/github.php</code>.</dd>
</div>
</dl>
<p>The handlers are in directories where that keeps them in order: <code>handlers/users/</code>, <code>admin/</code>, <code>api/</code> and <code>hooks/</code> each hold the files for one part of the site, a group's before-file and error file too, and <code>errors/</code> holds the error pages. <code>routes.php</code> names each file by its path inside <code>handlers/</code>, like <code>users/show.php</code>. A file's path has nothing to do with its URL.</p>
<p>Each page's handler prints its own <code>&lt;main&gt;</code>, between two templates that it runs with <code>template()</code>: <code>templates/header.php</code>, the top of the page, which runs <code>head.php</code> and <code>nav.php</code>, and <code>templates/footer.php</code>, the bottom. Under its heading, it runs <code>route.php</code>, which shows the route that matched. A template has a scope of its own, with only <code>$tillkite</code> and the <code>$data</code> it was given.</p>
<p>In development, under <code>php -S</code>, each page has a Stack trace button that shows how PHP got to it. On the 500 page, it shows where the exception was thrown.</p>
<p>In development, every response has a <code>Server-Timing</code> header with the time each step took. The bottom of each page shows it. It's off everywhere else, because timings every visitor can see can give information away.</p>
</main>
<?= $tillkite->template( 'footer.php' ) ?>
