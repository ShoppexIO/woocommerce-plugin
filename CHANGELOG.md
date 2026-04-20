# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2026-04-20

### Fixed
- Webhook handler now protects every WooCommerce paid status (including `processing`) from late `order:cancelled` events. Previously a delayed cancellation webhook could flip a paid order back to cancelled. Caught by Docker-based E2E test.

### Added
- `shoppex_pay_api_base` WP filter for routing API calls to a non-production backend during local development or E2E tests.
- Docker Compose + Playwright E2E test suite covering admin settings, full browser checkout, Blocks checkout rendering, and refund flow.

## [1.0.0] - 2026-04-20

### Added
- Initial release.
- WooCommerce payment gateway registering under the ID `shoppex`.
- Hosted checkout session creation via `POST /dev/v1/checkout/sessions`.
- Signed webhook handler at `?wc-api=shoppex_webhook` with HMAC-SHA512 verification.
- Status mapping for `order:created`, `order:paid`, `order:partial`, `order:cancelled`, `order:disputed`, `order:updated`.
- WooCommerce Blocks checkout support.
- HPOS (High-Performance Order Storage) compatibility declaration.
- Refund support for Stripe and PayPal orders via `POST /dev/v1/orders/{id}/refund` with graceful fallback for unsupported gateways.
