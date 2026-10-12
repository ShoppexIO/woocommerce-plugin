# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - Unreleased

### Added
- *Checkout display* setting: *Redirect* (default) or *Overlay window*. The overlay opens the Shoppex invoice with `Shoppex.open({ invoiceId })` on the order payment page (classic and Blocks checkout) and always links the full payment page. When the embed script does not load, or has no invoice mode yet (`Shoppex.supports.invoiceId`), the buyer is sent on to the full payment page with a neutral "Redirecting you to the secure payment page…" message instead of an error.
- PHPUnit suite with WordPress/WooCommerce doubles (`composer test`), run in CI.

### Security
- Webhooks are verified with `X-Shoppex-Signature-V2` (HMAC-SHA256 over delivery id, timestamp and body, 5-minute window) instead of the legacy SHA-512 body signature, which a captured delivery could replay forever.
- The event name comes from the signed body. The unsigned `X-Shoppex-Event` header could turn a captured `order:created` delivery into `order:paid`.

### Fixed
- `order:paid` completes the order only for a `COMPLETED` invoice that paid at least the order total in the order currency; otherwise the order goes on hold with a note. A repeated delivery completes it once.
- Orders are found through the checkout session metadata in the shape Shoppex sends (`custom_fields.customFields`); the order key must match.
- `order:disputed` on a paid order puts it on hold (the paid-order guard dropped it); unpaid, refunded or cancelled orders keep their status.
- A repeated webhook is applied once, so a retried dispute no longer puts an order the merchant released back on hold. Deliveries are deduplicated by the signed `X-Shoppex-Delivery` id, which a Shoppex retry keeps even when it rebuilds the payload with a new `created_at`; a dashboard retry under a new delivery id is still caught by its frozen payload.
- Events of an older Shoppex invoice of the same order no longer cancel or hold the order; a payment through it still completes the order and retargets refunds.
- Checkout session idempotency: a retry of a create whose answer was lost replays that session; any later attempt for the order (changed cart on a reused pending order, or a return after the session expired) gets a new session instead of a 422 or an expired invoice.
- Refund amounts are sent with two decimals (stores with three price decimals got a validation error).
- A late payment on a refunded order is reported for a manual refund instead of retargeting refunds.
- An order put on hold for a short or foreign-currency payment remembers the invoice that paid, and refunds go to that invoice instead of the order's newest checkout session.
- Refund idempotency keys come from the WooCommerce refund record, so a refund recorded again after its record was deleted is sent to Shoppex instead of replaying the first one. A retry after a lost answer reuses the lost key. When Shoppex answers that retry with the stored response of the earlier refund (`idempotency-status: cached`), the plugin records no new refund and tells the merchant that no new refund was sent, so a lost refund the merchant already recorded by hand is not counted twice; the next attempt sends a new refund.
- Dashboard links point at current routes.

### Removed
- The *White-label checkout* setting and the `white_label` field. No behaviour change: the checkout session endpoint has no such field and never returned a branded URL.
- `Shoppex_Api_Client::get_payment()` (unused) and the standalone signature smoke test. `npm run test:smoke` now runs the PHPUnit webhook suites on the plugin classes.

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
