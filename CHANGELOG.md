# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.1.0] - 2026-10-07

### Changed

- Selective response signing. Anis now signs only the answers that move money, deliver card codes, or establish a
  key: orders (create and read), card and invoice reveals, the four enrollment routes, and the signature self-check.
  On those routes every answer, success and refusal, is still verified, and an answer without a signature is still
  refused (`signature_missing`).
- The information reads — profile, wallets, the four catalogue reads, and owned-card list and detail — are no longer
  signed by Anis. The SDK returns their answers, and maps their refusals to typed errors, without verifying a
  signature, and ignores a signature header if one is present. They no longer fetch Anis's signing keys, so they keep
  working while the keys cannot be fetched. HTTPS protects them.
- The choice is explicit per route (`PartnerRoute::$signsResponse`, `PartnerRoutes::signsResponse()`); it never depends
  on whether an answer carries a signature, and a route outside the catalogue is treated as signed.
- Every request is still signed exactly as before.

## [1.0.0] - 2026-10-06

### Added

- Signed P-256 partner requests and verified Anis responses.
- Typed operations for profile, wallets, catalogue, orders, owned cards, diagnostics, and credential enrolment.
- Durable order recovery outcomes and explicit resume guidance.
- Structured PSR-3 logs and OpenTelemetry traces and metrics.
- PHP 8.2 support with PSR HTTP interfaces.

### Changed

- Partners can use an installed PSR-18 client and PSR-17 factories discovered by the package.
