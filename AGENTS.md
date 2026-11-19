# Working on Tillkite

Tillkite is a small, fast PHP router that maps URLs to PHP files. These notes are for AI coding tools.

## Where to look

- [`notes/SPEC.md`](notes/SPEC.md) is the design: the project layout, code style, public API, how matching, the route cache and errors work, and why. Read it before changing anything.
- [`README.md`](README.md) is how a site uses Tillkite.
- [`CHANGELOG.md`](CHANGELOG.md) lists every change.

## Rules

- **Run `make all` before every commit,** and commit only when it passes. It runs the style fixer, which can rewrite files, so running only `analyze` or `tests` isn't enough. Commit only when asked to.
- **Every change adds a line to `CHANGELOG.md`,** under `## [Unreleased]`, in the same commit as the change.
- **Branches:** the base branch is `trunk`. Make each change on its own branch from `trunk`, and after committing, fast-forward `trunk` to it with `git switch trunk && git merge --ff-only <branch>`, so `trunk` always has the latest.
- **Tests must earn their place.** Add a test only when it catches a real regression no other test catches: one dataset row per distinct code path, not several that hit the same branch. The full run has to stay fast.
- **No `extract()`,** anywhere: not in `src/`, the example site, the starter's templates, tests or docs. Give a file one named array, like `$params` or `$data`.
- **Security scope:** this version doesn't handle CSRF, a content CSP, HSTS or sessions, and `php -S` is always development mode, with verbose errors and stack traces. Don't report these as gaps.
