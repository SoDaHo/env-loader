# Contributing

## Requirements

- PHP 8.5+
- Composer

## Setup

```bash
git clone https://github.com/sodaho/env-loader.git
cd env-loader
composer install
```

`composer.lock` is committed, so `composer install` gets the versions of the development tools CI uses. After `composer update`, commit the lock file together with the change it belongs to.

## Development Commands

```bash
# Run the code checks: code style, static analysis, tests
composer ci

# Individual commands
composer test        # PHPUnit tests
composer analyse     # PHPStan (level max)
composer cs          # Check code style
composer cs:fix      # Fix code style automatically
```

## Code Style

This project follows **PSR-12** with additional strict rules. Configuration is in `.php-cs-fixer.dist.php`.

Before committing, run:

```bash
composer cs:fix
```

Key rules:
- `declare(strict_types=1)` required
- Single quotes for strings
- Trailing commas in multiline arrays
- Ordered imports (alphabetically)
- No unused imports

## Static Analysis

PHPStan runs at **level max** on `src` and `tests`. All code must pass without errors:

```bash
composer analyse
```

## Tests

Tests use PHPUnit 11. Run with:

```bash
composer test
```

Coverage report (requires Xdebug or PCOV):

```bash
composer test:coverage
```

## Pull Request Process

1. Fork and create a feature branch
2. Make changes
3. Run `composer ci` - all checks must pass
4. Submit PR against `main`

CI also checks `composer.json` and `composer.lock` (`composer validate --strict`), runs the tests, static analysis and code style checks on PHP 8.5, and looks up the locked development tools in the security advisories (`composer audit`; it only warns where Packagist cannot be reached). In CI the FastCGI and CGI tests are required: php-cgi and phpdbg are installed, and a skipped test fails the run. A job that runs longer than ten minutes fails. The tests also run on the pre-release of PHP 8.6; a failure there does not fail the run.
