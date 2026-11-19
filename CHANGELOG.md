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
