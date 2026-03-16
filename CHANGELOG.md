# Changelog

## [Unreleased]

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
- GitHub Actions CI for PHP 8.1, 8.2, 8.3, 8.4.

[Unreleased]: https://github.com/SoDaHo/env-loader/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/SoDaHo/env-loader/releases/tag/v1.0.0
