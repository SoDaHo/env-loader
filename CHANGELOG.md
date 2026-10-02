# Changelog

## [Unreleased]

Work on 2.0 (branch `2.x`). What breaks is collected under "Upgrading from 1.x" as it is built.

### Added
- `load()` returns the values of the file, as `parse()` does — also those the environment has overruled.
- `InvalidLineException` for a line that is neither empty, a comment nor an assignment.
- `TrailingCharactersException` for text after a closing quote.
- A single CR ends a line, so files with CR line endings (and mixed ones) are read.

### Changed
- PHP `^8.5` is required (1.x: `^8.2`). CI also runs the tests on the pre-release of PHP 8.6.
- Without `overwrite`, the process environment wins over the file: a key that is not in `$_ENV` but set in the environment of the PHP process (`getenv($key, true)`) takes that value instead of the one from the file. The value is copied into `$_ENV`. What the web server passes with a request (FastCGI parameters, request headers) is not read; under plain CGI, where the request is the process environment, and where `getenv()` is disabled, the process environment is not read at all.
- `required` accepts a key that only the process environment defines, and copies it into `$_ENV`.
- `EnvLoader` is `final`.
- A line without `=` throws `InvalidLineException` instead of being ignored.
- Text after a closing quote throws `TrailingCharactersException` instead of `UnterminatedQuoteException`.
- In an unquoted value, a `#` after any whitespace starts a comment (1.x: only after a space).
- After a closing quote, whitespace is ASCII whitespace in every locale (1.x followed the locale, which let the byte `A0` pass on macOS).
- A form feed is whitespace around keys, values and required keys on every PHP version (1.x used the default of `trim()`, which includes it from PHP 8.6 only).
- Line endings are recognized the same way whatever `auto_detect_line_endings` says.
- `parse()` returns the whole file or throws `FileNotReadableException`: where a read fails, also if PHP reports the end of the file after it (an I/O error on a disk), and where a read brings no data before the file has ended. A line is never cut off there, never joined across it, and never reported as malformed for the part that was read.

### Upgrading from 1.x
- **PHP version:** `"sodaho/env-loader": "^1.1"` runs on PHP `^8.2`, `"^2.0"` needs PHP `^8.5`. Stay on `^1.1` until the application runs on PHP 8.5.
- **Environment before file:** code that copied the process environment into `$_ENV` before `EnvLoader::load('.env');` (1.x) becomes `EnvLoader::load('.env');` alone. The other way round: where a variable of the process environment is not in `$_ENV` (php.ini `variables_order` without `E`), 1.x used the value of the file and 2.0 uses the variable. `$_ENV += EnvLoader::parse('.env');` behaves like the 1.x `load()` without `required`; `overwrite: true` lets the file win over `$_ENV` as well. Two consequences: a key of the file named like a variable the system sets (`PATH`, `HOME`, `USER`, `HOSTNAME`) takes the value of the system now, and variables that are not keys of the file do not reach `$_ENV` through `load()` — name them in `required` (then they are copied) or read them with `getenv($key, true)`.
- **Required keys:** `EnvLoader::load('.env', required: ['TOKEN']);` threw `MissingRequiredKeyException` when only the process environment had `TOKEN` (1.x); it passes now and copies `TOKEN` into `$_ENV`.
- **Values of the file:** `load()` returned nothing (1.x) and returns the values of the file now: `EnvLoader::load($path); $file = EnvLoader::parse($path);` becomes `$file = EnvLoader::load($path);`.
- **Subclasses:** `class MyLoader extends EnvLoader { … parent::load($path); … }` (1.x) becomes `final class MyLoader { … EnvLoader::load($path); … }`: a class of your own that calls `EnvLoader::load()`, `parse()` and `format()`.
- **Lines without `=`:** a line such as `DB_PASSWORD secret` or `export DB_HOST` was skipped (1.x) and throws `InvalidLineException` now. Add the `=`, or turn the line into a comment with `#`. In a file shared with `docker run --env-file`, a bare `DB_HOST` means "take the value from the environment": remove the line and name the key in `required`, which takes it from the process environment.
- **Text after a closing quote:** `catch (UnterminatedQuoteException $e)` (1.x) no longer catches `KEY="value" text`; catch `TrailingCharactersException` as well, or `EnvLoaderException` for every error.
- **Whitespace before `#`:** `KEY=value<tab>#text` was the value `value<tab>#text` (1.x) and is `value` now, the same with a vertical tab, form feed or NUL before the `#`. Write `KEY="value<tab>#text"` to keep the `#`.
- **CR inside a line:** a value that contained a CR (1.x) ends at the CR now, and what follows is the next line: `KEY=a<CR>b` throws `InvalidLineException` for the line `b`, `KEY="a<CR>b"` throws `UnterminatedQuoteException`, `KEY=a<CR>OTHER=b` is two keys.
- **`auto_detect_line_endings`:** with that deprecated setting switched on, a file whose first line ending is a CR was read with CR as the only line ending (1.x): `A=1<CR>B=2<LF>C` gave `B` the value `2<LF>C`. Now LF ends the line there as well, and the line `C` throws `InvalidLineException`.
- **Form feed:** `KEY=value<FF>` was the value `value<FF>` and `<FF>KEY=value` an invalid key (1.x on PHP up to 8.5); both are `KEY` with the value `value` now.
- **Whitespace of the locale after a closing quote:** `KEY="value"<A0># note` was accepted where the locale counts the byte `A0` as whitespace (1.x, UTF-8 locales on macOS) and throws `TrailingCharactersException` now. Use a space or a tab.

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
