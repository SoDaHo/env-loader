# Contributing

## Requirements

- PHP 8.1+
- Composer

## Setup

```bash
git clone https://github.com/sodaho/env-loader.git
cd env-loader
composer install
```

## Development Commands

```bash
# Run all checks (CI)
composer ci

# Individual commands
composer test        # PHPUnit tests
composer analyse     # PHPStan (Level 9)
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

PHPStan runs at **Level 9** (maximum strictness). All code must pass without errors:

```bash
composer analyse
```

## Tests

Tests use PHPUnit 10. Run with:

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
4. Submit PR against `main` branch

CI will automatically run tests, static analysis, and code style checks on PHP 8.1, 8.2, 8.3, and 8.4.
