# env-loader

Lightweight .env file loader for PHP. Zero dependencies.

## Why This Library?

There are established .env loaders for PHP, most notably [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv). This library exists because we needed something simpler:

- **Zero dependencies** — Nothing to install besides this package.
- **Thread-safe by design** — Only writes to `$_ENV`. No `putenv()`, which is not thread-safe in async runtimes (Swoole, RoadRunner, FrankenPHP).
- **No magic** — No variable expansion (`${VAR}`), no multiline values, no interpreted escape sequences (`\n`, `\t`). What you write is what you get.
- **Minimal footprint** — Easy to audit, easy to understand.

If you need variable expansion, multiline values, or loaded values that `getenv()` returns, use phpdotenv instead.

## Installation

```bash
composer require sodaho/env-loader
```

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

// Combine options
EnvLoader::load('.env', overwrite: true, required: ['DB_HOST']);
```

Without `overwrite`, the environment wins over the file. For every key of the file, `load()` uses the first value it finds:

1. the entry in `$_ENV`,
2. the variable of the process environment (`getenv($key, true)`),
3. the value of the file.

A value from the process environment is copied into `$_ENV`, so the application reads everything from there, whatever `variables_order` in php.ini says. A variable that is set but empty counts as set. Only keys named in the file or in `required` are copied; the rest of the process environment stays out of `$_ENV`.

The process environment is the environment of the PHP process itself: what Docker, systemd, the shell or `env[NAME]` in a PHP-FPM pool set. That includes what the system sets on its own (`PATH`, `HOME`, `USER`, `HOSTNAME`, in the official Docker image also `PHP_VERSION` and other `PHP_*`): a key of that name in the file loses against it.

What the web server passes with a request is not part of it. Under FastCGI (PHP-FPM) the request arrives as parameters (every header as `HTTP_*`, and request variables such as `CONTENT_TYPE`, `QUERY_STRING` and `REQUEST_URI`), and `load()` does not read them, so a request cannot overrule the file. What to know about the edges:

- **Plain CGI:** there the request is the environment of the process. `load()` recognizes it by `GATEWAY_INTERFACE`, which CGI/1.1 requires every gateway to set, and does not read the process environment; only `$_ENV` and the file count, as in 1.x. A gateway that omits the variable is not recognized.
- **PHP-FPM** clears the environment of its workers (`USER` and `HOME` remain) unless the pool says `clear_env = no` (the official Docker image does) or lists the variables with `env[NAME]`.
- **`variables_order` with `E`** makes PHP fill `$_ENV` itself, under PHP-FPM and CGI with the parameters of the request. `$_ENV` wins over the file, so there a request can set a key named like a header (`HTTP_…`) or a request variable. `E` is the default when no php.ini is loaded, as in the official Docker image; use `GPCS` (php.ini-production and php.ini-development), or keep such names out of the file.
- **`getenv()` disabled** (`disable_functions`): the process environment is not read.

With `overwrite: true` the file wins: its values replace what `$_ENV` holds, whatever the process environment says.

A required key may come from the file, from `$_ENV` or from the process environment; an empty value counts. If a required key is missing or the file cannot be parsed, `$_ENV` is left unchanged.

`load()` returns the values of the file, as `parse()` does — also those the environment has overruled:

```php
$file = EnvLoader::load('.env');

if ($_ENV['APP_DEBUG'] !== $file['APP_DEBUG']) {
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

file_put_contents('.env', $content);
```

`parse()` reads formatted content back unchanged, so a script that generates a .env file (e.g. a container entrypoint) should use it instead of writing `KEY=$value` lines itself. Values that are not strings or contain a line break or NUL byte throw `InvalidValueException`.

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
- **Duplicate keys:** the last one wins.
- **Files:** LF, CRLF or CR line endings, also mixed, each counting as one line; a UTF-8 BOM is skipped. A value cannot contain a line break.

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
| `FileNotFoundException` | File does not exist or is a directory |
| `FileNotReadableException` | File exists but cannot be read, or reading it failed or stopped before its end (an error handler of yours that throws even for messages suppressed with `@` throws instead; if it throws while the file is closed, you get that exception, whatever `parse()` would have returned or thrown, with an exception thrown before as its previous) |
| `InvalidLineException` | Line is neither empty, a comment nor an assignment (no `=`) |
| `InvalidKeyException` | Key has invalid format (e.g. `123KEY`, `MY-KEY`) |
| `UnterminatedQuoteException` | Quoted value missing closing quote |
| `TrailingCharactersException` | Text other than a comment after the closing quote |
| `MissingRequiredKeyException` | Required key missing in file, `$_ENV` and process environment |
| `InvalidValueException` | `format()`: value is not a string, or contains a line break or NUL byte |

Messages for errors in the file name the file, the line and, for quote errors, the key — never a value, so a typo in a secret does not end up in logs. Arguments holding raw lines or values are hidden from stack traces as well. One limit: the backtrace PHP itself prints for a fatal error (`fatal_error_backtraces`), such as memory running out on a huge file, can show the start of a line; `zend.exception_ignore_args=1` (php.ini-production) keeps arguments out of the traces PHP generates for exceptions and fatal errors.

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

`$_ENV` is process-local and safe. Use `$_ENV['KEY']` instead of `getenv('KEY')` in your application.

`load()` reads the process environment (`getenv($key, true)`) to let its variables win over the file. It never changes it.

```php
// Safe
$host = $_ENV['DB_HOST'];

// Not recommended (not set by this loader)
$host = getenv('DB_HOST');
```

## When to Use

- Development environments
- Shared hosting where system ENV is not available
- Simple projects without framework
- **Modern async PHP** (Swoole, RoadRunner, FrankenPHP)

## When NOT to Use

- Production with proper system ENV configuration
- When you need variable expansion (`${OTHER_VAR}`)
- When you need multiline values
- When you must support legacy code using `getenv()`

## Requirements

- PHP ^8.5

## Acknowledgments

Parts of this project (refactoring, documentation, code review) were developed with AI assistance (Claude).

## License

MIT
