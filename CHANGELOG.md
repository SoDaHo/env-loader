# Changelog

## [Unreleased]

### Changed
- `MissingRequiredKeyException` names every missing required key, in the order given (`Missing required keys: A, B`), instead of only the first. The message for a single missing key is unchanged.
- A file that exists but cannot be opened (permissions) throws `FileNotReadableException` with the message `Could not read file: …` instead of `File not readable: …`; the class is the same. Whether the file can be read is decided by opening it, no longer by `is_readable()` first, which asked for the real user instead of the effective one.

### Fixed
- A NUL byte inside a value of the file throws `InvalidValueException`, quoted or not (it was accepted, while `format()` rejects it). The message names the key and the line, never the value. NUL at either end of a value is whitespace and removed, as before. A file in UTF-16 no longer passes with values that hold NUL bytes.
- An entry of `required` that has no string form (an array, an object without `__toString()`) throws `\InvalidArgumentException` naming its position. An array raised the warning "Array to string conversion" and was required as the key `Array`; such an object threw an `Error`.
- README: `FileNotFoundException` is also thrown for a path that is not a regular file (FIFO, device) or cannot be reached (`open_basedir`, directory permissions); the message does not tell these cases apart.
- README: the encoding is described (values are bytes, UTF-8 or ASCII; other encodings are not recognized), and production with the values in the environment of the process is no longer advised against: since 2.0 that environment wins over the file.
- README: "thread-safe by design" is narrowed to what holds: in a long-running worker, load the file once when the worker starts, from a fixed path. A second `load()` with another file mixes with the first; for values per tenant or request, `parse()` and an object of your own. The example for writing a file creates it with mode 0600 outside the web root and replaces the old one in one step.
- README: the path is trusted input (stream wrappers such as `phar://` are read), and messages name it as it was passed in.
- README: the example that compares the environment with the file no longer warns when the key is not in the file.
- README: links to the upgrade guide from 1.x in this changelog, which the installed package does not contain.
- CHANGELOG: 1.0.0 was released on 2026-03-19 (the date of its tag), not on 2026-03-15.
- Only a whole UTF-8 BOM is skipped, and only one: the bytes `EF`, `BB` and `BF` at the start of the file were removed in any number and order, so a file starting with a stray byte of a BOM, or with two BOMs, passed. They throw `InvalidKeyException` for line 1 now.

## [2.0.0] - 2026-10-03

Breaks compatibility with 1.x; what to change is listed under "Upgrading from 1.x".

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

## [1.1.1] - 2026-10-02

### Fixed
- A read error on a file on disk that persists (an I/O error), after which PHP reports the end of the file, returned the lines read so far as if they were the whole file. It throws `FileNotReadableException` now, if a second read fails as well. Not covered: an error that does not repeat, and a read error that cuts a line short, which still lets that line through as it was read; 2.0 reads differently and closes those as well.
- README: the advice to copy all of `getenv()` into `$_ENV` is gone. Under PHP-FPM that copies request headers into `$_ENV`, where they win over the file; the README shows how to copy only the keys of the file.

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

## [1.0.0] - 2026-03-19

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

[Unreleased]: https://github.com/SoDaHo/env-loader/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/SoDaHo/env-loader/compare/v1.1.1...v2.0.0
[1.1.1]: https://github.com/SoDaHo/env-loader/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/SoDaHo/env-loader/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/SoDaHo/env-loader/releases/tag/v1.0.0
