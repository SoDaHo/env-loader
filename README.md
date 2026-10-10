# sodaho/env-loader

Reads `.env` files into `$_ENV`, returns their values as arrays and formats arrays as `.env` content.

## Requirements

PHP ^8.5. No extensions.

## Installation

```bash
composer require sodaho/env-loader
```

## Quick start

```php
use Sodaho\EnvLoader\EnvLoader;

EnvLoader::load(__DIR__ . '/.env', required: ['DB_HOST', 'APP_KEY'], requireNonEmpty: true, localOnly: true);

$host = $_ENV['DB_HOST'];
```

## Reference

### `Sodaho\EnvLoader\EnvLoader` (final, static methods)

```php
load(string $path, bool $overwrite = false, array|string $required = [], bool $requireNonEmpty = false,
    bool $rejectRequestVariables = false, bool $localOnly = false): array
```

Parses the file, checks the options, writes the keys to `$_ENV` and returns the values of the file
(`array<string, string>`), also those the environment overrules. A `load()` that throws leaves `$_ENV` unchanged.

Without `overwrite`, each key takes the first value found: (1) `$_ENV`, (2) the process environment
(`getenv($key, true)`), (3) the file; with `overwrite: true`, the file. A value from the process environment is copied
into `$_ENV`, other variables stay out. The process environment is not read under plain CGI (`GATEWAY_INTERFACE` set),
where `getenv()` is disabled, and for names with `=` or NUL; FastCGI request parameters are no process environment.

`parse(string $path, bool $localOnly = false): array` returns the values of the file without writing `$_ENV`.

