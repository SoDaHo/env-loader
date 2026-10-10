# Changelog

## [Unreleased]

## [2.1.0] - 2026-10-10

New options for `load()` and `parse()`, and a size limit. New parameters come after the existing ones, so positional calls keep working. What a caller may notice is listed under "Upgrading from 2.0.1".

### Added
- `requireNonEmpty` for `load()`: a required key also fails with an empty value. Checked is the value `load()` leaves in `$_ENV`, so also an empty variable of the environment that wins over the file (Docker Compose sets one where the host has no value) makes the load fail instead of leaving an empty secret; the empty variable still wins where nothing is required. The message names such keys as `Empty required key: A` (`Empty required keys: A, B`), after the missing ones, separated by `; `.
- `rejectRequestVariables` for `load()`: throws the new `RequestVariableException` before anything is written, where a key named like a request variable (`HTTP_*`, `REDIRECT_*`, a CGI meta-variable such as `CONTENT_TYPE`, `QUERY_STRING` or `REMOTE_USER`) would take its value from `$_ENV` while PHP fills `$_ENV` with the request (under PHP-FPM or CGI with an `E` in `variables_order`); a value equal to the variable of the process environment passes. Without an `E`, and on the command line, it has nothing to do.
- `localOnly` for `parse()` and `load()`: a URL that PHP would open with a stream wrapper (a scheme of two or more characters followed by `://`, such as `phar://`, `file://` or `ftp://`, or `data:`) throws `FileNotFoundException` with `Not a local file: …` before anything is done with it; a wrapper could read an archive or a file on another host where a path to a configuration file was meant. Without it, a URL is opened with its wrapper as in 2.0.x. The README recommends it for a file with secrets.
- `SECURITY.md`: how to report a vulnerability (GitHub's private vulnerability reporting), which versions get fixes (the latest release only), and what is outside the reach of the library.

### Changed
- `parse()` reads the whole file before it parses its first line (it parsed while reading): a read that fails or stops throws `FileNotReadableException` even where a line before it is malformed.
- Development (not part of the package): CI looks up the locked development tools in the security advisories (`composer audit`; an unreachable Packagist only warns), and a job fails after ten minutes instead of hanging.

### Fixed
- A local file is checked on what was opened (`fstat()`), no longer on its name before opening, and it is opened without blocking: a FIFO or device that replaced the file between the check and the opening was read, and a FIFO made `parse()` wait for a writer. It throws `FileNotFoundException` (`Not a file: …`) now, as a FIFO or device named in the first place does.
- For a local file, an error handler of the application that throws even for warnings suppressed with `@` no longer replaces the result of `parse()` and `load()` with its `ErrorException`: the warnings of opening, reading and closing it do not reach the handler, and a failure is reported as an `EnvLoaderException`. A URL is read as in 2.0.x: the warnings of its stream wrapper are suppressed with `@`, a handler that throws anyway and an exception of the wrapper (also from `stream_close()`) reach the caller. A handler of the library over the code of a wrapper, which may suspend a fiber, would remove a handler that another fiber installed meanwhile.
- A required key whose value in `$_ENV` is no string (set there by the application: `42`, `false`, `null`) throws `MissingRequiredKeyException` with `Required key without a string value: A` before anything is written; it passed, and the value stayed in `$_ENV`. The value of a key that is not required is still kept as it is.
- A UTF-8 BOM after a line of whitespace is skipped, as after an empty line: such a line is skipped like an empty one, but it threw `InvalidKeyException` for the BOM.
- README: "a request cannot overrule the file" is gone; it held only without an `E` in `variables_order`. The README asks for `variables_order = "GPCS"` under PHP-FPM and CGI (PHP uses `EGPCS` without a php.ini, as in the official Docker image) and names `rejectRequestVariables` as a guard.
- README: the content of `format()` is for `parse()`, not for a shell or another tool (`$`, backticks and `${…}` stay as they are: `source .env` runs `$(…)`, Docker Compose expands `${…}`, `docker run --env-file` keeps the quotes). The example for writing a file checks that `tempnam()` did not fall back to the temporary directory of the system, catches a write that stops short and removes the file with the values on failure.
- README: under plain CGI a variable the web server sets for the script (`SetEnv`) loses against the file, and a gateway without `GATEWAY_INTERFACE` lets the request win over it; under PHP-FPM `SetEnv` and `fastcgi_param` arrive with the request and are not read either. In a long-running worker a key removed from the file keeps its old value.
- README: `export` is followed by a space or a tab, as in a shell; after another whitespace (vertical tab, form feed) it is part of the key, which is invalid.
- The class documentation of `FileNotFoundException`, `FileNotReadableException`, `InvalidValueException` and `MissingRequiredKeyException` names every case that throws them (it described one each).

### Security
- No file larger than 1 MiB is read: it throws `FileNotReadableException` with `File too large: …`, counted while reading, so also for a stream that tells no size. A larger file was read up to the memory limit, and running out of memory was a fatal error whose backtrace could show the start of a line. A line is bounded by the file.
- Messages name required keys with control characters escaped (a line break as `\x0A`, NUL as `\x00`): a name passed in `required` with a line break added a line of its own to a log that writes the message.

### Upgrading from 2.0.1
Code that loads a local file of at most 1 MiB, with keys and required keys that are plain names, needs no change. A caller can notice these differences:
- **A file larger than 1 MiB:** `FileNotReadableException` with `File too large: …`, where 2.0.1 read it.
- **A required key with a value in `$_ENV` that is no string** (put there by the application): `MissingRequiredKeyException` with `Required key without a string value: …`, where 2.0.1 passed. Put a string into `$_ENV`, or let the file overwrite it (`overwrite: true`). Code that compares the message has to accept the parts joined by `; `.
- **An error handler that sees suppressed warnings, with a local file:** it no longer sees the warnings of opening, reading and closing the file. Where it turned them into an `ErrorException`, `parse()` and `load()` throw their `EnvLoaderException` now; where it only logged them, they are not logged. A URL is read as before.
- **A required key with a control character in its name:** the message shows it escaped (`\x0A`), where 2.0.1 showed it as it is.
- **A Unix socket as the path:** `FileNotReadableException` with `Could not read file: …` (it cannot be opened), where 2.0.1 threw `FileNotFoundException` with `Not a file: …`.
- **A read that fails after a malformed line** (an I/O error, a stream wrapper): `FileNotReadableException`, where 2.0.1 threw the error of that line, as the file is read to its end before a line is parsed.
- **A BOM after a line of whitespace:** the file is read, where 2.0.1 threw `InvalidKeyException`.

## [2.0.1] - 2026-10-09

Fixes and documentation, no new API. What a caller may notice is listed under "Upgrading from 2.0.0".

### Changed
- `MissingRequiredKeyException` names every missing required key, in the order given (`Missing required keys: A, B`), instead of only the first. The message for a single missing key is unchanged.
- A file that exists but cannot be opened (permissions) throws `FileNotReadableException` with the message `Could not read file: …` instead of `File not readable: …`; the class is the same. Whether the file can be read is decided by opening it, no longer by `is_readable()` first, which asked for the real user instead of the effective one: a file that `is_readable()` rejected but that can be opened is read now.
- Development (not part of the package): `composer.lock` is committed and CI checks it with `composer validate --strict`; CI installs php-cgi so the FastCGI and CGI tests run, and fails on a skipped test; PHPStan runs on level max; the actions are pinned to commits and the workflow may only read the repository. The tests are split by theme.

### Fixed
- A NUL byte inside a value of the file throws `InvalidValueException`, quoted or not (it was accepted, while `format()` rejects it). The message names the key and the line, never the value. NUL at either end of a value is whitespace and removed, as before. A file in UTF-16 no longer yields values with NUL bytes between their letters; it is still neither recognized nor reliably rejected.
- Only a whole UTF-8 BOM is skipped, and only one: the bytes `EF`, `BB` and `BF` at the start of the file were removed in any number and order, so a file starting with a stray byte of a BOM, or with two BOMs, passed. They throw for line 1 now: `InvalidKeyException`, or `InvalidLineException` where that line has no `=` (a comment line, say).
- An entry of `required` that has no string form (an array, an object without `__toString()`) throws `\InvalidArgumentException` naming its position. An array raised the warning "Array to string conversion" and was required as the key `Array`; such an object threw an `Error`.
- README: `FileNotFoundException` is also thrown for a path that is not a regular file (FIFO, device) or cannot be reached (`open_basedir`, directory permissions); the message does not tell these cases apart.
- README: the encoding is described (values are bytes, UTF-8 or ASCII; other encodings are not recognized), and production with the values in the environment of the process is no longer advised against: since 2.0 that environment wins over the file.
- README: "thread-safe by design" is narrowed to what holds: in a long-running worker, load the file once when the worker starts, from a fixed path. A second `load()` with another file mixes with the first; for values per tenant or request, `parse()` and an object of your own. The example for writing a file creates it with mode 0600 outside the web root and replaces the old one in one step.
- README: the path is trusted input (stream wrappers such as `phar://` are read), and messages name it as it was passed in.
- README and class documentation: the arguments holding raw lines or values are hidden from the stack traces of the exceptions this library throws. The backtrace PHP prints for a fatal error, such as memory running out on a huge line, can show the start of a line; `zend.exception_ignore_args=1` keeps the arguments out of it.
- README: the example that compares the environment with the file no longer warns when the key is not in the file.
- README: links to the upgrade guide from 1.x in this changelog, which the installed package does not contain.
- CHANGELOG: 1.0.0 was released on 2026-03-19 (the date of its tag), not on 2026-03-15.

### Upgrading from 2.0.0
Code that loads valid files with valid arguments needs no change. A caller can notice these differences:
- **NUL in a value:** `parse()` and `load()` throw `InvalidValueException` where 2.0.0 returned the value. `catch (EnvLoaderException $e)` catches it; code that catches the errors of the file class by class needs `InvalidValueException` as well.
- **Several missing required keys:** the message is `Missing required keys: A, B` (2.0.0 named the first one: `Missing required key: A`). With one missing key the message is unchanged; code that compares the message has to accept both forms.
- **Opening decides whether a file is read:** the check with `is_readable()` before opening is gone. In 2.0.0 a file that `is_readable()` reported as not readable threw `FileNotReadableException` with `File not readable: …`; now it is opened, and read where that works. The two can disagree where the real and the effective user differ, or for a stream wrapper whose stat shows no read permission. If opening fails, the message is `Could not read file: …`, which 2.0.0 already used where the check passed but opening failed; the class is the same.
- **Stray bytes of a BOM, or two BOMs, at the start of the file:** `InvalidKeyException` for line 1 (`InvalidLineException` where that line has no `=`, a comment line, say), where 2.0.0 read the file.
- **An entry of `required` without a string form** (an array, an object without `__toString()`): `\InvalidArgumentException`, which is no `EnvLoaderException` (2.0.0: a warning and the key `Array`, or an `Error` for such an object).

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

[Unreleased]: https://github.com/SoDaHo/env-loader/compare/v2.1.0...HEAD
[2.1.0]: https://github.com/SoDaHo/env-loader/compare/v2.0.1...v2.1.0
[2.0.1]: https://github.com/SoDaHo/env-loader/compare/v2.0.0...v2.0.1
[2.0.0]: https://github.com/SoDaHo/env-loader/compare/v1.1.1...v2.0.0
[1.1.1]: https://github.com/SoDaHo/env-loader/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/SoDaHo/env-loader/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/SoDaHo/env-loader/releases/tag/v1.0.0
