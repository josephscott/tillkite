# Changelog

All notable changes to Tillkite are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `make root` runs the tests that need root in Docker, so the test that the route cache won't use a directory that belongs to another user runs somewhere. `make matrix` and CI run it too.
- Tests that a miss's group is found even when PCRE fails, so its before-files run, that a before-file's redirect or `error()` replaces a 405 or 308 without its `Allow` or `Location`, that `HEAD` gets no body, and that handlers get the app's subclass.
- A test that a site made by `new` keeps PHP's own settings under `php -S`, with errors on the page and session cookies that work over plain HTTP, for the developer.

## [0.1.0] - 2026-11-19

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
- The site that `new` makes has a page with a route parameter, `/hello/{name}`, a before-file that sends `X-Content-Type-Options`, `Referrer-Policy` and `Content-Security-Policy: frame-ancestors 'none'`, and 404, 405 and 500 pages. The 500 page shows the error only under `php -S`, and logs it elsewhere.
- `new` ends with how to run the site under `php -S`, with a `cd` quoted for the shell, and an nginx server block for production that sends every request that isn't a real file, or is for a PHP file, to `index.php`, with the `root` quoted for nginx.
- `dist/tillkite.php`, every class in one file, for a site that doesn't use Composer: require it, then create a `Tillkite\App`. `make dist` builds it, and the tests run against it as well as `src/`.
- `php tillkite.php new my-site` makes a site with the dist file, which it copies into the site as `lib/tillkite.php`, for `public/index.php` to require, or uses where it is when it's that file already. Required, the dist file does nothing more.
- What Composer installs leaves out the tests and the tools for working on Tillkite, like `bin/dist.php`, so nothing in a site's `vendor/` but `bin/tillkite` can be run, and it does nothing for a web request.
- An example site, in `example/`, which `make serve` runs: static and dynamic routes, a before-file whose variable the handler gets, error pages, a layout made of templates, the route that matched on each page, and in development, a stack trace and the `Server-Timing` header.
- The example site's blog, a route with optional parts that gets its posts with `get()` and shows the 404 page with `error()`, its docs, which find a file for the rest of the path with `file()`, and its contact page, one route for GET and POST.
- The example site's CSV export, which isn't buffered, an `/admin` group whose before-file shows the 403 page without a key, an `/api` group with only its own before-file and JSON error files, a webhook that checks GitHub's signature with no before-file, and a route for any method.
- `make bench` compares Tillkite with FastRoute, Symfony Routing and RadixRouter, each with its route cache, on synthetic route sets of 10 to 5,000 routes and the GitHub and Stripe APIs, matching only and per request, and times Tillkite's own features. Every router has to agree on each match first.
- `make load` is an HTTP load test, with nginx, php-fpm and oha in Docker: each router, and a Tillkite site with Composer and with the dist file, serves the GitHub API's routes, with PHP alone as the floor, and Tillkite's own time comes from its `Server-Timing` header. Each run gets a new directory that only its user can use.
- `make fpm` runs tests under nginx and php-fpm, in Docker, against `src/` and the dist file: Tillkite reads the request without building `$_SERVER`, keeps PHP's messages and the site's paths off the page, and notices route edits through OPcache, and a site that `php tillkite.php new` makes answers its pages, keeps its copy of Tillkite out of reach, and logs the 500 page's exception instead of showing it. `PHP=8.4` picks the PHP version.
- `make apache` runs tests under Apache with mod_php, in Docker, behind an `.htaccess` that sends every request that isn't a real file, or is for a PHP file, to `index.php`.
- `make matrix` lints, analyzes and runs every test, the php-fpm tests too, on PHP 8.4 and 8.5, in Docker, and fails when the checkout's `dist/tillkite.php` or `App::SOURCE_HASH` is out of date.
- GitHub Actions runs the checks on every push and pull request, on PHP 8.4 and 8.5: lint, analysis, that `dist/tillkite.php` and `App::SOURCE_HASH` are up to date, every test, and the php-fpm and Apache tests in Docker. Its token can only read the repository.
- A route too large for PCRE to compile is reported once, without PCRE's offset, which points into Tillkite's joined regex, not the route's pattern, and differs between PCRE versions.
- The route cache's key has a hash of Tillkite's code, so a cache that other code wrote is never read, even between releases.
- The README: a quick start with `new`, by hand and in production, and every route, handler, template, escaping and option feature. `make apache` checks that its test site's `.htaccess` is the README's.
- `notes/SPEC.md`, the design: the project layout, code style, public API, how matching, the route cache and errors work, the benchmarks, and why.
- `AGENTS.md`, for AI coding tools: where the design is, and the rules for working on Tillkite, with `CLAUDE.md` pointing to it.
- `.claude/launch.json` starts `make serve` and `make serve-dist` for Claude Code's browser preview, and git ignores `.claude/settings.local.json`.
