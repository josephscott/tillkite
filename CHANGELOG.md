# Changelog

All notable changes to Tillkite are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- The Composer package, `josephscott/tillkite`, MIT licensed, for PHP 8.4 and later.
- `Tillkite\Escape::log()` escapes text for a log line: control characters, C1 controls and the characters that reorder text become escapes like `\n`, `\033` and `\u{202E}`, so a value can't forge a log entry or send a terminal a command.
- `Tillkite\App` takes the `routes`, `handlers`, `trailing_slash`, `before`, `errors` and `methods` options. `run()` loads and checks the routes, and reports every problem with a 500: under `php -S` it lists them, and elsewhere it logs them and sends a plain 500, without them.
- `run()` matches the request and runs the route's handler, which sees only `$tillkite` and the decoded `$params`. A request that matches no route gets a plain 404.
- The `before` option's file runs ahead of every handler, in its scope, and ahead of the 404 when no route matches. A before-file that returns `false` stops the request there.
- A miss gets a 404, a 405 with an `Allow` header, or a 308 to the other trailing slash form, keeping the query string. The `errors` option's 404 and 405 files run in place of the plain text. A redirect that would leave the site, to `//` or `/\`, gets a 404 instead, and the `Location` percent-encodes anything that isn't printable ASCII.
- The `buffer` option, on by default, buffers the output of the files that run, so a handler can send headers after it. A miss's before-file is always buffered, and its output is thrown away unless it stops the request.
- `$tillkite->error( 404 )`, from a before-file or handler, stops the files after the one that called it, throws away what they buffered and shows the error file for that code, with the route's `$params`, or plain text. It takes a 4xx code other than 405; any other is logged and gets a 500.
- A before-file that redirects or sets an error status, like a login check with only `header( 'Location: /sign-in' )`, stops the request there, even when it doesn't return `false`. A status under 300 goes on.
- The `errors` option's 500 file runs for an exception that the files don't catch, with `$params` and the `$error`, after throwing away what they buffered. It also runs, with an `$error` that describes it, when matching the request fails, for an `error()` code it doesn't take, and for configuration problems outside `php -S`.
- A fatal error, like running out of memory, or an uncaught exception with no 500 file, gets a 500 with one line of text in place of what was buffered. When PHP displays errors, its message stays, with the 500 status.
- The `cache` option names a directory for the compiled routes, so they're compiled once, and again when the routes file, a file it loaded or a setting changes. Routes with problems are never cached.
- A cache directory that doesn't exist, that PHP can't write to, or that belongs to a user Tillkite doesn't trust is a problem with the site's setup. Outside `php -S`, the response says what's wrong without any paths, which go to the error log, and the 500 file doesn't run.
- The `base` option mounts the app under a URL prefix, like `/app`. It's removed from the path before matching, a path outside it gets a 404, and redirects keep it.
- Under `php -S`, `run()` returns `false` for a file in the document root, so `php -S` serves it, unless it's a PHP file, which is routed like any other path, so the front controller is the only PHP a request runs.
- A handlers or cache directory inside the document root, where a request could get or run its files directly, is a problem with the site's setup.
- `$tillkite->uri()` and `$tillkite->method()` return the request's URI and method, without building `$_SERVER`, and `$tillkite->route()` returns the matched route as written in the routes file.
- `$tillkite->register( 'db', $db )` makes a value available to the files that run, which get it with `$tillkite->get( 'db' )`. A name with nothing registered is logged, and gets null.
- `$tillkite->file( $directory, $name )` returns the real path of a file inside the directory, for a name from a request, or null for any name that could reach outside it: an empty, `.` or `..` segment, a null byte, a directory, or a symlink that leads outside.
- `$tillkite->template( 'post.php', [ 'post' => $post ] )` runs a PHP file from the `templates` directory, which defaults to the handlers directory, in a scope with only `$tillkite` and `$data`, and returns what it printed. A name with a `..` segment, a null byte or no `.php` runs nothing, and is logged. A templates directory inside the document root is a problem with the site's setup.
- `$tillkite->timings()` returns the milliseconds for each step of the request so far: the total, route, before, handler, error, and each template, as a step of its own.
- The `server_timing` option sends the steps that have finished in a `Server-Timing` header, like `tillkite-handler;dur=1.234`, with a template's name as its metric's description. Without buffering, it goes out before the handler or error file can send the headers.
- Route options, as a route's fourth element: `buffer` overrides the app's buffer option, `before` adds a before-file after the app's, `before_only` runs one in its place, and `'before' => false` runs none. A before-file that runs already, so it would run twice, is a problem.
- Route groups: `[ 'prefix' => '/admin', 'routes' => [ ... ] ]` joins the prefix to each route's pattern, and can take `buffer`, `before`, `before_only` and `errors` for its routes. A path under the prefix that no route matches runs the group's before-files and gets its error files, and `$tillkite->group()` returns the prefix.
- `Tillkite\Escape::html()`, `html_attr()` and `url()` escape a value for a UTF-8 page: between tags, in an attribute's value, quoted or not, and in a link, where a scheme other than `http`, `https`, `mailto` or `tel`, like `javascript:`, becomes `about:invalid`. Invalid UTF-8 becomes U+FFFD.
- `Tillkite\Escape::js()` turns any value into JSON that's safe in a `<script>`, with only letters, digits and escapes in its strings, and logs what JSON can't hold, like `INF`. `Tillkite\Escape::css()` escapes text for a CSS string or name, like a font's.
- `Tillkite\Escape::set_encoding( 'Shift_JIS' )` sets the page's encoding, by its WHATWG name, for a page that isn't UTF-8. It takes 17 of them, and needs the mbstring extension. Each method then converts its text to UTF-8, checks a URL's scheme after that, and gives output that's all ASCII. An encoding it doesn't take is logged, and the encoding stays as it was.
- `vendor/bin/tillkite new my-site` makes a site: `init.php`, for what the front controller and any scripts share, a front controller that sets production settings outside `php -S`, routes, a layout template and a home page, with the route cache in `var/`. It never overwrites a file, or writes through a symlink, and nothing it makes is writable by every user.