`format(array $values): string` returns `.env` content with every value double-quoted (`\` and `"` escaped), which
`parse()` reads back unchanged up to 1 MiB. Keys must be valid, values strings without a line break or NUL. Writing
the file is left to the caller. The content is for `parse()`, not for a shell: `$`, backticks and `${…}` stay.

### File syntax

| Line | Value |
|------|-------|
| `KEY=value` | `value`; whitespace around key and value is removed |
| `KEY=`, `KEY= # note` | empty string |
| `KEY=a=b` | `a=b` (split at the first `=`) |
| `KEY=value # note` | `value`; in an unquoted value a `#` after whitespace starts a comment |
| `KEY=#fff`, `KEY=a#b` | `#fff`, `a#b` (no whitespace before `#`) |
| `KEY="say \"hi\" \\ \n $X"` | `say "hi" \ \n $X`: only `\"` and `\\` are unescaped |
| `KEY='a \n $X'` | `a \n $X`: literal; cannot contain `'` |
| `KEY="v" # note`, `KEY="v"#note` | `v`; after a closing quote only spaces, tabs, VT, FF and a comment |
| `export KEY=v` | `v`; `export` followed by a space or tab is dropped; `export = 1` is the key `export` |
| `# comment`, empty line | skipped |

- **Keys:** letters, digits and `_`, not starting with a digit. Another line without `=` throws `InvalidLineException`.
- **Whitespace:** space, tab, LF, CR, VT, FF and NUL on every PHP version. **Line endings:** LF, CRLF, CR, mixed.
- **Not supported:** multiline values, variable expansion. A NUL byte inside a value throws `InvalidValueException`.
- **BOM:** one UTF-8 BOM on the first line that is not blank is skipped; single bytes of it or a second one are not.
- **Duplicate keys:** the last one wins. **Encoding:** values are the bytes of the file, not converted.
- **Size:** no file larger than 1 MiB (1 048 576 bytes) is read; counted while reading.

## Configuration

Options of `load()` (`localOnly` also of `parse()`). All checks run before anything is written to `$_ENV`.

| Option | Type | Default | Refused |
|--------|------|---------|---------|
| `overwrite` | `bool` | `false` | — |
| `required` | `array<string>\|string` | `[]` | an entry without a string form: `\InvalidArgumentException` |
| `requireNonEmpty` | `bool` | `false` | `true` without a required key: `\InvalidArgumentException` |
| `rejectRequestVariables` | `bool` | `false` | — |
| `localOnly` | `bool` | `false` | — |

- `required`: each key must end up in `$_ENV` with a string value, from the file, `$_ENV` or the process environment.
  A string is split at `,`; entries are trimmed, empty ones (also `null`, `false`) ignored. One exception names all.
- `requireNonEmpty`: the value of a required key must not be empty either, also where it comes from the environment.
- `rejectRequestVariables`: throws `RequestVariableException` where a key named like a request variable would take
  its value from `$_ENV` while PHP fills `$_ENV` with the request (not CLI, not phpdbg, and only with an `E` in
  `variables_order`). A value equal to the process environment passes. Checked are the keys of the file and the
  required keys, except keys of the file under `overwrite`. Known names: `HTTP_*`, `REDIRECT_*` and
  `AUTH_TYPE CONTENT_LENGTH CONTENT_TYPE GATEWAY_INTERFACE PATH_INFO PATH_TRANSLATED QUERY_STRING REMOTE_ADDR`
  `REMOTE_HOST REMOTE_IDENT REMOTE_USER REQUEST_METHOD SCRIPT_NAME SERVER_NAME SERVER_PORT SERVER_PROTOCOL`
  `SERVER_SOFTWARE DOCUMENT_ROOT DOCUMENT_URI FCGI_ROLE HTTPS REMOTE_PORT REQUEST_SCHEME REQUEST_URI`
  `SCRIPT_FILENAME SERVER_ADDR ORIG_PATH_INFO ORIG_SCRIPT_NAME PHP_AUTH_DIGEST PHP_AUTH_PW PHP_AUTH_USER PHP_SELF`.
- `localOnly`: a URL that PHP opens with a stream wrapper (a scheme of two or more characters and `://`, such as
  `phar://`, `file://`, `ftp://`, or `data:`) throws `FileNotFoundException` (`Not a local file: …`) unopened.

## Exceptions

All extend `Sodaho\EnvLoader\Exception\EnvLoaderException` (abstract, extends `\Exception`).

| Class | Thrown when |
|-------|-------------|
| `FileNotFoundException` | no such file, not a regular file, not reachable (`open_basedir`); `localOnly` and a URL |
| `FileNotReadableException` | the file cannot be opened, is larger than 1 MiB, or reading failed or stopped early |
| `InvalidLineException` | a line is neither empty, a comment nor an assignment |
| `InvalidKeyException` | a key is invalid (`format()` names its position only) |
| `UnterminatedQuoteException` | a quoted value has no closing quote |
| `TrailingCharactersException` | something other than a comment follows the closing quote |
| `InvalidValueException` | a NUL byte in a value; for `format()` also a value that is no string or has a line break |
| `MissingRequiredKeyException` | a required key is missing, has no string value in `$_ENV`, or is empty |
| `RequestVariableException` | `rejectRequestVariables` and a key the request may have set |
| `\InvalidArgumentException` | the arguments refused under Configuration; not an `EnvLoaderException` |

- Messages name the path, the line and, once valid, the key; never a value. Listed keys escape control bytes (`\x0A`).
- For a local file, a warning PHP raised is `getPrevious()` (an `ErrorException`) and stays in `error_get_last()`;
  it does not reach the error handler of the application. For a URL, warnings are suppressed with `@` only.

## Security

- `putenv()` and `getenv()` are not thread-safe; the library writes only `$_ENV`, never the process environment.
- Arguments holding values or lines are hidden from stack traces (`#[\SensitiveParameter]`). The backtrace PHP
  prints for a fatal error, such as memory running out, can show the start of a line; with at most 1 MiB read,
  that takes a `memory_limit` of up to about 18 MiB. `zend.exception_ignore_args=1` keeps arguments out of traces.
- The path is trusted input and appears in messages: without `localOnly`, a URL is opened with its stream wrapper.
  Never build the path from a request. Only regular files are read; a local file is checked again once opened.
- Under PHP-FPM and CGI with an `E` in `variables_order`, PHP fills `$_ENV` with the request, which then wins over
  the file and counts for `required`. Set `variables_order = "GPCS"`; `rejectRequestVariables` guards the names
  listed above. Names outside the list (such as `SSL_CLIENT_*`) are not checked: keep them out of the file.
- `required` checks presence, type and emptiness, not whether a secret is long enough.
- In a long-running worker `$_ENV` lives as long as the worker: load once when it starts, from a fixed path. A second
  `load()` does not remove the keys of the first. For values per tenant or request, use `parse()`.
- A replaced plain file wrapper (`stream_wrapper_unregister('file')`) runs under the error handler of the library.
  If it suspends a fiber while reading (not supported), a warning raised meanwhile goes to that handler and can become
  the previous of the exception; handlers installed meanwhile stay, the one of the library under the first of them.
- Report vulnerabilities as described in [SECURITY.md](https://github.com/SoDaHo/env-loader/blob/main/SECURITY.md).

## Testing

`composer ci` runs `composer cs` (PHP-CS-Fixer, dry run), `composer analyse` (PHPStan, level max) and
`composer test` (PHPUnit). The FastCGI and CGI tests need `php-cgi`, one test `phpdbg`, next to `PHP_BINARY`;
without them they are skipped. Two tests read `/proc/self/mem` and run on Linux only. No environment variables.

## Upgrading

See [CHANGELOG.md](https://github.com/SoDaHo/env-loader/blob/main/CHANGELOG.md).

## License

MIT

Parts of this project (refactoring, documentation, code review) were developed with AI assistance (Claude).
