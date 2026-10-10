# Changelog

## [2.1.0] - 2026-10-10
### Added
- `load()` option `requireNonEmpty`: a required key with an empty value fails (`Empty required key: …`).
- `load()` option `rejectRequestVariables` and `RequestVariableException` for keys the request may have set.
- Option `localOnly` for `parse()` and `load()`: a stream wrapper URL throws `FileNotFoundException`.
### Changed
- `parse()` reads the whole file before it parses a line: a failed read throws `FileNotReadableException` first.
- A replaced plain file wrapper is opened with mode `rbn` (was `rb`) and must report a regular file via `stream_stat()`.
### Fixed
- A local file swapped for a FIFO or device after the look at its name throws `FileNotFoundException`; it was read.
- For a local file, warnings no longer reach the application's error handler; they are the exception's previous.
- A required key whose value in `$_ENV` is no string throws `MissingRequiredKeyException`; it passed.
- A UTF-8 BOM after a line of whitespace is skipped; it threw `InvalidKeyException`.
### Security
- No file larger than 1 MiB is read (`FileNotReadableException`, `File too large: …`); it was read up to memory_limit.
- Messages escape control characters in required key names (`\x0A`).

## [2.0.1] - 2026-10-09
### Changed
- `MissingRequiredKeyException` names every missing required key (`Missing required keys: A, B`), not the first only.
- Opening decides whether a file is read, not `is_readable()`; a failure is `Could not read file: …`.
### Fixed
- A NUL byte inside a value throws `InvalidValueException`; it was accepted.
- Only one whole UTF-8 BOM is skipped; stray BOM bytes or a second BOM make line 1 invalid.
- An entry of `required` without a string form throws `\InvalidArgumentException`; it became the key `Array`.

## [2.0.0] - 2026-10-03
### Added
- `load()` returns the values of the file; a single CR ends a line.
- `InvalidLineException` for a line without `=`, `TrailingCharactersException` for text after a closing quote.
### Changed
- Requires PHP `^8.5` (1.x: `^8.2`); `EnvLoader` is `final`.
- Without `overwrite`, the process environment wins over the file and is copied into `$_ENV`, also for `required`.
- In an unquoted value a `#` after any whitespace starts a comment (1.x: after a space only).
- Whitespace and line endings are the same on every PHP version, locale and `auto_detect_line_endings` setting.
- `parse()` returns the whole file or throws `FileNotReadableException`; it never returns a part of it.
### Upgrading from 1.x
- Stay on `^1.1` until the application runs on PHP 8.5.
- Drop copying the process environment into `$_ENV` before `load()`; `$_ENV += EnvLoader::parse($p)` is the 1.x load.
- Replace `extends EnvLoader` with a class that calls `EnvLoader::load()`, `parse()` and `format()`.
- Catch `TrailingCharactersException` or `EnvLoaderException` where `UnterminatedQuoteException` was caught.
- Add `=` to lines without one; quote values with whitespace before `#`; a CR inside a value now ends the line.

Older versions: see the git tags.

[2.1.0]: https://github.com/SoDaHo/env-loader/compare/v2.0.1...v2.1.0
[2.0.1]: https://github.com/SoDaHo/env-loader/compare/v2.0.0...v2.0.1
[2.0.0]: https://github.com/SoDaHo/env-loader/compare/v1.1.1...v2.0.0
