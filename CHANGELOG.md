# Changelog

All notable changes to Tillkite are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- The Composer package, `josephscott/tillkite`, MIT licensed, for PHP 8.4 and later.
- `Tillkite\Escape::log()` escapes text for a log line: control characters, C1 controls and the characters that reorder text become escapes like `\n`, `\033` and `\u{202E}`, so a value can't forge a log entry or send a terminal a command.
- `Tillkite\App` takes the `routes`, `handlers`, `trailing_slash`, `before`, `errors` and `methods` options. `run()` loads and checks the routes, and reports every problem with a 500: under `php -S` it lists them, and elsewhere it logs them and sends a plain 500, without them.
- `run()` matches the request and runs the route's handler, which sees only `$tillkite` and the decoded `$params`. A request that matches no route gets a plain 404.
- The `before` option's file runs ahead of every handler, in its scope, and ahead of the 404 when no route matches. A before-file that returns `false` stops the request there.
