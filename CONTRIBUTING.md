# Contributing

## Setup

Requires PHP 8.5+ and Composer.

```bash
git clone https://github.com/sodaho/env-loader.git
cd env-loader
composer install
```

## Commands

```bash
composer ci              # cs, analyse, test
composer test            # PHPUnit 11
composer analyse         # PHPStan, level max, src and tests
composer cs              # code style, dry run
composer cs:fix          # fix code style
composer test:coverage   # coverage report, needs Xdebug or PCOV
```

## Rules

- Code style: PSR-12 plus the rules in `.php-cs-fixer.dist.php` (strict types, single quotes, trailing commas in
  multiline arrays, alphabetic imports, no unused imports).
- `composer ci` passes before a pull request against `main`.
- `composer.lock` is committed; commit it together with the change that updates it.
- The FastCGI and CGI tests need `php-cgi` and `phpdbg` next to the PHP binary; CI fails on a skipped test.

## CI

- PHP 8.5: `composer validate --strict`, code style, PHPStan, tests, `composer audit` (warns if Packagist is
  unreachable).
- PHP 8.6 pre-release: tests; a failure there does not fail the run.
- A job that runs longer than ten minutes fails.
