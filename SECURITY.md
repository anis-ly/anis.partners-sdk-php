# Security

## Reporting a vulnerability

Please do not open a public issue for a security problem. Email **support@anis.ly** with a description, the package
version, and steps to reproduce it.

## Supported versions

Security fixes are made to the latest released version.

## Private key handling

The client signs through `P256Signer`; it does not need to export a private key. Keep private keys in protected
custody and never include them in settings, environment variables, logs, or crash reports. See
[Security and key custody](docs/security.md).
