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
