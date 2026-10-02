# env-loader

Lightweight .env file loader for PHP. Zero dependencies.

## Why This Library?

There are established .env loaders for PHP, most notably [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv). This library exists because we needed something simpler:

- **Zero dependencies** — Nothing to install besides this package.
- **Thread-safe by design** — Only writes to `$_ENV`. No `putenv()`/`getenv()`, which are not thread-safe in async runtimes (Swoole, RoadRunner, FrankenPHP).
- **No magic** — No variable expansion (`${VAR}`), no multiline values, no interpreted escape sequences (`\n`, `\t`). What you write is what you get.
- **Minimal footprint** — Easy to audit, easy to understand.

If you need variable expansion, multiline values, or `getenv()` support, use phpdotenv instead.

## Installation

```bash
composer require sodaho/env-loader
```

## Usage

### Basic Usage

```php
use Sodaho\EnvLoader\EnvLoader;

// Loads .env into $_ENV (does not overwrite existing, no required keys)
EnvLoader::load(__DIR__ . '/.env');

echo $_ENV['DB_HOST'];
```

### Options

```php
// Overwrite existing $_ENV variables
EnvLoader::load('.env', overwrite: true);

// Require specific keys (throws exception if missing)
EnvLoader::load('.env', required: ['DB_HOST', 'DB_NAME']);

// Required keys as comma-separated string
EnvLoader::load('.env', required: 'DB_HOST,DB_NAME');

// Combine options
EnvLoader::load('.env', overwrite: true, required: ['DB_HOST']);
```

A required key may come from the file or from an existing `$_ENV` entry; an empty value counts. If a required key is missing or the file cannot be parsed, `$_ENV` is left unchanged.

`overwrite: false` and `required` do not see the process environment, only `$_ENV`. Whether real environment variables appear there depends on `variables_order` in php.ini: with `GPCS` (php.ini-production and php.ini-development) `$_ENV` starts empty, so a value from the file is used even if the process environment defines the key. To let the process environment win, copy it first:

```php
$_ENV += getenv();
EnvLoader::load('.env');
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

- **Inline comments:** in an unquoted value, a `#` preceded by a space starts a comment (`KEY= # note` is empty). A `#` after a tab or without a space is part of the value. Quote values that contain ` #` — `PASSWORD=abc #123` is read as `abc`.
- **After a closing quote** only a comment may follow; it needs no space (`KEY="value"#note`).
- **Double quotes:** only `\"` and `\\` are unescaped. Everything else stays literal, including `\n` and `\$`.
- **Single quotes:** literal, a single-quoted value cannot contain `'`.
- **Ignored lines:** empty lines, comment lines and lines without `=`.
- **Duplicate keys:** the last one wins.
- **Files:** LF or CRLF line endings, a UTF-8 BOM is skipped.

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
| `FileNotReadableException` | File exists but cannot be read |
| `InvalidKeyException` | Key has invalid format (e.g. `123KEY`, `MY-KEY`) |
| `UnterminatedQuoteException` | Quoted value missing closing quote, or text other than a comment after it |
| `MissingRequiredKeyException` | Required key missing in file and `$_ENV` |
| `InvalidValueException` | `format()`: value is not a string, or contains a line break or NUL byte |

Messages for errors in the file name the file, the line and, for quote errors, the key — never a value, so a typo in a secret does not end up in logs. Arguments holding raw lines or values are hidden from stack traces as well.

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

`putenv()` and `getenv()` are **not thread-safe**. In modern PHP runtimes like:

- Swoole
- RoadRunner
- FrankenPHP
- ReactPHP

...concurrent requests can overwrite each other's environment variables, causing hard-to-debug race conditions.

`$_ENV` is process-local and safe. Use `$_ENV['KEY']` instead of `getenv('KEY')` in your application.

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

- PHP ^8.2

## Acknowledgments

Parts of this project (refactoring, documentation, code review) were developed with AI assistance (Claude).

## License

MIT
