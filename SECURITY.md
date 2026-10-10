# Security Policy

## Supported versions

Only the latest release gets fixes.

| Version | Supported |
|---------|-----------|
| 2.1.x   | yes       |
| < 2.1   | no        |

## Reporting a vulnerability

Report privately through GitHub's private vulnerability reporting:
https://github.com/SoDaHo/env-loader/security/advisories/new

Do not open a public issue. Include the affected version, the PHP version and SAPI (CLI, PHP-FPM, CGI),
`variables_order` and, if possible, a minimal reproduction.

## Scope

Outside the reach of the library (README, section Security):

- The path is trusted input: without `localOnly: true`, a URL is opened with its stream wrapper.
- With an `E` in `variables_order`, PHP-FPM and CGI fill `$_ENV` with the request, and `$_ENV` wins over the file.
  `rejectRequestVariables: true` guards the listed names only.
- `required` checks presence, a string value and, with `requireNonEmpty`, emptiness; not the strength of a secret.
- `format()` output is not escaped for a shell.
- The backtrace PHP prints for a fatal error can show the start of a line of the file.
- Long-running workers share `$_ENV` between requests. A replaced plain file wrapper that suspends fibers is not
  supported.
