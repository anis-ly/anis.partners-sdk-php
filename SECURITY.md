# Security

## Reporting a vulnerability

Please do not open a public issue for a security problem. Email **support@anis.ly** with a description, the package
version, and steps to reproduce it. Anis aims to acknowledge reports within five working days.

## Supported versions

Security fixes target 2.0.1. 1.x is deprecated — upgrade.

Signed answers require the `anis-response-v2` profile. See the verification failure reasons in [Security and key custody](docs/security.md).

## Private key handling

The client signs through `P256Signer`; it does not need to export a private key. Keep private keys in protected
custody and never include them in settings, environment variables, logs, or crash reports. See
[Security and key custody](docs/security.md).
