# Security Policy

## Supported Versions

Security fixes are provided for the latest released version of this package and
the current `main` branch.

## Secure Deployment

Documentator docs routes are disabled by default. Set
`DOCUMENTATOR_ENABLED=true` only in environments where the docs should be
reachable, and protect private APIs with route middleware and/or
`Documentator::auth()`.

The built-in explorer stores try-it auth tokens in memory by default. If you
choose `DOCUMENTATOR_AUTH_STORAGE=session` or `local`, treat the docs origin as
token-bearing and apply the same XSS controls you use for the rest of the app.

When using the optional Scalar UI driver, prefer a self-hosted asset URL so you
can apply your own CSP and Subresource Integrity policy.

## Reporting a Vulnerability

Please do not open a public issue for suspected security vulnerabilities.

Use GitHub private vulnerability reporting for this repository if it is enabled.
If private reporting is not available, contact the maintainer privately through
GitHub and include:

- A short description of the issue
- Steps to reproduce or a minimal proof of concept
- The affected version, commit, or dependency version
- Any known workarounds or mitigations

You should receive an initial response within 7 days. Confirmed vulnerabilities
will be fixed privately first, then disclosed with release notes after a patched
version is available.
