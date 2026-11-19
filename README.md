# Tillkite

Tillkite is a small, fast PHP router that maps URLs to PHP files. You write a list of routes, and each one points to a plain PHP file that builds the page. There are no controllers, request or response objects, or middleware: a page is a PHP file that uses `echo` and `header()`.

- PHP 8.4 or later, with no dependencies.
- Install it with Composer, or copy one file, `dist/tillkite.php`, into your project.
- One command makes a new site to start from: `vendor/bin/tillkite new`, or `php tillkite.php new` with the one file.
- Escaping for each place text goes in a page, HTML, attributes, URLs, JavaScript and CSS, with `Tillkite\Escape`.
- Runs the same under `php -S` for development and php-fpm in production.
- Fast: finding the route takes about half a microsecond or less, with 10 routes or 5,000. See [Speed](#speed).

## Quick start

Tillkite can make a new site for you. With Composer, in your site's directory:

```bash
composer require josephscott/tillkite
```

```bash
vendor/bin/tillkite new
```

Without Composer, download [`dist/tillkite.php`](dist/tillkite.php), which is every class in one file, and run it:

```bash
php tillkite.php new my-site
```

Either way, you get a site with two pages, a layout [template](#templates), error pages, a before-file that sends security headers, and `init.php`, for what `public/index.php` and your scripts share. With the dist file, the site gets its own copy of it, in `lib/`. Give `new` a directory, or leave it out for the current one. It never overwrites a file or writes through a symlink: if any file it would make is already there, or a symlink is in place of one of them or of one of its directories, it lists them and writes nothing.

```
my-site/
├── init.php            what index.php and your scripts share
├── public/
│   ├── index.php       every request goes through this
│   └── style.css
├── routes.php          the list of routes
├── handlers/           one PHP file per page
│   ├── before.php      runs first, and sends the security headers
│   ├── home.php
│   ├── hello.php
│   ├── 404.php
│   ├── 405.php
│   └── 500.php         shows the error under php -S, and logs it elsewhere
├── templates/
│   └── layout.php      the page around each page's content
├── var/                the route cache, writable by the web server
└── lib/
    └── tillkite.php    with the dist file
```

Start PHP's built-in server in the site's directory, and open http://localhost:8000:

```bash
php -S localhost:8000 -t public public/index.php
```

`new` prints that command, and the nginx settings below.

`public/index.php` starts with `require __DIR__ . '/../init.php';`, then loads Tillkite, creates the app and runs it. A script of your own, like a cron job in `bin/`, starts the same way, so `init.php` is the place for what both need, like a database connection in `$db`. `index.php` gets its variables, and can [register](#sharing-values) them with the app, for handlers and templates. Only the web needs Tillkite, so with the dist file, `index.php` requires it, not `init.php`. With Composer, `init.php` requires the autoloader, since scripts need your other packages too, and that loads Tillkite as well.

### By hand

To see what each file does, or to add Tillkite to a project you have, make the files yourself. Get Tillkite with Composer:

```bash
composer require josephscott/tillkite
```

Or copy [`dist/tillkite.php`](dist/tillkite.php) from this repository into your project.

Then make four files:

```
my-site/
├── public/
│   └── index.php       every request goes through this
├── routes.php          the list of routes
├── handlers/           one PHP file per page
│   ├── home.php
│   └── hello.php
└── var/                the route cache, writable by the web server
```

`public/index.php` creates the app and runs it:

```php
<?php // public/index.php
declare( strict_types = 1 );

require __DIR__ . '/../vendor/autoload.php'; // or: require __DIR__ . '/../tillkite.php';

$tillkite = new Tillkite\App( [
	'routes' => __DIR__ . '/../routes.php',
	'handlers' => __DIR__ . '/../handlers',
	'cache' => __DIR__ . '/../var',
] );

return $tillkite->run();
```

`routes.php` returns the routes. Each one is a method, a URL pattern and the file that handles it:

```php
<?php // routes.php
declare( strict_types = 1 );

return [
	[ 'GET', '/', 'home.php' ],
	[ 'GET', '/hello/{name}', 'hello.php' ],
];
```

Each handler writes its page. Parameters from the URL are in `$params`:

```php
<?php // handlers/home.php
declare( strict_types = 1 );

echo '<h1>Home</h1><p><a href="/hello/Ada">Say hello</a></p>';
```

```php
<?php // handlers/hello.php
declare( strict_types = 1 );

use Tillkite\Escape as Esc;

echo '<h1>Hello, ' . Esc::html( $params['name'] ) . '!</h1>';
```

Make the cache directory and start PHP's built-in server:

```bash
mkdir var
```

```bash
php -S localhost:8000 -t public public/index.php
```

Open http://localhost:8000/hello/Ada. Tillkite also handles the cases around your routes:

- `/hello/Ada/` redirects to `/hello/Ada` with a 308, because URLs don't end in `/` by default.
- `POST /hello/Ada` gets a 405 with an `Allow: GET, HEAD` header.
- `/nope` gets a plain-text 404. To show your own pages instead, see [`errors`](#errors).
- Files in `public/`, like `style.css`, are served by `php -S` as they are, except PHP files: `index.php` is the only PHP a request runs, so `/other.php` is a 404 even if `public/other.php` exists.

### In production

Point nginx at `public/`, and send every request that isn't a real file, or is for a PHP file, to `index.php` through php-fpm:

```nginx
server {
    root /var/www/my-site/public;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~* \.php$ {
        rewrite ^ /index.php last;
    }

    location = /index.php {
        include fastcgi.conf;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }
}
```

The middle block is for any other PHP file that ends up in `public/`: without it, nginx would send the file's source to whoever asks for it.

With Apache, make `public/` the document root, or on a host whose document root is fixed, like `public_html`, put what's in `public/` there and the rest of the site above it, with the paths in `index.php` changed to match. Then `public/.htaccess` does the same as the nginx blocks:

```apache
RewriteEngine On

RewriteCond %{REQUEST_FILENAME} !-f [OR]
RewriteCond %{REQUEST_URI} \.php(/|$) [NC]
RewriteRule ^ index.php [L]
```

Keep `handlers/`, your templates and `var/` outside the document root, as above. A file inside it can be requested by its own path, which would run a handler without its before-files, like a login check, or send what's in `var/`. So Tillkite reports any of them inside the document root as a [configuration problem](#configuration-problems), and the site answers with a 500 that says so until it's moved. Keep `routes.php` and `init.php` outside it too. See [Deploying](#deploying) for OPcache settings.

## Routes

The routes file returns an array of routes. Each route is `[ method, pattern, file ]`, with an optional fourth element for [route options](#route-options). Routes that share a prefix, like an admin area, can go in a [group](#groups). Put a login check on the group, not on its routes, so it also runs for the paths under the prefix that no route matches, and a visitor it turns away can't tell which ones exist.

```php
return [
	[ 'GET', '/', 'home.php' ],
	[ 'GET', '/users/new', 'users/new.php' ],
	[ 'GET', '/users/{id:\d+}', 'users/show.php' ],
	[ [ 'GET', 'POST' ], '/contact', 'contact.php' ],
	[ 'GET', '/blog[/{page:\d+}]', 'blog.php' ],
	[ 'GET', '/docs/{path:.+}', 'docs.php' ],
	[ 'GET', '/export.csv', 'export.php', [ 'buffer' => false ] ],
	[ 'prefix' => '/admin', 'before' => 'admin-before.php', 'routes' => [
		[ 'GET', '/{page}', 'admin.php' ],
	] ],
	[ 'GET', '/api/status', 'api-status.php', [ 'before_only' => 'api-before.php' ] ],
	[ 'POST', '/hooks/github', 'github.php', [ 'before' => false ] ],
	[ '*', '/webhook', 'webhook.php' ],
];
```

**The first route that matches wins**, reading from the top. When Tillkite compiles the list, it reports a route that can never match because one above it always matches first, like `/users/new` listed below `/users/{id}`. It always catches this for a route without parameters, and catches what it can when both routes have them.

### HTTP methods

- One method, like `'GET'`, or a list, like `[ 'GET', 'POST' ]`.
- `'*'` matches any method.
- A `GET` route also answers `HEAD`. The handler runs in full, and PHP leaves out the body.
- Methods are uppercase. The standard ones are allowed: `GET`, `HEAD`, `POST`, `PUT`, `PATCH`, `DELETE`, `OPTIONS`, `CONNECT` and `TRACE`. Add others with the [`methods`](#methods) option.

### Patterns

| Pattern | Matches | `$params` |
|---|---|---|
| `/users/new` | `/users/new` exactly. Case matters. | `[]` |
| `/users/{id}` | One path segment: `/users/42`, `/users/ada` | `[ 'id' => '42' ]` |
| `/users/{id:\d+}` | A segment that matches the regex: `/users/42`, but not `/users/ada` | `[ 'id' => '42' ]` |
| `/docs/{path:.+}` | The rest of the path, slashes and all: `/docs/guide/install` | `[ 'path' => 'guide/install' ]` |
| `/blog[/{page:\d+}]` | An optional part: `/blog` and `/blog/2` | `[]` or `[ 'page' => '2' ]` |

- Every pattern starts with `/`.
- `{name}` matches one segment: anything except `/`.
- `{name:regex}` uses your regex instead. Use `(?:...)` for groups, because capturing groups aren't allowed.
- A regex that allows `/`, like `.+`, makes a catch-all.
- Optional parts go in `[...]` at the end of a pattern, and can be nested: `/blog[/{year:\d{4}}[/{month:\d{2}}]]` matches `/blog`, `/blog/2026` and `/blog/2026/10`. A parameter that's left out isn't set in `$params`.
- Parameter values are always strings, and they're URL-decoded: `/hello/J%C3%BCrgen` gives `Jürgen`. An encoded slash, `%2F`, never creates another segment.
- A value that was encoded has to match its regex decoded too, so `{name:[^./]+}` turns away `%2E%2E` as it does `..`. It's a 404, even if a later route would take it. A regex that names `%`, like `(?:[a-z]|%[0-9A-F]{2})+`, is written for the encoded form, and is checked only that way.
- `{name}` is one segment decoded too: a value that decodes to a `/` or a `\`, like `a%2Fb`, is a 404, and so is one with a raw `\`, which nginx passes on, and Windows reads as a `/`. A regex written for the encoded form, as above, like `{name:(?:[^/%]|%[0-9A-Fa-f]{2})+}`, still takes them.
- No parameter takes a `..` segment or a null byte, whatever its regex, encoded or not: `/files/..`, `/files/%2E%2E` and `/files/a%00` are 404s, and so is `/docs/a/../b` for `/docs/{path:.+}`. So a value can't climb out of the directory a handler puts it in, or end a file name early. A browser takes `..` segments out of a URL before it sends it, so only a request made by hand has one. Dots that aren't a segment, like `a..b`, are fine.
- A regex of your own can still take what you didn't mean it to: `.+` takes a value that starts with `/`, like `/etc/passwd` from `/docs//etc/passwd`. For a file a parameter names, get its path from [`$tillkite->file()`](#files-named-by-the-request). Before you use one in a query or anything else that gives its characters meaning, check it, or use a regex that lists what's allowed, like `{name:[a-z0-9-]+}`.
- Non-ASCII text in a pattern, like `/café`, matches the encoded URL that browsers send.

### Route options

The fourth element of a route changes how it runs:

- **`'before' => 'file.php'`** runs that before-file after the app's [`before`](#before) file.
- **`'before_only' => 'file.php'`** runs only that before-file, and not the app's.
- **`'before' => false`** runs no before-files at all.
- **`'buffer' => false`** turns off output buffering for this route, so its output goes out as it's written, for streaming or large downloads. `'buffer' => true` turns it on when the [`buffer`](#buffer) option has turned it off.

A [group](#groups) takes the same options, for every route in it.

### Groups

A group puts a prefix in front of the pattern of each route in it, and can give them all a before-file, a `buffer` setting or error files of their own:

```php
return [
	[ 'GET', '/', 'home.php' ],

	[ 'prefix' => '/admin', 'before' => 'admin-before.php', 'routes' => [
		[ 'GET', '', 'admin/dashboard.php' ],
		[ 'GET', '/users', 'admin/users.php' ],
		[ 'GET', '/users/{id:\d+}', 'admin/user.php' ],
		[ 'POST', '/users/{id:\d+}', 'admin/user-save.php', [ 'before' => 'csrf.php' ] ],
	] ],

	[ 'prefix' => '/api', 'before_only' => 'api-before.php', 'errors' => [ 404 => 'api/error.php', 405 => 'api/error.php' ], 'routes' => [
		[ 'GET', '/status', 'api/status.php' ],
	] ],
];
```

The `/admin` group's routes match `/admin`, `/admin/users` and `/admin/users/42`, and each runs `admin-before.php` after the app's before-file. `POST /admin/users/42` then runs `csrf.php` too. [Before-files](#before-files) explains how they combine. The `/api` group's routes run only `api-before.php`, and its 404 and 405 pages come from `api/error.php`, which can send JSON.

A path under a group's prefix that no route matches, like `/admin/nope` or `/api/nope`, runs the before-files a route in the group with no options of its own would, and then gets the group's error page. So a visitor that `admin-before.php` turns away can't tell which `/admin` pages exist. A path is under a prefix when it's the prefix, or starts with it and a `/`. When a path is under more than one group's prefix, like `/api/admin/nope` under `/api` and `/api/admin`, the prefix with the most segments counts, wherever it is in the list, so a narrower group's login check covers its own misses. Prefixes with as many segments, like `/{lang}` and `/en`, count in the order of the list.

- **`prefix`** starts with `/` and doesn't end with one. It can have parameters, like `/users/{id:\d+}`, which every route in the group gets in `$params`. It can't have optional parts, which go at the end of a pattern.
- **A pattern in a group** starts with `/` or `[`, or is `''` for the prefix by itself. With [`trailing_slash`](#trailing_slash) set to true, that's `'/'`.
- **`before`, `before_only` and `buffer`** work as they do for a route. A route's own `buffer` wins over its group's.
- **`errors`** takes [error files](#error-files), as the [`errors`](#errors) option does, and they take the place of the app's for the codes it lists. They run for `error()` and uncaught exceptions in the group's routes, and for a 404 or 405 under its prefix.
- **Order:** a group's routes take the group's place in the list, so the first route that matches still wins, and a route that can never match is still reported, across groups.
- **A group can't go inside another group.**

Groups cost nothing when matching: Tillkite puts the prefix on each pattern when it compiles the routes, so they match as if each pattern were written out in full. [`route()`](#the-apps-methods) returns them that way too, like `[ 'GET', '/admin/users', 'admin/users.php' ]`, and [`group()`](#the-apps-methods) returns the group's prefix, like `'/admin'`, so a page can tell which section it's in.

### When nothing matches

When no route matches, Tillkite checks these in order:

1. **405:** the path matches a route for another method. The response is a 405 with an `Allow` header listing the methods it does take.
2. **308:** adding or removing the trailing slash gives a URL that matches. The response redirects there, keeping the query string. See [`trailing_slash`](#trailing_slash).
3. **404:** nothing matches at all.

The before-files for the path run first, and can send a response of their own instead. See [When no route matches](#when-no-route-matches), under Before-files. The 404 and 405 responses are plain text unless you set files for them with [`errors`](#errors). A request that matches a route never pays for these checks.

## Handlers

A handler is a plain PHP file. It writes its response with `echo` and `header()`, and its return value is ignored. Each handler has two variables:

- **`$params`:** the route's parameters, like `[ 'id' => '42' ]`.
- **`$tillkite`:** the app, for [`error()`](#showing-an-error-page), [`template()`](#templates), [`get()`](#sharing-values), [`route()`, `group()` and `timings()`](#the-apps-methods), or your own [helper methods](#adding-helper-methods).

Handlers run in a scope of their own. They don't see global variables, and nothing they set becomes global, but superglobals like `$_GET` and `$_POST` work as usual.

Output is buffered by default, so a handler can call `header()` or `http_response_code()` at any point, even after it has started writing the page.

To show an error page from a handler, for a user that doesn't exist, for example, return `$tillkite->error()` with the status code:

```php
<?php // handlers/users/show.php
declare( strict_types = 1 );

use Tillkite\Escape as Esc;

$user = find_user( $params['id'] );
if ( $user === null ) {
	return $tillkite->error( 404 );
}

echo '<h1>' . Esc::html( $user['name'] ) . '</h1>';
```

Tillkite throws away what the handler had written, and runs your 404 file, or sends a plain-text `404 Not Found`. See [Showing an error page](#showing-an-error-page).

### Before-files

A before-file runs before the handler, for what pages need first: security headers, a session, or a login check. There are three kinds, and they run in this order:

1. **The app's before-file**, set with the [`before`](#before) option, runs before every route's handler, and when no route matches.
2. **A group's before-file**, set with a [group's](#groups) `before` or `before_only` option, runs for every route in the group, and for the paths under its prefix that no route matches.
3. **A route's own before-file**, set with the [`before` or `before_only` route option](#route-options), runs only for that route, and not when no route matches. So a login check goes on a group, where it covers the misses under its prefix too.

A group's options, and then a route's, decide what runs after the before-files above them: the app's, for a group or a route outside one, or what its group runs, for a route in a group.

| Option | Before-files that run |
|---|---|
| None | The ones above it |
| `'before' => 'admin-before.php'` | The ones above it, then `admin-before.php` |
| `'before_only' => 'api-before.php'` | Only `api-before.php` |
| `'before' => false` | None |

So in the example in [Groups](#groups), `GET /admin/users` runs the app's before-file, then `admin-before.php`, and `POST /admin/users/42` runs those two and then `csrf.php`.

The before-files and the handler share one scope, so a variable set in one is there in every file after it. The app's before-file can start the session and find the user:

```php
<?php // handlers/before.php
declare( strict_types = 1 );

header( 'X-Content-Type-Options: nosniff' );
session_start();
$user = $_SESSION['user'] ?? null;
```

The `public/index.php` that `new` makes has already set the session cookie's options. See [Deploying](#deploying).

Then a group's or route's before-file, which runs after it, only has to check `$user`. If a before-file returns `false`, or leaves a status of 300 or more, as a `Location` header or `http_response_code( 403 )` does, nothing after it runs, not another before-file and not the handler, and the response is whatever the before-file sent:

```php
<?php // handlers/admin-before.php
declare( strict_types = 1 );

// before.php has already run, and set $user.
if ( $user === null ) {
	header( 'Location: /sign-in' );
	return false;
}
```

A group or route that doesn't want the app's before-file, like an API that doesn't use the session, uses `before_only` to run only its own, or `'before' => false` to run none.

#### When no route matches

The before-files run when no route matches too, before the 404, 405 or 308 response: the ones a route in the group with no options of its own would run, for a path under a [group's](#groups) prefix, or else the app's. So a login check covers every path under its prefix, and a visitor it turns away gets the same answer for `/admin/nope`, and for a `GET` of a route that takes only `POST`, as for `/admin/users`. Security headers from the app's before-file go out with the 404 page too.

If a before-file returns `false`, leaves a status of 300 or more or calls [`error()`](#showing-an-error-page), that's the response, without the 405's `Allow` header or the 308's `Location`. Otherwise, what they printed is thrown away, and the response goes ahead. While they run, [`route()`](#the-apps-methods) returns `null`, which tells a before-file that no route matched, [`group()`](#the-apps-methods) returns the group's prefix, and `$params` has the parameters of the group's prefix, like `id` for `/users/{id:\d+}`, or nothing.

[Error files](#error-files) run in a scope of their own, without the before-files' variables.

These are reported as [configuration problems](#configuration-problems), so they're caught on the first request:

- A group or route with both `before` and `before_only`.
- `'before_only' => false`. Use `'before' => false` to run no before-files.
- A `before` file that already runs, as the app's or the group's, which would run it twice.

### Showing an error page

A before-file or handler shows an error page with `error()`, for any standard 4xx code but 405:

```php
return $tillkite->error( 404 );
```

When the file that called it returns:

- No file after it runs: not another before-file, and not the handler.
- What the files have buffered is thrown away. Headers they set, like security headers from the app's before-file, stay.
- Tillkite sets the status and runs the file for that code from [`errors`](#errors), or from the route's [group's](#groups) `errors`, with the route's `$params`, in a scope of its own, so it doesn't see the handler's variables. With no file for that code, it sends plain text, like `403 Forbidden`.

`error()` returns `false`, so in a before-file, returning it stops the handler the way returning `false` always does:

```php
<?php // handlers/admin-before.php
declare( strict_types = 1 );

// before.php has already run, and set $user.
if ( $user === null || ! $user['is_admin'] ) {
	return $tillkite->error( 403 );
}
```

Without the `return`, no file after this one runs either, but the rest of this one does, and its work is wasted. So always return it.

- Only the first call counts.
- It works only in before-files and handlers. Anywhere else, like an error file, it does nothing, and logs a warning.
- A code it doesn't take, like 405 or 500, is logged, and the response is a 500. For a 500, throw an exception, and the [500 file](#error-files) gets it.
- On an [unbuffered](#buffer) route that has already sent output, the status can't change, so `error()` adds nothing, and logs a warning.

### Error files

The [`errors`](#errors) option sets a file for 404, 405, 500 and any other standard 4xx code. They work like handlers, with `$params` and `$tillkite`, and Tillkite has already set the status code. They run after the before-files, but in a scope of their own, without their variables. The 404 and 405 files run when no route matches, with the parameters of the group's prefix in `$params`, or none. The others run for [`error()`](#showing-an-error-page), with the route's `$params`.

A [group](#groups) can have error files of its own, with its `errors` key. Its routes, and the paths under its prefix that no route matches, use them for the codes it lists, and the app's for the rest. An API group can send JSON error pages this way, and the headers its before-file sends, like a JSON `Content-Type`, are already set when they run.

The 500 file runs when a before-file or a handler throws an exception it doesn't catch, and for Tillkite's own failures. It gets the exception in `$error`. Anything the page had already written is thrown away first, unless the route isn't buffered and the output has already gone out.

```php
<?php // handlers/500.php
declare( strict_types = 1 );

error_log( (string) $error );
echo '<h1>Something went wrong</h1>';
```

## Templates

A template is a PHP file that prints part of a page, like the layout around every page, or a blog post. `$tillkite->template()` runs one and returns what it printed, so a handler can `echo` it, or pass it to another template:

```php
<?php // handlers/post.php
declare( strict_types = 1 );

$post = find_post( $params['slug'] );
echo $tillkite->template( 'layout.php', [
	'title' => $post['title'],
	'content' => $tillkite->template( 'post.php', [ 'post' => $post ] ),
] );
```

```php
<?php // templates/post.php
declare( strict_types = 1 );

use Tillkite\Escape as Esc;
?>
<article>
<p>Posted on <?= Esc::html( $data['post']['date'] ) ?></p>
<?= $data['post']['html'] ?>
</article>
```

```php
<?php // templates/layout.php
declare( strict_types = 1 );

use Tillkite\Escape as Esc;

$title = Esc::html( $data['title'] );
?>
<!doctype html>
<html lang="en">
<title><?= $title ?></title>
<h1><?= $title ?></h1>
<?= $data['content'] ?>
```

Template names are relative to the [`templates`](#templates-1) directory, which is the handlers directory unless you set it:

```php
'templates' => __DIR__ . '/../templates',
```

Like a handler, a template runs in a scope of its own, with two variables:

- **`$data`:** the array passed to `template()`, or `[]`. A template reads each value by its key, like `$data['title']`, so it's always clear where a value came from.
- **`$tillkite`:** the app, so a template can run other templates, or call `route()` and `group()`, to mark the current page in a nav.

It doesn't see the handler's variables, like `$params`, or a before-file's. Pass what it needs in `$data`.

A template prints what it's given as it is, so escape everything that isn't already HTML with [`Tillkite\Escape`](#escaping), as the examples above do. `template()` works anywhere: in a before-file, handler or error file, in another template, or in `index.php`.

Each template's time is in [`timings()`](#the-apps-methods), as a step named after it, like `template:layout.php`, and in the [`Server-Timing`](#server_timing) header. A call takes about 0.46 µs, timing included.

If a template throws an exception, what it printed is thrown away, and the exception goes on to the code that called `template()`, or to your [500 file](#error-files). A template that doesn't exist is a fatal error, and PHP's message names the file it looked for.

A template's name is a `.php` file in the templates directory, or in a directory in it, like `posts/card.php`. Any other name runs nothing: one with a `..` segment, like `../.env` or `posts/../../secret.php`, one that doesn't end in `.php`, or one with a NUL byte. `template()` logs it, and returns `''`. So a name built from the request can't print or run a file outside the directory, but it can still run any template in it, so check it against the ones you mean to allow.

## Sharing values

Before-files, handlers, error files and templates each run in a scope of their own, so a variable in `index.php`, like a database connection, doesn't reach them. Register it with the app, and they get it from `$tillkite`:

```php
<?php // public/index.php
declare( strict_types = 1 );

require __DIR__ . '/../init.php'; // which sets $db

$tillkite = new Tillkite\App( [
	// ...
] );
$tillkite->register( 'db', $db );

return $tillkite->run();
```

```php
<?php // handlers/post.php
declare( strict_types = 1 );

$db = $tillkite->get( 'db' );
```

- Registering a name again replaces its value. `register()` works anywhere, so a before-file can register the visitor's account for the templates, which don't get the before-file's variables, as the handler does.
- For a name with nothing registered, `get()` logs it and returns `null`, so a misspelled name shows in the error log.
- `get()` can return anything, so your editor and static analysis don't know what `$db` is. Tell them with `/** @var PDO $db */` above it, or give your handlers a typed [helper method](#adding-helper-methods), like `db() : PDO`.
- What `init.php` or `index.php` makes, it makes for every request, so a database connection made there connects even for a page that doesn't use it.
- Each call to `register()` or `get()` takes about 0.02 µs.

## Files named by the request

When a parameter, or anything else from a request, names a file to read, `file()` turns the name into a path that's safe to open. It takes the directory the file has to be in, as an absolute path, and the name, and returns the file's real path, or `null` when no file by that name is inside the directory:

```php
<?php // handlers/docs.php, for [ 'GET', '/docs/{path:.+}', 'docs.php' ]
declare( strict_types = 1 );

$file = $tillkite->file( __DIR__ . '/../docs', $params['path'] . '.md' );
if ( $file === null ) {
	return $tillkite->error( 404 );
}

header( 'Content-Type: text/markdown; charset=utf-8' );
readfile( $file );
```

The name is the file's, or its path from the directory, like `guide/install.md`. Whatever the name holds, the path that comes back is a file inside the directory, with every symlink followed. `file()` returns `null` for:

- a file that isn't there, and a directory,
- a name with a `..`, `.` or empty segment, between `/` or `\`, so `../.env`, `/etc/passwd` and a stream wrapper like `php://filter/...`,
- a name with a null byte,
- a symlink that leads outside the directory, and a file under a directory that does.

Some things to know about `file()`:

- Open the path it returns, not the name you gave it, so the file you open is the file it checked.
- It answers the same for a file that's missing and one it won't give you, so a visitor can't tell which names exist outside the directory.
- PHP remembers the paths it has resolved for two minutes, so `file()` has it forget each part of the name first. A file swapped for a symlink a moment ago is seen as a symlink.
- It says where a file is, not what's in it or who may read it. A directory that holds one visitor's files next to another's still needs your own check.
- The directory has to be an absolute path, built from `__DIR__`. Another is logged, and gets `null`.
- It works anywhere, even before `run()`.

## Escaping

Text that goes in a page, like a name from a form or a parameter from the URL, has to be escaped for the place it goes, or a `<`, a quote or a `javascript:` URL in it can change the page or run a script. `Tillkite\Escape` has a method for each place. Give it a short name at the top of a template or handler:

```php
use Tillkite\Escape as Esc;
```

```php
<p><?= Esc::html( $name ) ?></p>
<input name="name" value="<?= Esc::html_attr( $name ) ?>">
<a href="<?= Esc::url( $link ) ?>">Profile</a>
<p style="font-family: '<?= Esc::css( $font ) ?>'">Hi</p>
<script>const user = <?= Esc::js( $user ) ?>;</script>
<button onclick="greet(<?= Esc::html_attr( Esc::js( $name ) ) ?>)">Hi</button>
```

Each method returns the escaped text, and never throws.

- **`html( $value )`** is for text between tags. It takes a string, a number, `null` or a `Stringable` object, and escapes `& < > " '`, so it's safe in a quoted attribute too.
- **`html_attr( $value )`** is for an attribute's value that's text, like `title`, `alt` or `value`. It's `html()`, and also escapes whitespace, `=` and the backtick, which can end an attribute that has no quotes, so it's safe with quotes or without. But without quotes, an empty value makes the browser take the next attribute as its value, so quote an attribute that can be empty. An attribute that holds a URL, CSS or code, like `href`, `style` or `onclick`, needs `url()`, `css()` or `html_attr( js() )` instead, and text in `srcdoc`, which holds a page, needs escaping twice: `Esc::html_attr( Esc::html( $text ) )`.
- **`url( $url, $schemes = [ 'http', 'https', 'mailto', 'tel' ] )`** is for a URL in `href`, `src`, `action` or another attribute. A URL with a scheme that isn't in `$schemes`, in lowercase, becomes `about:invalid`, so a `javascript:` or `data:` URL can't run. It checks the scheme the way browsers read it, in any case, with no control characters or spaces at the start and no tabs or newlines inside. A relative URL, like `/blog?page=2`, has no scheme, so it's allowed. Then it escapes the URL as `html_attr()` does. Build the parts of a URL first, with `rawurlencode()` or `http_build_query()`: `Esc::url( '/search?' . http_build_query( [ 'q' => $q ] ) )`. It doesn't stop a link to another site, so check the host of a URL you redirect to.
- **`js( $value )`** is for JavaScript. It turns any value into a JavaScript value, with its own quotes: a string, a number, `true`, `false`, `null`, an array, as a JavaScript array for a list and an object for other keys, or an object, with its public properties or what its `jsonSerialize()` returns. So don't put quotes around it, and build a string with `+` or a template literal's `${}`, like `'Hello, ' + <?= Esc::js( $name ) ?>`. Inside its strings, every character but ASCII letters and digits is escaped, so it can't end the string or the script, and the output is all ASCII. Even in quotes you put around it by mistake, it's an error, not code that runs. In an attribute, like `onclick`, escape it again with `html_attr()`, because the browser decodes the attribute before it runs the code. The output is JSON too, so it works in `<script type="application/json">`, read with `JSON.parse()`.
- **`log( $value )`** is for text in a log line, like a value you pass to `error_log()`. Control characters, which a terminal can take as commands, or which can start a line that looks like another entry, become escapes, like `\n` and `\033`, and so do the characters that make an editor show text in another order or on another line, like U+202E, as `\u{202E}`. Text that isn't valid UTF-8 has each byte outside ASCII escaped. Tillkite logs what it didn't write itself this way, and so does the 500 file that `new` makes: `error_log( Esc::log( (string) $error ) )`.
- **`css( $value )`** is for text in CSS, like a font's name in a CSS string, or a class name. Every ASCII character but letters and digits becomes an escape, like `\000020` for a space, and so does a digit at the start, since a name can't start with one. An escape takes in one space after it, so output that ends with an escape ends with a space, and a space you write after it stays. A colour, a size or a URL isn't text, and escaping breaks it, so `#fff` would become a name. Check those against what you allow instead, like `preg_match( '/^#[0-9a-f]{6}$/i', $colour ) === 1`.

Some things to know about `js()`:

- What JSON can't hold, like `INF` or a resource, is logged, and becomes `0` or `null`.
- An empty PHP array is `[]`, never `{}`.
- JavaScript numbers lose precision above 2^53, so send big integers, like some database IDs, as strings.
- A key named `__proto__` sets an object's prototype in JavaScript code, though not in `JSON.parse()`. So put data with keys from a visitor in `<script type="application/json" id="data">`, and read it with `JSON.parse( document.getElementById( 'data' ).textContent )`.
- A `Stringable` object is its public properties, like any other object, so pass `(string) $value` for its string. A backed enum is its value, and an enum without values is logged and becomes `0`.
- A float is written as `serialize_precision` says, which by default is as JavaScript writes it.

Don't put data from a request anywhere else: in a tag's or an attribute's name, in an HTML comment, or in JavaScript or CSS code outside a value. Escape a value where it's printed, once, so a template that gets HTML, like a layout's `$data['content']`, prints it as it is.

An invalid byte in UTF-8 text comes out as U+FFFD, `�`, as a browser shows it. So in `js()`, two keys that differ only in invalid bytes become the same key, and the later one wins.

### Other encodings

Escape takes text in UTF-8 unless you set another encoding, once, in `index.php`, by the name in the page's `Content-Type` header:

```php
Tillkite\Escape::set_encoding( 'Shift_JIS' );
```

It takes `UTF-8`, `ISO-8859-1`, `ISO-8859-2`, `ISO-8859-5`, `ISO-8859-7`, `ISO-8859-15`, `windows-1251`, `windows-1252`, `windows-1254`, `IBM866`, `KOI8-R`, `KOI8-U`, `Shift_JIS`, `EUC-JP`, `EUC-KR`, `GBK`, `gb18030` and `Big5`, in any case, and needs PHP's mbstring extension. Each method converts the text it's given from that encoding, and its output is all ASCII, with every other character escaped, like `&#12354;` for あ in HTML. That reads the same in each of these encodings. In some, like Shift_JIS, the second byte of a character can be a `\` or a backtick, so escaping their bytes one at a time isn't safe. `js()` converts the strings in arrays, but an object's strings have to be UTF-8 already. What isn't valid in the encoding comes out as mbstring's substitute character, `?` unless `mbstring.substitute_character` says otherwise. mbstring reads a few characters differently from browsers, mostly in Big5 and EUC-JP, so they can look different, but that never changes what the page does. `set_encoding()` returns `false`, and logs it, for an encoding it doesn't take, and the encoding stays as it was.

## Options

Pass options to the constructor, or set them as properties before calling `run()`:

```php
$tillkite = new Tillkite\App( [
	'routes' => __DIR__ . '/../routes.php',
	'cache' => __DIR__ . '/../var',
] );
$tillkite->server_timing = true;
```

| Option | What it does | Default |
|---|---|---|
| [`routes`](#routes-1) | The PHP file that returns the routes | Required |
| [`handlers`](#handlers-1) | The directory that handler, before and error files are in | The routes file's directory |
| [`templates`](#templates-1) | The directory that template names are relative to | The handlers directory |
| [`cache`](#cache) | The directory for the compiled route cache | None: the routes are compiled on every request |
| [`base`](#base) | The URL prefix the site lives under, like `/app` | None |
| [`trailing_slash`](#trailing_slash) | `false`: URLs never end in `/`. `true`: they always do. | `false` |
| [`buffer`](#buffer) | Buffers the output of the files that run | `true` |
| [`server_timing`](#server_timing) | Sends a `Server-Timing` header with Tillkite's timings | `false` |
| [`before`](#before) | A file that runs before every matched handler | None |
| [`errors`](#errors) | Files for 404, 405, 500 and other 4xx codes | Plain-text responses |
| [`methods`](#methods) | HTTP methods to allow besides the standard ones | None |

An unknown option, a value of the wrong type, or any other mistake in the options or routes is a [configuration problem](#configuration-problems), reported by `run()`.

### `routes`

The path to the PHP file that returns your routes. It's required.

`routes`, `handlers` and `cache` must be absolute paths, so build them from `__DIR__`. A relative path depends on the current directory, which is different under `php -S` and php-fpm.

### `handlers`

The directory that every file named in the routes, `before` and `errors` is relative to. It defaults to the directory the routes file is in. Every file is checked when the routes are compiled, so a misspelled file name shows up right away, not when someone visits that page.

### `templates`

The directory that the names passed to [`template()`](#templates) are relative to. It defaults to the handlers directory. Templates are run when they're called, so a misspelled name isn't found until then.

### `cache`

The directory for the compiled route cache. **Set it in production.** Without it, Tillkite compiles the routes on every request.

With it, the first request compiles the routes and writes them to a PHP file in this directory, and OPcache keeps that file in memory. After that, each request checks the routes file's modification time, change time and size, and the directory's permissions, with two `stat`s, and uses the cached routes. The cache rebuilds itself:

- **Change the routes file, a file it loads or a setting, or update Tillkite, and the next request rebuilds the cache.** There's no build step and nothing to clear when you deploy, even with a deploy that keeps files' modification times. Older cache files are removed. Each file that the routes file loads takes one more `stat` on every request, about 1.5 µs. For up to `opcache.revalidate_freq` seconds after one changes, the routes are compiled on every request, until OPcache is sure to have the new file.
- Routes with problems aren't cached, so problems are reported on every request until they're fixed.
- A cache directory that doesn't exist, or that PHP can't write to, is a [problem with the site's setup](#configuration-problems): every request gets a 500 that says so, until it's fixed. Without that, the routes would be compiled on every request, which takes milliseconds for a few hundred routes, with only a line in the log each time. A write that fails for another reason, like a full disk, is logged, and the routes are compiled for that request.
- Only files that run while the routes file does are noticed. If it reads routes from a JSON file, or finds files with `glob()`, or loads a file that was already loaded, like one `init.php` requires, a change to that isn't noticed until the routes file changes. Keep the routes in PHP files that only the routes file loads, so a before-file added to a route always takes effect.

The directory must exist, be writable by the web server, and be outside the document root, because the cache files are PHP. Inside it, it's a [configuration problem](#configuration-problems). It must not be writable by every user, since any of them could put PHP in it for the web server to run: Tillkite won't write its cache in a directory like that, even one with the sticky bit, like `/tmp`, or run a cache file it finds in one, and logs why. Give the web server's group access instead of using `chmod 777`, like `chgrp www-data var && chmod 775 var`. It must also belong to root, to the user PHP runs as, or to the owner of the routes file, for the same reason: a directory someone else made first, like one under `/tmp`, could already hold their file under the cache file's name. Telling which user PHP runs as takes PHP's posix extension. Without it, only root and the routes file's owner will do. A directory with any other owner is a [configuration problem](#configuration-problems) too, with a 500 page that says which of these to do, so a site never runs on quietly without its cache. Cache files are written as 0644, whatever the umask, and `new` never makes anything that every user can write to.

### `base`

The URL prefix your site lives under, when it isn't at the root of the domain. It starts with `/` and doesn't end with one.

With `'base' => '/app'`, the route `/users/{id}` matches `/app/users/42`, and the route `/` matches `/app`. A request outside `/app` is a 404. Redirects include the prefix.

### `trailing_slash`

Whether URLs end in `/`:

- **`false`** (the default): URLs never end in `/`. Write every pattern without one, like `/users/{id}`, and `/users/42/` redirects to `/users/42`.
- **`true`**: URLs always end in `/`. Write every pattern with one, like `/users/{id}/`, and `/users/42` redirects to `/users/42/`. A last segment with a dot, like `/feed.xml`, can leave it off.

A pattern that doesn't follow the setting is a configuration problem, so every page has one correct URL, and the redirect always goes to it.

### `buffer`

Whether the output of before-files, handlers and error files is buffered. It's `true` by default, which means:

- Headers and the status code can be set at any point, even after the page has started.
- An uncaught exception shows a clean 500 page, not half a page followed by an error.
- The [timings](#server_timing) cover the whole response.

Set it to `false` to send output as it's written for the whole site. To do that for one route, like a large download, use the [`buffer` route option](#route-options).

### `server_timing`

When `true`, every response has a `Server-Timing` header with the time, in milliseconds, of each step of the request:

```
Server-Timing: tillkite-total;dur=13.566, tillkite-route;dur=0.004, tillkite-before;dur=1.217, tillkite-handler;dur=12.345
```

| Step | What it measures |
|---|---|
| `total` | All of `run()`, until the response is finished |
| `route` | Loading the routes and finding the match |
| `before` | The before-files |
| `handler` | The handler |
| `error` | The error file |
| `template` | One [template](#templates), named in `desc`, like `tillkite-template;desc="layout.php"` |

A template's time is its own, without the templates it ran, and it's taken out of the step that ran it, so the steps still add up to the total. A template that runs more than once, like one for each row of a table, has the sum. Each template has an entry, so the header shows the names of the templates a page ran.

Browsers show it in the developer tools, on the Network tab under Timing. Your handlers can add their own entries with `header( 'Server-Timing: ...', false )`.

It's off by default, because timings that every visitor can see can give away information, such as a login check that's slower for an account that exists. Timing itself is always on, and costs well under a microsecond, so [`timings()`](#the-apps-methods) works either way.

### `before`

A file that runs before every route's handler, sharing its scope, and before the response when no route matches. A group or route can add its own before-file to run after this one with `before`, run only its own with `before_only`, or run none with `'before' => false`. See [Before-files](#before-files).

### `errors`

Files to run for error responses, relative to `handlers`:

```php
'errors' => [ 403 => '403.php', 404 => '404.php', 405 => '405.php', 500 => '500.php' ],
```

It takes 500 and the standard 4xx codes. Tillkite itself sends 404, 405 and 500, and the other 4xx codes come from [`error()`](#showing-an-error-page). Without a file for a code, Tillkite sends a plain-text response, like `404 Not Found`. Without a 500 file, an uncaught exception is a PHP fatal error: it's logged, and the visitor gets a plain-text 500. A [group](#groups) can have its own with its `errors` key. See [Error files](#error-files).

### `methods`

HTTP methods to allow in routes besides the standard ones, in uppercase:

```php
'methods' => [ 'PURGE' ],
```

## The app's methods

`Tillkite\App` has eleven methods:

- **`run() : bool`** handles the request. Under `php -S`, it returns `false` for a request for a real file in the document root that isn't PHP, so `php -S` serves the file. That's why `index.php` ends with `return $tillkite->run();`.
- **`route() : ?array`** returns the route that matched, as it's written in the routes file, or `null` when none did. A route in a [group](#groups) has the group's prefix on its pattern.
- **`group() : ?string`** returns the prefix of the [group](#groups) the matched route is in, as it's written in the routes file, like `'/admin'` or `'/users/{id:\d+}'`, or `null` when it isn't in one. When no route matched, it's the prefix of the group the path is under. A page can use it to mark its section, or log it. Checking the route's pattern with `str_starts_with()` instead would also match a route like `/administrators`, outside the `/admin` group, and `route()` is `null` when no route matched.
- **`uri() : string`** returns the request's URI as the web server sent it, with its query string, like `'/blog?page=2'`, and **`method() : string`** returns its method, like `'POST'`. They're `$_SERVER['REQUEST_URI']` and `$_SERVER['REQUEST_METHOD']`, without `$_SERVER`: PHP builds `$_SERVER` from everything the web server sent on every request that loads a file that names it, which takes about 4 µs, so a site that never names it is that much faster.
- **`timings() : array`** returns the milliseconds for each step that has run, like `[ 'total' => 13.566, 'route' => 0.004, 'handler' => 12.345 ]`. A step that's still running reports its time so far. Each [template](#templates) that ran has a step named after it, like `'template:layout.php' => 0.052`, with its own time, which isn't in the step that ran it.
- **`error( int $code ) : false`** shows the error page for a 4xx code, from a before-file or handler. See [Showing an error page](#showing-an-error-page).
- **`template( string $template, array $data = [] ) : string`** runs a template and returns what it printed. See [Templates](#templates).
- **`register( string $name, mixed $value ) : void`** makes a value, like a database connection, available to before-files, handlers, error files and templates. See [Sharing values](#sharing-values).
- **`get( string $name ) : mixed`** returns the value registered as `$name`, or `null`, which it logs, when there's none.
- **`file( string $directory, string $name ) : ?string`** returns the real path of the file named `$name` inside `$directory`, or `null` when there's none, for a name that comes from a request. See [Files named by the request](#files-named-by-the-request).

`route()`, `group()` and `timings()` are useful for logging. For example, a before-file can log slow requests from a shutdown function:

```php
register_shutdown_function( function() use ( $tillkite ) : void {
	$timings = $tillkite->timings();
	if ( $timings['total'] > 500 ) {
		error_log( 'Slow request: ' . json_encode( [ $tillkite->route(), $timings ] ) );
	}
} );
```

## Adding helper methods

To give your handlers helper methods, like sending JSON, extend `Tillkite\App` and create your class in `index.php`:

```php
class My_App extends Tillkite\App {
	public function json( mixed $value ) : void {
		header( 'Content-Type: application/json' );
		echo json_encode( $value );
	}
}
```

Handlers then call `$tillkite->json( [ 'ok' => true ] )`. A site with no helpers uses `Tillkite\App` directly. Don't reuse the names of `App`'s own methods: `run()`, `route()`, `group()`, `uri()`, `method()`, `timings()`, `error()`, `template()`, `register()`, `get()` and `file()`.

## Configuration problems

Tillkite never throws exceptions. Mistakes in the options or routes, like a misspelled option, a relative path, a handlers directory inside the document root, a handler file that doesn't exist, or a route that can never match, are collected, and `run()` responds with a 500:

- Under `php -S`, the response lists every problem, so you see them on the first page you load.
- Under php-fpm, each problem is written to the error log, starting with `Tillkite: `, and the 500 file runs, if there is one.

```
Tillkite found problems with its configuration:

- Unknown option: server_timeing
- Route 3 [ 'GET', '/users/{id}', 'users/profile.php' ]: the file doesn't exist: /var/www/my-site/public/../handlers/users/profile.php
```

A route is numbered by its place in the routes file. Route 4.2 is the second route in the group that's fourth.

A problem with how the site is set up on its server, like a `handlers` directory inside the document root, is one you have to fix before the site is safe to run, and may meet before you know where the server's error log is. So under php-fpm too, the response says what's wrong and what to do about it, and your 500 file doesn't run:

```
500 Internal Server Error

This site isn't set up safely, so Tillkite won't run it:

- The handlers directory is inside the document root, where a request can get or run its files directly, without the before-files. Move it outside the document root, and point the handlers option at it.

The error log has the details.
```

Every visitor gets that page until it's fixed, so it has no paths. The error log has each problem with its paths.

## Deploying

There's no build step. Copy the files to the server, and the first request after the routes file changes rebuilds the route cache.

**PHP's settings:** with no `php.ini`, PHP prints its errors on the page, with file paths and sometimes passwords.

- Outside `php -S` and the command line, `run()` turns off `display_errors`. To see errors on the page while you work on a site under a web server, turn it back on in a before-file, with `ini_set( 'display_errors', '1' );`.
- `run()` comes too late for what fails before it, like a database connection in `init.php`. So the `public/index.php` that `new` makes starts by setting `display_errors` and `html_errors` to `0`, `log_errors` and `zend.exception_ignore_args`, which keeps arguments out of stack traces, to `1`, and `session.cookie_httponly`, `session.cookie_samesite`, `session.cookie_secure` and `session.use_strict_mode`, and removes the `X-Powered-By` header. All of that takes about 0.5 µs a request. Take out `session.cookie_secure` for a site without HTTPS, or sign-ins won't stick. If you didn't start with `new`, copy those lines from [`example/public/index.php`](example/public/index.php) to the top of your front controller.
- Code can't cover everything. A parse error in `public/index.php` itself comes before any of its lines run, and PHP takes `expose_php`, `allow_url_fopen` and `allow_url_include` only from its configuration. A host that locks a setting, or disables `ini_set()`, has the last word. So set these in `php.ini` too, leaving `allow_url_fopen` on if your code reads URLs as files:

```ini
display_errors = Off
log_errors = On
expose_php = Off
allow_url_fopen = Off
allow_url_include = Off
```

**Copying files in place:**

- Keep OPcache's timestamp checks on: `opcache.validate_timestamps=1`, with `opcache.revalidate_freq` at 2 seconds or more. OPcache then checks each file at most once per interval.
- Copy handler files before the routes file, so a new route never points at a file that isn't there yet.

**One directory per release,** with a `current` symlink that switches to the new one:

- Turn OPcache's timestamp checks off: `opcache.validate_timestamps=0`.
- Have the web server pass the resolved path, so each release is cached under its own path. For nginx:

```nginx
root /var/www/current/public;
fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
fastcgi_param DOCUMENT_ROOT   $realpath_root;
```

- Give `opcache.memory_consumption` room for a few releases.

With Composer, install without development packages in production, like `composer install --no-dev --classmap-authoritative`. In Tillkite's load test, Composer's autoloader with its development packages took more time per request than all of Tillkite. The `dist/tillkite.php` file needs no autoloader at all.

## Speed

Measured on an Apple M3 Max with PHP 8.5 and OPcache. The full results are in [notes/SPEC.md](notes/SPEC.md#design-notes).

- **Finding the route,** including loading the cached routes, takes 0.2 to 0.5 µs, from 10 routes to 5,000. That's as fast as or faster than FastRoute, Symfony Routing and RadixRouter in every case measured, and on large route sets it's many times faster than FastRoute.
- **Under load,** with nginx and php-fpm serving the GitHub API's 1,259 routes, a Tillkite site using `dist/tillkite.php` handled about 90% of the requests per second of a PHP script that only prints `ok`. All of Tillkite's work, from `new App()` to the handler, takes about 9 µs per request.

To run the benchmarks yourself, clone this repository and run `composer install`, then `make bench`. `make load` runs the load test in Docker, with nginx, php-fpm and [oha](https://github.com/hatoo/oha), so it needs only Docker.

## The example site

The [`example/`](example/) directory is a small site that uses every feature, with notes on each page. To run it, clone this repository and run `composer install`, then:

```bash
make serve
```

Then open http://localhost:8417. `make serve-dist` runs the same site with `dist/tillkite.php` instead, at http://localhost:8418.

## Working on Tillkite

[notes/SPEC.md](notes/SPEC.md) is the design: how matching, the route cache and errors work, and why. Changes are listed in [CHANGELOG.md](CHANGELOG.md).

You need PHP 8.4 or later and Composer. `composer install` checks that PHP has the extensions the development tools need. Then:

- `make all` runs the style fixer, static analysis and the tests, against `src/` and `dist/tillkite.php`.
- `make serve` runs the example site at http://localhost:8417.
- `make bench` compares Tillkite's speed with other routers.
- `make help` lists the rest.

Four commands run in Docker, so they need nothing else installed. Any recent Docker should do, like Docker Desktop or OrbStack on a Mac, or Docker Engine on Linux:

- `make matrix` runs what CI checks, on PHP 8.4 and 8.5, on Linux: lint, static analysis, the tests, with the php-fpm tests, and that `dist/tillkite.php` is up to date. It's the way to try a PHP version you don't have, and Linux's case-sensitive file names.
- `make fpm` runs the tests that need nginx and php-fpm, which `make tests` skips: a site served as in production, where Tillkite reads the request without `$_SERVER`. `make matrix` runs them too. It uses PHP 8.5, and `make fpm PHP=8.4` picks another version.
- `make apache` runs the tests that need Apache with PHP as its module, mod_php, as many hosts run it, behind the `.htaccess` in [Deploying](#deploying), which `make tests` skips too. It uses PHP 8.5, and `make apache PHP=8.4` picks another version.
- `make load` is the HTTP load test, with nginx, php-fpm and oha. It uses PHP 8.5, and `make load PHP=8.4` picks another version. It times each row for 30 seconds, about ten minutes in all, and `make load LOAD_SECONDS=5` makes each one shorter.

They all work in a copy of the repository, so they never change your files. The first run builds an image for each PHP version and installs the Composer packages into a Docker volume. After that, they start in a few seconds. To remove them all:

```bash
docker image rm tillkite-dev:8.4 tillkite-dev:8.5
docker volume rm tillkite-vendor-8.4 tillkite-vendor-8.5
```

## License

MIT. See [LICENSE](LICENSE).
