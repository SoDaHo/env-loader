# Changelog

## [Unreleased]

Work on 2.0 (branch `2.x`). What breaks is collected under "Upgrading from 1.x" as it is built.

### Changed
- PHP `^8.5` is required (1.x: `^8.2`). CI also runs the tests on the pre-release of PHP 8.6.

### Upgrading from 1.x
- **PHP version:** `"sodaho/env-loader": "^1.1"` runs on PHP `^8.2`, `"^2.0"` needs PHP `^8.5`. Stay on `^1.1` until the application runs on PHP 8.5.

## [1.1.0] - 2026-10-02

### Added
- `format()` returns key-value pairs as .env content that `parse()` reads back unchanged.
- `InvalidValueException` for values `format()` cannot write (line break, NUL byte, not a string).

### Changed
- Parse errors (`InvalidKeyException`, `UnterminatedQuoteException`) name file and line, and the key for quote errors, instead of the content of the line.
- `load()` checks required keys before writing: after a `MissingRequiredKeyException`, `$_ENV` is unchanged.
- The key is validated before its value: a line with an invalid key and a broken quote throws `InvalidKeyException`.
- A read error reported by a stream wrapper no longer returns the values read so far: it throws `FileNotReadableException` (or a parse error, if the line it cut short is malformed).

### Fixed
- Large double-quoted values threw `UnterminatedQuoteException` (PCRE limit: from 8 KB with JIT, 50 KB without); values are now scanned without a regex.
- `KEY= # comment` resulted in the value `# comment` instead of an empty value. A value that starts with `#` after a space (`COLOR= #fff`) is therefore empty now and has to be quoted.
- Text after a closing quote was reported as an unterminated quote.
- `export` followed by a tab was not recognized; a key named `export` with spaces before `=` was rejected.
- Required keys given as array were not trimmed and empty entries not ignored, unlike the string form.
- A file outside `open_basedir` raised a warning in addition to the exception.

### Security
- Values from the file no longer appear in exception messages or in stack trace arguments (`#[\SensitiveParameter]`).

## [1.0.0] - 2026-03-15

### Added
- Load `.env` files into `$_ENV` superglobal.
- `parse()` method to get values without setting `$_ENV`.
- Support for double-quoted values with escape sequences (`\"`, `\\`).
- Support for single-quoted values (literal, no escaping).
- Inline comment support (` #` syntax).
- Required keys validation (array or comma-separated string).
- Overwrite option for existing `$_ENV` values.
- Exception hierarchy for granular error handling.
- UTF-8 BOM handling for Windows-created files.
- TOCTOU protection for file reading.
- PHPStan level 9 static analysis.
- GitHub Actions CI for PHP 8.2, 8.3, 8.4, 8.5.

[Unreleased]: https://github.com/SoDaHo/env-loader/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/SoDaHo/env-loader/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/SoDaHo/env-loader/releases/tag/v1.0.0
