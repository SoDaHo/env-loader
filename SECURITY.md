# Security Policy

## Supported versions

Only the latest release gets fixes.

| Version | Supported |
|---------|-----------|
| 2.1.x   | yes       |
| < 2.1   | no        |

## Reporting a vulnerability

Report vulnerabilities privately through GitHub's private vulnerability reporting:
https://github.com/SoDaHo/env-loader/security/advisories/new

Please do not open a public issue for a security problem. Include the affected version, the PHP version and
SAPI (CLI, PHP-FPM, CGI, …), `variables_order` and, if you have one, a minimal reproduction.

## Scope

The library parses a file into strings and writes them to `$_ENV`: no variable expansion, nothing in the file is run, no value
appears in an exception message. These are outside its reach by design, and documented in the README:

- **The path is trusted input.** A URL is opened with its stream wrapper, as in 2.0; `localOnly: true` reads a local
  file only. A report that needs a path built from a request is a usage error in the application.
- **`variables_order` with `E`.** Under PHP-FPM and CGI PHP itself fills `$_ENV` with the request then, and `$_ENV`
  wins over the file. The README asks for `variables_order = "GPCS"`; `rejectRequestVariables: true` is a guard on
  top, for the names a request can bring.
- **`required` checks presence**, a string value and, with `requireNonEmpty: true`, that the value is not empty — not
  whether a secret is long or strong enough.
- **The content of `format()` is for `parse()`**, not for a shell that would expand `$`, `${…}` and backticks.
- **Fatal errors.** The backtrace PHP prints for a fatal error can show the start of a line or of the file; no file
  larger than 1 MiB is read, so that takes a `memory_limit` of a few MiB. `zend.exception_ignore_args=1` keeps the
  arguments out.
- **Long-running workers** share `$_ENV` between requests: load the file once when the worker starts.
