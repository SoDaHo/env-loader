# env-loader

Lightweight .env file loader for PHP. Zero dependencies.

## Why This Library?

There are established .env loaders for PHP, most notably [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv). This library exists because we needed something simpler:

- **Zero dependencies** — Nothing to install besides this package.
- **Safe in async runtimes when loaded once** — Only writes to `$_ENV`, no `putenv()`, which is not thread-safe in async runtimes (Swoole, RoadRunner, FrankenPHP). Load the file once when the worker starts, from a fixed path (see [Why $_ENV Only?](#why-_env-only)).
- **No magic** — No variable expansion (`${VAR}`), no multiline values, no interpreted escape sequences (`\n`, `\t`). What you write is what you get.
- **Minimal footprint** — Easy to audit, easy to understand.

If you need variable expansion, multiline values, or loaded values that `getenv()` returns, use phpdotenv instead.

## Installation

```bash
composer require sodaho/env-loader
```

Upgrading: what to change is listed in the changelog under each release, [from 2.0.1](https://github.com/SoDaHo/env-loader/blob/main/CHANGELOG.md#upgrading-from-201) and [from 1.x](https://github.com/SoDaHo/env-loader/blob/main/CHANGELOG.md#upgrading-from-1x). It is not part of the installed package.

## Usage

### Basic Usage

```php
use Sodaho\EnvLoader\EnvLoader;

// Loads .env into $_ENV (the environment wins over the file, no required keys)
EnvLoader::load(__DIR__ . '/.env');

echo $_ENV['DB_HOST'];
```

### Options

```php
// Let the file win over the environment
EnvLoader::load('.env', overwrite: true);

// Require specific keys (throws exception if missing)
EnvLoader::load('.env', required: ['DB_HOST', 'DB_NAME']);

// Required keys as comma-separated string
EnvLoader::load('.env', required: 'DB_HOST,DB_NAME');

// Required keys whose values must not be empty either
EnvLoader::load('.env', required: ['JWT_SECRET'], requireNonEmpty: true);

// Throw where a request may have set a key through $_ENV (see variables_order below)
EnvLoader::load('.env', rejectRequestVariables: true);

// Combine options
EnvLoader::load('.env', overwrite: true, required: ['DB_HOST']);

// Read a local file only: a URL of a stream wrapper (phar://, file://, …) throws
EnvLoader::load(__DIR__ . '/.env', localOnly: true);
```

Without `overwrite`, the environment wins over the file. For every key of the file, `load()` uses the first value it finds:

1. the entry in `$_ENV`,
2. the variable of the process environment (`getenv($key, true)`),
3. the value of the file.

A value from the process environment is copied into `$_ENV`, so the application reads everything from there, whatever `variables_order` in php.ini says. A variable that is set but empty counts as set (for required keys, `requireNonEmpty` rejects it). Only keys named in the file or in `required` are copied; the rest of the process environment stays out of `$_ENV`.

The process environment is the environment of the PHP process itself: what Docker, systemd, the shell or `env[NAME]` in a PHP-FPM pool set. That includes what the system sets on its own (`PATH`, `HOME`, `USER`, `HOSTNAME`, in the official Docker image also `PHP_VERSION` and other `PHP_*`): a key of that name in the file loses against it.

What the web server passes with a request is not part of it. Under FastCGI (PHP-FPM) the request arrives as parameters (every header as `HTTP_*`, and request variables such as `CONTENT_TYPE`, `QUERY_STRING` and `REQUEST_URI`); `load()` does not read them as process environment. But with an `E` in `variables_order`, PHP itself fills `$_ENV` with them, under PHP-FPM and CGI, and `$_ENV` wins over the file: there a client sets a key named like a header (`HTTP_…`) or a request variable with a request of its own, also one in `required`.

**Under PHP-FPM and CGI, set `variables_order = "GPCS"`.** That is the value of php.ini-production and php.ini-development, but without a php.ini (the official Docker image ships none) PHP uses `EGPCS`. Set it in a php.ini or for the pool (`php_admin_value[variables_order] = GPCS`), and keep names like `HTTP_…` out of the file and out of `required`. As a guard on top, `rejectRequestVariables: true` makes `load()` throw `RequestVariableException` before it writes anything, where such a key would take its value from `$_ENV` while PHP fills `$_ENV` with the request (under PHP-FPM or CGI, with an `E` in `variables_order`); a value equal to the variable of the process environment passes. It knows the names `HTTP_*` and `REDIRECT_*`, the meta-variables of CGI/1.1 (`AUTH_TYPE`, `CONTENT_LENGTH`, `CONTENT_TYPE`, `GATEWAY_INTERFACE`, `PATH_INFO`, `PATH_TRANSLATED`, `QUERY_STRING`, `REMOTE_ADDR`, `REMOTE_HOST`, `REMOTE_IDENT`, `REMOTE_USER`, `REQUEST_METHOD`, `SCRIPT_NAME`, `SERVER_NAME`, `SERVER_PORT`, `SERVER_PROTOCOL`, `SERVER_SOFTWARE`), those nginx and Apache add (`DOCUMENT_ROOT`, `DOCUMENT_URI`, `FCGI_ROLE`, `HTTPS`, `REMOTE_PORT`, `REQUEST_SCHEME`, `REQUEST_URI`, `SCRIPT_FILENAME`, `SERVER_ADDR`) and those PHP derives from the request (`ORIG_PATH_INFO`, `ORIG_SCRIPT_NAME`, `PHP_AUTH_DIGEST`, `PHP_AUTH_PW`, `PHP_AUTH_USER`, `PHP_SELF`). A `fastcgi_param` of the web server with another name is configuration of the server, not of the client, and wins over the file as before.

What to know about the edges:

- **Plain CGI:** there the request is the environment of the process. `load()` recognizes it by `GATEWAY_INTERFACE`, which CGI/1.1 requires every gateway to set, and does not read the process environment; only `$_ENV` and the file count, as in 1.x. A variable the web server sets for the script (Apache `SetEnv`) is part of that environment and so loses against the file (where no `E` in `variables_order` has put it into `$_ENV`). A gateway that omits `GATEWAY_INTERFACE` is not recognized: there the request is read as process environment and wins over the file.
- **Configuration of the web server** (Apache `SetEnv`, nginx `fastcgi_param`) reaches PHP-FPM with the request, not as process environment: `load()` does not read it, and the file wins — unless an `E` in `variables_order` puts it into `$_ENV` (above). Values per deployment belong into the environment of the PHP process (`env[NAME]` in the pool, Docker, systemd).
- **PHP-FPM** clears the environment of its workers (`USER` and `HOME` remain) unless the pool says `clear_env = no` (the official Docker image does) or lists the variables with `env[NAME]`.
- **`getenv()` disabled** (`disable_functions`): the process environment is not read.

With `overwrite: true` the file wins: its values replace what `$_ENV` holds, whatever the process environment says.

A required key may come from the file, from `$_ENV` or from the process environment. Checked is the value `load()` leaves in `$_ENV`, and it has to be a string: a value of another type that the application put into `$_ENV` fails. An empty value counts, unless `requireNonEmpty: true` says otherwise. Then an empty value fails as well, also where an empty variable of the environment wins over the file: Docker Compose turns `JWT_SECRET: ${JWT_SECRET}` into an empty variable where the host has none, and the load fails instead of leaving an empty secret. `required` does not check whether a secret is long or strong enough; check that after loading. If required keys fail, the exception names all of them (`Missing required key: A; Empty required keys: B, C`). If a required key fails or the file cannot be parsed, `$_ENV` is left unchanged.

`load()` returns the values of the file, as `parse()` does — also those the environment has overruled:

```php
$file = EnvLoader::load('.env');

if (array_key_exists('APP_DEBUG', $file) && $_ENV['APP_DEBUG'] !== $file['APP_DEBUG']) {
    // The environment overrules the file
}
```

### Parse Without Loading

```php
// Returns array without setting $_ENV
$values = EnvLoader::parse('.env');

print_r($values);
// ['DB_HOST' => 'localhost', 'DB_NAME' => 'myapp', ...]
```

### Write .env Content

```php
// Returns the content as a string; every value is double-quoted and escaped
$content = EnvLoader::format(['DB_HOST' => 'localhost', 'DB_PASSWORD' => 'p@ss #1 "x"']);

// Outside the web root, readable only by its owner: tempnam() creates the file with mode 0600. Where $dir cannot be
// written, it creates the file in the temporary directory of the system instead (with a notice), hence the check
// of where it is; tempnam() reports the directory resolved (realpath). A write that stops short is caught by the
// length, and the file with the values is removed on failure. rename() replaces the old file in one step, so no
// reader sees a file half written
$dir = '/srv/app';
$tmp = tempnam($dir, '.env');
if ($tmp === false || dirname($tmp) !== realpath($dir)
    || file_put_contents($tmp, $content) !== strlen($content) || !rename($tmp, "$dir/.env")) {
    if ($tmp !== false) {
        @unlink($tmp);
    }
    throw new RuntimeException('Could not write .env');
}
```

`parse()` reads formatted content back unchanged, so a script that generates a .env file (e.g. a container entrypoint) should use it instead of writing `KEY=$value` lines itself. Values that are not strings or contain a line break or NUL byte throw `InvalidValueException`.

The content is for `parse()`, not for a shell or another tool: `$`, backticks and `${…}` stay as they are inside the double quotes. A shell that reads the file (`source .env`, `set -a; . ./.env`) runs `$(…)` and backticks and expands `$VAR`, Docker Compose expands `${…}` in an `env_file`, and `docker run --env-file` keeps the quotes as part of the value. Never let a shell read a file with values you do not control.

## Supported .env Syntax

```env
# Comments
DB_HOST=localhost

# Empty values
EMPTY_VAR=

# Values with equals sign
PASSWORD=val=ue=with=equals

# Double quotes (supports escaped quotes)
MESSAGE="Hello World"
ESCAPED="Say \"Hello\""

# Single quotes (no escape processing)
SINGLE='Hello World'

# Inline comments
API_KEY=secret123 # this is ignored
QUOTED="value with # hash" # comment outside quotes

# export prefix
export DB_PORT=3306

# Whitespace is trimmed
  SPACED_KEY  =  value
```

Details:

- **Inline comments:** in an unquoted value, a `#` preceded by whitespace (usually a space or a tab) starts a comment (`KEY= # note` is empty). A `#` after any other character is part of the value (`COLOR=#fff`, `KEY=a#b`). Quote values that contain ` #` — `PASSWORD=abc #123` is read as `abc`.
- **After a closing quote** only spaces, tabs (also vertical tabs and form feeds) and a comment may follow; the comment needs no space (`KEY="value"#note`). Anything else throws.
- **Double quotes:** only `\"` and `\\` are unescaped. Everything else stays literal, including `\n` and `\$`.
- **Single quotes:** literal, a single-quoted value cannot contain `'`.
- **Ignored lines:** empty lines and comment lines. Every other line has to be an assignment: a line without `=` throws.
- **Whitespace** around keys and values, and at both ends of a line, is removed: space, tab, vertical tab, form feed and NUL, the same on every PHP version.
- **`export`** is followed by a space or a tab, as in a shell: `export<VT>KEY=1` (vertical tab, form feed) throws `InvalidKeyException`.
- **NUL bytes** inside a value throw `InvalidValueException`, quoted or not: no environment value can hold one.
- **Duplicate keys:** the last one wins.
- **Size:** no file larger than 1 MiB (1 048 576 bytes) is read; a larger one throws `FileNotReadableException` (`File too large: …`). A line is bounded by the file, not by a limit of its own.
- **Files:** LF, CRLF or CR line endings, also mixed, each counting as one line; one UTF-8 BOM at the start of the first line that is not empty (a line of whitespace counts as empty) is skipped (only the whole three bytes). A value cannot contain a line break.
- **Encoding:** values are bytes, returned as they are in the file, without conversion. Write the file in UTF-8 or ASCII. Other encodings are not recognized: a value in Latin-1 comes back in Latin-1, and a file in UTF-16 is neither recognized nor reliably rejected.

## Exceptions

All exceptions extend `EnvLoaderException` for easy catching:

```php
use Sodaho\EnvLoader\EnvLoader;
use Sodaho\EnvLoader\Exception\EnvLoaderException;
use Sodaho\EnvLoader\Exception\FileNotFoundException;
use Sodaho\EnvLoader\Exception\MissingRequiredKeyException;

try {
    EnvLoader::load('.env', required: ['API_KEY']);
} catch (FileNotFoundException $e) {
    // File does not exist
} catch (MissingRequiredKeyException $e) {
    // Required key not found
} catch (EnvLoaderException $e) {
    // Any other EnvLoader error
}
```

| Exception | When |
|-----------|------|
| `FileNotFoundException` | File does not exist, is not a regular file (directory, FIFO, device) or cannot be reached (`open_basedir`, directory permissions), or, with `localOnly`, the path is a URL of a stream wrapper |
| `FileNotReadableException` | File exists but cannot be read, is larger than 1 MiB, or reading it failed or stopped before its end |
| `InvalidLineException` | Line is neither empty, a comment nor an assignment (no `=`) |
| `InvalidKeyException` | Key has invalid format (e.g. `123KEY`, `MY-KEY`) |
| `UnterminatedQuoteException` | Quoted value missing closing quote |
| `TrailingCharactersException` | Text other than a comment after the closing quote |
| `MissingRequiredKeyException` | Required key missing in file, `$_ENV` and process environment, its value in `$_ENV` is no string, or it is empty (`requireNonEmpty`) |
| `RequestVariableException` | `rejectRequestVariables`: a key named like a request variable would take its value from `$_ENV` while PHP fills `$_ENV` with the request |
| `InvalidValueException` | `parse()`, `load()`: a value contains a NUL byte. `format()`: value is not a string, or contains a line break or NUL byte |

The warnings PHP raises while a local file is opened and read (`open_basedir`, a failed open or read) do not reach an error handler of yours: a failure there is one of these exceptions, also where your handler throws even for warnings suppressed with `@`. A URL is read as in 2.0: the warnings of its stream wrapper are suppressed with `@`, so a handler that throws even for those throws from `parse()`, and an exception the wrapper throws, also from `stream_close()`, reaches you as it is. No error handler of the library stands over the code of a wrapper, which may suspend a fiber.

An entry of `required` that has no string form (an array, an object without `__toString()`) is a mistake in the code, not in the file: it throws `\InvalidArgumentException`, which is no `EnvLoaderException`.

Messages for errors in the file name the file, the line and, for quote errors and NUL bytes, the key — never a value, so a typo in a secret does not end up in logs. Messages about required keys and request variables name the keys with control characters escaped (a line break as `\x0A`): a name passed in `required` cannot add a line to a log. Arguments holding raw lines or values are hidden from the stack traces of these exceptions as well. One limit: the backtrace PHP itself prints for a fatal error (`fatal_error_backtraces`), such as memory running out, can show the start of a line or of the file. As no file larger than 1 MiB is read, parsing needs at most about 3 MiB beyond what the application uses, so that takes a `memory_limit` set that low; `zend.exception_ignore_args=1` (php.ini-production) keeps arguments out of the traces PHP generates for exceptions and fatal errors.

Messages name the path as it was passed in, so keep secrets out of the path. The path is trusted input: `parse()` and `load()` open it with `fopen()`, so a URL is opened with its stream wrapper where PHP can stat it (a `phar://` path is read like a file; `http://` or `php://filter` throw `FileNotFoundException`). For a file with secrets, pass `localOnly: true`: a URL that PHP would open with a stream wrapper — a scheme of two or more characters followed by `://` (`phar://`, `file://`, `ftp://`, …) or `data:` — then throws `FileNotFoundException` (`Not a local file: …`) before anything is done with it. Never build the path from a request.

## Key Naming Rules

Valid keys must:
- Start with a letter or underscore
- Contain only letters, numbers, and underscores

```
DB_HOST      ✓
_PRIVATE     ✓
API_KEY_2    ✓
123KEY       ✗ (starts with number)
MY-KEY       ✗ (contains hyphen)
MY KEY       ✗ (contains space)
```

## Why $_ENV Only?

This library intentionally writes **only to `$_ENV`**, not `putenv()` or `$_SERVER`.

**Reason: Thread Safety**

`putenv()` is **not thread-safe**. In modern PHP runtimes like:

- Swoole
- RoadRunner
- FrankenPHP
- ReactPHP

...concurrent requests can overwrite each other's environment variables, causing hard-to-debug race conditions.

`$_ENV` is process-local: no other process sees it, and nothing outside PHP changes it. Use `$_ENV['KEY']` instead of `getenv('KEY')` in your application.

**Load once per worker.** In a long-running worker (Swoole, RoadRunner, FrankenPHP in worker mode) `$_ENV` lives as long as the worker and is shared by every request it handles. Call `load()` once when the worker starts, with a fixed path. A second `load()` with another file does not replace the first: without `overwrite` the keys already in `$_ENV` keep their values, and keys that only the first file has stay, with `overwrite` as well — `load('a.env'); load('b.env');` mixes both, and a key removed from the file keeps its old value until the worker ends: a rotated secret needs a new worker. For configuration per tenant or per request, use `parse()` and keep the values in an object of your own instead of `$_ENV`.

`load()` reads the process environment (`getenv($key, true)`) to let its variables win over the file. It never changes it.

```php
// Safe
$host = $_ENV['DB_HOST'];

// Not recommended (not set by this loader)
$host = getenv('DB_HOST');
```

## When to Use

- Development environments
- Production where the environment of the process (Docker, systemd, `env[NAME]` in a PHP-FPM pool) sets the values: it wins over the file, which only fills the gaps
- Shared hosting where system ENV is not available
- Simple projects without framework
- **Modern async PHP** (Swoole, RoadRunner, FrankenPHP), loaded once when the worker starts

## When NOT to Use

- When you need variable expansion (`${OTHER_VAR}`)
- When you need multiline values
- When you must support legacy code using `getenv()`

## Security

Report a vulnerability privately, as the [security policy](https://github.com/SoDaHo/env-loader/blob/main/SECURITY.md) describes (it is not part of the installed package). Under PHP-FPM and CGI set `variables_order = "GPCS"`, pass a fixed local path with `localOnly: true`, and check secrets for length and form after loading.

## Requirements

- PHP ^8.5

## Acknowledgments

Parts of this project (refactoring, documentation, code review) were developed with AI assistance (Claude).

## License

MIT
